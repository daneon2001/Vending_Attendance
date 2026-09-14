import { App, type RestoredListenerEvent } from '@capacitor/app'
import { Camera, type Photo } from '@capacitor/camera'
import type { HumanSessionStore } from '@/fieldIdentity/FieldMobileTransport'
import { FieldMobileError } from '@/fieldIdentity/FieldMobileTransport'
import type { FieldDeviceKey } from '@/fieldIdentity/FieldDeviceKey'
import type { ConnectivityService } from '@/services/ConnectivityService'
import type { EvidenceFiles } from '@/support/PrivateEvidenceFiles'
import { SUPPORT_CAMERA_OPTIONS } from '@/support/SupportCaptureService'
import { SupportError, SupportMutex, type SupportOperation } from '@/support/types'
import type { Activity, FieldActivityApi, Operation } from './FieldActivityFlow'
import { fieldDigest, FieldOfflineStore, type FieldCapture, type FieldScope } from './FieldOfflineStore'

/** Fresh signatures on reconnect; only intent and file metadata survive offline. */
export class FieldOfflineSupport {
  scope: FieldScope | null = null
  offline = false
  message = ''
  private running: Promise<void> | null = null
  private initialized = false
  private foreground = true
  private timer: ReturnType<typeof setTimeout> | null = null
  private unsubscribeNetwork: (() => void) | null = null
  private handles: { remove(): Promise<void> }[] = []
  private readonly captureMutex = new SupportMutex()
  private listeners = new Set<() => void>()
  constructor(readonly store: FieldOfflineStore, private readonly sessions: HumanSessionStore,
    private readonly api: FieldActivityApi, private readonly keys: FieldDeviceKey,
    readonly files: EvidenceFiles, private readonly connectivity: Pick<ConnectivityService, 'current' | 'subscribe'>) {}
  subscribe(listener: () => void): () => void { this.listeners.add(listener); return () => this.listeners.delete(listener) }
  private changed(): void { for (const listener of this.listeners) listener() }
  private async sessionHash(): Promise<string> {
    const session = await this.sessions.session()
    if (!session || session.origin !== this.api.origin || Date.parse(session.expires_at) <= Date.now()) { this.scope = null; throw new FieldMobileError(401) }
    return fieldDigest(session.token)
  }
  private async current(): Promise<FieldScope> {
    const hash = await this.sessionHash()
    if (!this.scope || this.scope.sessionHash !== hash) throw new FieldMobileError(401)
    return this.scope
  }
  async prepare(): Promise<{ deviceUuid: string; offline: boolean }> {
    const hash = await this.sessionHash()
    await this.store.initialize()
    try {
      if (this.connectivity.current() === 'OFFLINE') throw new FieldMobileError(0)
      const capability = await this.api.post<{ available: boolean; device_uuid: string }>('activities/capability', {})
      if (!capability.available) throw new FieldMobileError(403)
      this.scope = await this.store.bind(this.api.origin, capability.device_uuid, hash)
      this.offline = false
    } catch (error) {
      if (!(error instanceof FieldMobileError) || (error.status !== 0 && error.status < 500)) { this.scope = null; throw error }
      this.scope = await this.store.cachedScope(this.api.origin, hash)
      if (!this.scope) throw error
      this.offline = true
    }
    if (!this.initialized) {
      this.initialized = true
      this.unsubscribeNetwork = this.connectivity.subscribe(state => { if (state === 'ONLINE' && this.foreground) void this.sync(); if (state === 'OFFLINE') { this.offline = true; this.changed() } })
      this.handles.push(await App.addListener('appStateChange', ({ isActive }) => { this.foreground = isActive; if (isActive) void this.sync(); else if (this.timer) clearTimeout(this.timer) }))
      this.handles.push(await App.addListener('appRestoredResult', result => { void this.restore(result).catch(() => { this.message = 'La fotografía requiere revisión antes de guardarla.'; this.changed() }) }))
    }
    await this.recover().catch(() => { this.message = 'La fotografía requiere revisión. Puedes descartarla antes de guardar.' })
    this.schedule()
    return { deviceUuid: this.scope.deviceUuid, offline: this.offline }
  }
  async cache(rows: Activity[]): Promise<void> { await this.store.cache(await this.current(), rows) }
  async stop(): Promise<void> {
    this.initialized = false; this.foreground = false
    if (this.timer) clearTimeout(this.timer)
    this.timer = null; this.unsubscribeNetwork?.(); this.unsubscribeNetwork = null
    await Promise.all(this.handles.map(handle => handle.remove())); this.handles = []
    await this.running
  }
  async cached(uuid?: string): Promise<Activity[]> { return this.store.activities(await this.current(), uuid) }
  async pending(uuid?: string): Promise<SupportOperation[]> { return this.store.queued(await this.current(), uuid) }
  async pendingCount(): Promise<number> { return this.store.pendingCount(await this.current()) }
  async captures(uuid?: string): Promise<FieldCapture[]> { return this.store.captures(await this.current(), uuid) }
  async note(activity: Activity, body: string): Promise<void> {
    const scope = await this.current()
    await this.store.queue(scope, { action: 'note', activity_uuid: activity.uuid, operation_uuid: crypto.randomUUID(), body, captured_at: new Date().toISOString() })
    this.message = 'Nota guardada en el dispositivo. Pendiente de sincronizar.'; this.changed(); void this.sync()
  }
  async complete(activity: Activity): Promise<void> {
    const scope = await this.current()
    if ((await this.store.captures(scope, activity.uuid)).some(row => row.preview || row.evidence.state === 'CAPTURING')) throw new SupportError('CAPTURE_PENDING', 'Confirma o descarta la fotografía antes de finalizar.')
    await this.store.queue(scope, { action: 'complete', activity_uuid: activity.uuid, operation_uuid: crypto.randomUUID() })
    this.message = 'Finalización guardada en el dispositivo. Pendiente de confirmar.'; this.changed(); void this.sync()
  }
  capture(activity: Activity): Promise<void> {
    return this.captureMutex.run(async () => {
      const scope = await this.current()
      const cached = (await this.store.activities(scope, activity.uuid))[0]
      const policy = cached?.contribution_policy
      if (cached?.status !== 'IN_PROGRESS' || !policy?.available || (await this.pending(activity.uuid)).some(row => (row.payload.operation as Operation).action === 'complete')) throw new SupportError('ACTIVITY_NOT_STARTED', 'Se requiere una actividad en progreso.')
      const previous = await this.store.captures(scope)
      if (previous.some(row => row.preview || row.evidence.state === 'CAPTURING')) throw new SupportError('CAPTURE_PENDING', 'Confirma o descarta la fotografía pendiente.')
      if ((cached.evidence?.length ?? 0) + previous.filter(row => row.activityUuid === activity.uuid && !row.confirmed).length >= policy.max_count) throw new SupportError('EVIDENCE_LIMIT', 'Se alcanzó el límite de fotografías de la actividad.')
      const uuid = crypto.randomUUID()
      const value: FieldCapture = { scope: scope.key, activityUuid: activity.uuid, confirmed: false, preview: true,
        evidence: { localUuid: uuid, ticketLocalUuid: activity.uuid, deviceUuid: scope.deviceUuid, capturedAt: new Date().toISOString(), sourcePath: null, path: null,
          mime: null, sizeBytes: null, uploadSha256: null, state: 'CAPTURING', serverUuid: null, purgedAt: null } }
      await this.store.saveCapture(value)
      let photo: Photo
      try { photo = await Camera.getPhoto(SUPPORT_CAMERA_OPTIONS) }
      catch { value.evidence.state = 'CANCELLED'; await this.store.saveCapture(value); throw new SupportError('CAMERA_CANCELLED', 'No se agregó una fotografía.') }
      await this.accept(value, photo)
    })
  }
  private async accept(value: FieldCapture, photo: Photo): Promise<void> {
    const scope = await this.current()
    if (value.scope !== scope.key || !photo.path || !['jpeg', 'jpg'].includes(photo.format?.toLowerCase())) throw new SupportError('INVALID_IMAGE', 'No fue posible conservar la fotografía.')
    value.evidence.sourcePath = photo.path
    await this.store.saveCapture(value)
    await this.recover()
  }
  private async recover(): Promise<void> {
    const scope = await this.current()
    for (const value of await this.store.captures(scope)) {
      if (value.evidence.state !== 'CAPTURING') continue
      const activity = (await this.store.activities(scope, value.activityUuid))[0]
      if (!activity?.contribution_policy) continue
      const file = await this.files.recover(value.evidence, activity.contribution_policy.max_size_bytes)
      if (!file) continue
      value.evidence = { ...value.evidence, ...file, state: 'READY' }; value.preview = true
      await this.store.saveCapture(value)
      try {
        if (await this.files.cleanupCameraSource(value.evidence)) { value.evidence.sourcePath = null; await this.store.saveCapture(value) }
      } catch { /* Durable private bytes remain available; retain source for later cleanup. */ }
    }
    this.changed()
  }
  private async restore(result: RestoredListenerEvent): Promise<void> {
    if (result.pluginId !== 'Camera' || result.methodName !== 'getPhoto') return
    await this.captureMutex.run(async () => {
      const pending = (await this.captures()).find(value => value.evidence.state === 'CAPTURING')
      if (!pending) return
      if (!result.success) { pending.evidence.state = 'CANCELLED'; await this.store.saveCapture(pending); return }
      await this.accept(pending, result.data as Photo)
    })
  }
  async confirmPhoto(value: FieldCapture): Promise<void> {
    const scope = await this.current()
    const actual = (await this.store.captures(scope)).find(row => row.evidence.localUuid === value.evidence.localUuid)
    if (!actual || !actual.preview || actual.evidence.state !== 'READY') throw new SupportError('INVALID_IMAGE', 'Revisa la fotografía antes de guardarla.')
    await this.files.read(actual.evidence)
    const evidence = actual.evidence
    if (!await this.store.shared.operation(evidence.localUuid, scope.key)) {
      await this.store.queue(scope, { action: 'evidence', activity_uuid: actual.activityUuid, operation_uuid: evidence.localUuid,
        captured_at: evidence.capturedAt, evidence: { type: 'PHOTO', mime: evidence.mime!, extension: 'jpg', size_bytes: evidence.sizeBytes!, upload_sha256: evidence.uploadSha256! } })
    }
    actual.preview = false; await this.store.saveCapture(actual)
    this.message = 'Fotografía guardada en el dispositivo. Pendiente de sincronizar.'; this.changed(); void this.sync()
  }
  async discardPhoto(value: FieldCapture): Promise<void> {
    const scope = await this.current()
    const actual = (await this.store.captures(scope)).find(row => row.evidence.localUuid === value.evidence.localUuid)
    if (!actual?.preview || await this.store.shared.operation(actual.evidence.localUuid, scope.key)) throw new SupportError('ALREADY_QUEUED', 'La fotografía ya se guardó para sincronizar.')
    // Explicit discard before enqueue: retire draft only. No unconfirmed queued photo is deleted.
    actual.evidence.state = 'CANCELLED'; actual.preview = false; await this.store.saveCapture(actual)
    this.message = 'Fotografía descartada; no se enviará.'; this.changed()
  }
  sync(): Promise<void> {
    if (this.running) return this.running
    this.running = this.perform().finally(() => { this.running = null; this.changed(); this.schedule() })
    return this.running
  }
  private schedule(): void {
    if (this.timer) clearTimeout(this.timer)
    if (this.initialized && this.foreground) this.timer = setTimeout(() => void this.sync(), 60_000 + Math.round(Math.random() * 30_000))
  }
  private async perform(): Promise<void> {
    if (!this.foreground || this.connectivity.current() !== 'ONLINE') { this.offline = true; return }
    try {
      const scope = await this.current()
      if (!(await this.store.queued(scope)).length) return
      for (let count = 0; count < 5 && this.foreground && this.connectivity.current() === 'ONLINE'; count++) {
        if ((await this.current()).key !== scope.key) throw new FieldMobileError(401)
        const item = await this.store.shared.claim(scope.key)
        if (!item) break
        try {
          const operation = item.payload.operation as Operation
          const extra: Record<string, unknown> = {}
          if (operation.action === 'evidence') {
            const capture = (await this.store.captures(scope)).find(value => value.evidence.localUuid === item.uuid)
            if (!capture) throw new SupportError('EVIDENCE_UNAVAILABLE', 'La fotografía sigue pendiente de revisión.')
            const bytes = await this.files.read(capture.evidence)
            let binary = ''
            for (let i = 0; i < bytes.length; i += 8192) binary += String.fromCharCode(...bytes.subarray(i, i + 8192))
            extra.file_base64 = btoa(binary)
          }
          const challenge = await this.api.post<{ challenge_uuid: string; message: string }>('activities/challenge', { operation })
          const { signature } = await this.keys.sign({ deviceUuid: scope.deviceUuid, message: challenge.message })
          const result = await this.api.post<Record<string, unknown>>('activities/execute', { operation, challenge_uuid: challenge.challenge_uuid, signature, ...extra })
          await this.store.acknowledge(scope, item, result)
          this.offline = false; this.message = 'Información sincronizada y confirmada por el servidor.'; this.changed()
        } catch (error) {
          const status = error instanceof FieldMobileError ? error.status : 0
          const blocked = status === 401 || status === 403 || status === 404
          await this.store.shared.fail(item, blocked ? 'FIELD_AUTHORIZATION_REQUIRED' : 'FIELD_SYNC_PENDING', !blocked && (status === 0 || status >= 500 || status === 429), status === 429 ? 90 : null, blocked)
          if (blocked || status === 429 || status === 0 || status >= 500) throw error
        }
      }
    } catch (error) {
      this.offline = error instanceof FieldMobileError && error.status === 0
      this.message = error instanceof FieldMobileError ? error.message : 'La información sigue guardada. Pendiente de sincronizar.'
    }
  }
}
