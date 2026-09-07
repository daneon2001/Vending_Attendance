import { App, type AppPlugin } from '@capacitor/app'
import type { PluginListenerHandle } from '@capacitor/core'
import type { CredentialStore } from '@/security/DeviceCredentialStore'
import type { ConnectivityService } from '@/services/ConnectivityService'
import { EdgeError } from '@/domain/errors'
import type { SupportApi } from './SupportApiClient'
import type { SupportStore } from './SupportStore'
import type { EvidenceFiles } from './PrivateEvidenceFiles'
import { SupportError, type SupportOperation } from './types'

export interface SupportSyncState {
  phase: 'IDLE' | 'SYNCING' | 'OFFLINE' | 'ERROR'
  errorCode: string | null
  completedOperations: number
}

export class SupportSyncService {
  private running: Promise<void> | null = null
  private started = false
  private foreground = true
  private unsubscribe: (() => void) | null = null
  private appHandle: PluginListenerHandle | null = null
  private timer: ReturnType<typeof setTimeout> | null = null
  private listeners = new Set<(state: SupportSyncState) => void>()
  private state: SupportSyncState = { phase: 'IDLE', errorCode: null, completedOperations: 0 }

  constructor(
    private readonly api: SupportApi,
    private readonly store: SupportStore,
    private readonly files: EvidenceFiles,
    private readonly credentials: CredentialStore,
    private readonly connectivity: Pick<ConnectivityService, 'current' | 'subscribe'>,
    private readonly app: AppPlugin = App,
    private readonly maxOperationsPerRun = 20,
    private readonly maxFeedPagesPerRun = 3,
  ) {}

  subscribe(listener: (state: SupportSyncState) => void): () => void {
    this.listeners.add(listener); listener(this.state)
    return () => this.listeners.delete(listener)
  }
  private publish(patch: Partial<SupportSyncState>): void {
    this.state = { ...this.state, ...patch }
    for (const listener of this.listeners) listener(this.state)
  }
  async start(): Promise<void> {
    if (this.started) return
    try {
      await this.store.initialize()
      this.started = true
      this.unsubscribe = this.connectivity.subscribe(state => {
        if (state === 'ONLINE' && this.foreground) void this.syncNow()
        if (state === 'OFFLINE') this.publish({ phase: 'OFFLINE' })
      })
      this.appHandle = await this.app.addListener('appStateChange', ({ isActive }) => {
        this.foreground = isActive
        if (isActive) void this.syncNow()
        else if (this.timer) { clearTimeout(this.timer); this.timer = null }
      })
      await this.syncNow()
    } catch {
      // A support startup failure must never prevent the independent attendance startup.
      this.publish({ phase: 'ERROR', errorCode: 'SUPPORT_STARTUP_FAILED' })
      await this.stop()
    }
  }
  async stop(): Promise<void> {
    this.started = false
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
    this.unsubscribe?.(); this.unsubscribe = null
    await this.appHandle?.remove(); this.appHandle = null
    // Connectivity and the attendance database are owned by the existing application.
  }
  syncNow(): Promise<void> {
    if (this.running) return this.running
    this.running = this.perform().finally(() => { this.running = null; this.schedule() })
    return this.running
  }
  private schedule(): void {
    if (!this.started || !this.foreground) return
    if (this.timer) clearTimeout(this.timer)
    // Bounded foreground polling with jitter; no manifests, heartbeat changes or background worker.
    this.timer = setTimeout(() => void this.syncNow(), 60_000 + Math.round(Math.random() * 30_000))
  }
  private async perform(): Promise<void> {
    if (this.connectivity.current() !== 'ONLINE' || !this.foreground) {
      this.publish({ phase: 'OFFLINE' }); return
    }
    this.publish({ phase: 'SYNCING', errorCode: null, completedOperations: 0 })
    try {
      const identity = await this.credentials.get()
      if (!identity) throw new SupportError('NOT_PROVISIONED', 'El equipo no está configurado.')
      const deviceUuid = identity.deviceUuid
      const context = await this.api.context(deviceUuid)
      if (context.device.uuid !== deviceUuid) throw new SupportError('IDENTITY_CHANGED', 'La configuración corresponde a otro equipo.')
      await this.store.saveContext(context)
      await this.purge(deviceUuid)
      for (let count = 0; count < this.maxOperationsPerRun && this.foreground && this.connectivity.current() === 'ONLINE'; count++) {
        const current = await this.credentials.get()
        if (current?.deviceUuid !== deviceUuid) throw new SupportError('IDENTITY_CHANGED', 'La identidad del equipo cambió.')
        const item = await this.store.claim(deviceUuid)
        if (!item) break
        try {
          const result = await this.dispatch(item, context.machine.uuid)
          await this.store.acknowledge(item, result)
          this.publish({ completedOperations: this.state.completedOperations + 1 })
        } catch (error) {
          const failure = this.failure(error)
          await this.store.fail(item, failure.code, failure.retryable, failure.retryAfterSeconds,
            ['IDENTITY_CHANGED', 'MACHINE_CHANGED', 'NOT_PROVISIONED'].includes(failure.code) || failure.status === 401)
          if (failure.status === 401 || failure.code === 'IDENTITY_CHANGED') throw failure
        }
      }
      await this.purge(deviceUuid)
      for (let pageNumber = 0; pageNumber < this.maxFeedPagesPerRun && this.foreground && this.connectivity.current() === 'ONLINE'; pageNumber++) {
        const previous = await this.store.getCursor(deviceUuid)
        const page = await this.api.changes(deviceUuid, previous, 50)
        await this.store.applyChanges(deviceUuid, page)
        if (!page.has_more) break
        if (page.next_sequence <= previous) throw new SupportError('INVALID_FEED', 'Las novedades no tienen una posición válida.', true)
      }
      this.publish({ phase: this.connectivity.current() === 'ONLINE' ? 'IDLE' : 'OFFLINE', errorCode: null })
    } catch (error) { this.publish({ phase: 'ERROR', errorCode: this.failure(error).code }) }
  }
  private failure(error: unknown): SupportError {
    if (error instanceof SupportError) return error
    if (error instanceof EdgeError) return new SupportError(error.code, 'No fue posible sincronizar soporte.', error.retryable)
    // Unexpected native/storage errors retain intent and files for retry instead of discarding evidence.
    return new SupportError('SUPPORT_UNAVAILABLE', 'No fue posible sincronizar soporte.', true)
  }
  private async dispatch(item: SupportOperation, currentMachineUuid: string): Promise<Record<string, unknown>> {
    const local = item.ticketLocalUuid ? await this.store.getTicket(item.ticketLocalUuid, item.deviceUuid) : null
    const capturedMachine = local?.machineUuid ?? item.payload.captured_machine_uuid
    if (capturedMachine !== currentMachineUuid) throw new SupportError('MACHINE_CHANGED', 'La máquina asociada cambió. El reporte sigue guardado.')
    if (item.kind === 'CREATE_TICKET') return this.api.createTicket(item.deviceUuid, item.payload)
    if (item.kind === 'VERIFICATION') return this.api.verification(item.deviceUuid, item.payload)
    if (!local?.serverUuid) throw new SupportError('TICKET_NOT_CONFIRMED', 'El ticket aún no tiene confirmación.', true)
    if (item.kind === 'COMMENT') return this.api.comment(item.deviceUuid, local.serverUuid, item.payload)
    if (item.kind === 'RESERVE_EVIDENCE') return this.api.reserveEvidence(item.deviceUuid, local.serverUuid, item.payload)
    const evidence = item.evidenceLocalUuid ? await this.store.getEvidence(item.evidenceLocalUuid, item.deviceUuid) : null
    if (!evidence?.serverUuid || !evidence.mime) throw new SupportError('EVIDENCE_NOT_RESERVED', 'La fotografía aún no tiene una reserva confirmada.', true)
    if (evidence.state === 'CONFIRMED') return { evidence: { uuid: evidence.serverUuid, status: 'CONFIRMED' } }
    return this.api.uploadEvidence(item.deviceUuid, local.serverUuid, evidence.serverUuid, await this.files.read(evidence), evidence.mime)
  }
  private async purge(deviceUuid: string): Promise<void> {
    const sourceCleanupFailed = new Set<string>()
    for (const evidence of await this.store.cameraSources(deviceUuid)) {
      try {
        if (await this.files.cleanupCameraSource(evidence)) await this.store.markSourceCleaned(evidence.localUuid, deviceUuid)
      } catch { sourceCleanupFailed.add(evidence.localUuid) }
    }
    for (const evidence of await this.store.confirmedFiles(deviceUuid)) {
      if (sourceCleanupFailed.has(evidence.localUuid)) continue
      // A file is eligible only after its upload receipt and evidence metadata committed together.
      try { await this.files.purgeConfirmed(evidence); await this.store.markPurged(evidence.localUuid, deviceUuid) }
      catch { /* Retain metadata and retry cleanup next foreground sync. Never undo a server receipt. */ }
    }
  }
}
