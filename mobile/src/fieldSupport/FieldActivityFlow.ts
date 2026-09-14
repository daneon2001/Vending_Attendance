import type { GeofenceSnapshot, LocationEvidence } from '@/domain/types'
import type { FieldDeviceKey } from '@/fieldIdentity/FieldDeviceKey'
import { FieldMobileError } from '@/fieldIdentity/FieldMobileTransport'
import type { LocationProvider } from '@/services/LocationService'
import { GeofenceValidationService } from '@/services/GeofenceValidationService'
import type { FieldOfflineSupport } from './FieldOfflineSupport'

export interface Activity {
  uuid: string; title: string; status: string; type_label: string; employee: string; machine: string
  description?: string; geofence?: GeofenceSnapshot | null; geofence_result?: string | null
  started_at?: string | null; completed_at?: string | null
  contribution_policy?: { available: boolean; max_note_length: number; max_notes: number; max_count: number; max_size_bytes: number; allowed_mimes: string[] }
  notes?: { uuid: string; body: string; author: string; captured_at: string; received_at: string }[]
  evidence?: { uuid: string; author: string; captured_at: string; received_at: string; upload_sha256: string; sha256: string }[]
}
export interface Operation {
  action: 'list' | 'detail' | 'start' | 'complete' | 'note' | 'evidence' | 'notification_read'; activity_uuid?: string
  notification_uuid?: string
  operation_uuid?: string; location?: LocationEvidence; page?: number
  body?: string; captured_at?: string
  evidence?: { type: 'PHOTO' | 'DOCUMENT'; mime: string; extension: string; size_bytes: number; upload_sha256: string }
}
export interface FieldActivityApi {
  origin: string
  post<T>(action: 'activities/capability' | 'activities/challenge' | 'activities/execute', body: Record<string, unknown>): Promise<T>
}
export interface PendingOperation { origin: string; deviceUuid: string; operation: Operation }
export interface PendingStore { pending(): Promise<PendingOperation | null>; savePending(value: PendingOperation | null): Promise<void> }
export interface ActivityNotification { id: string; activity_uuid: string; folio: string; kind: string; created_at: string; read_at: string | null }
export interface ActivityNotificationFeed { notifications: ActivityNotification[]; unread_count: number }
export const activityNotificationLabel = (kind: string) => ({ 'support_activity.assigned': 'Actividad asignada', 'support_activity.completed': 'Actividad completada', 'support_activity.cancelled': 'Actividad cancelada' }[kind] ?? 'Novedad de la actividad')
export const emptyActivitiesMessage = (offline: boolean) => offline
  ? 'No hay actividades disponibles en este dispositivo. Conéctate a la red para actualizar tus actividades.'
  : 'No tienes actividades asignadas.'
export const activityStatus = (value: string) => ({ ASSIGNED: 'Asignada', IN_PROGRESS: 'En progreso', COMPLETED: 'Completada', CANCELLED: 'Cancelada' }[value] ?? 'Estado no disponible')
export const zoneLabel = (value: string | null) => ({ INSIDE: 'Dentro de la zona permitida', OUTSIDE: 'Fuera de la zona permitida', UNCERTAIN: 'No fue posible confirmar tu ubicación' }[value ?? ''] ?? 'Ubicación pendiente de comprobar')

/** Server-confirmed online state. One encrypted retry intent, not an offline queue. */
export class FieldActivityFlow {
  busy = false
  error = ''
  activity: Activity | null = null
  rows: Activity[] = []
  page = 1
  hasMore = false
  deviceUuid = ''
  edgeResult: string | null = null
  serverResult: string | null = null
  pending: PendingOperation | null = null
  mismatch = false
  notifications: ActivityNotificationFeed | null = null
  constructor(private readonly api: FieldActivityApi, private readonly keys: FieldDeviceKey,
    private readonly gps: LocationProvider, private readonly store: PendingStore,
    private readonly geofences = new GeofenceValidationService(), readonly offlineSupport?: FieldOfflineSupport) {}

  private async signed<T>(operation: Operation): Promise<T> {
    const challenge = await this.api.post<{ challenge_uuid: string; message: string }>('activities/challenge', { operation })
    const { signature } = await this.keys.sign({ deviceUuid: this.deviceUuid, message: challenge.message })
    return this.api.post<T>('activities/execute', { operation, challenge_uuid: challenge.challenge_uuid, signature })
  }

  private async run(action: () => Promise<void>): Promise<void> {
    if (this.busy) return
    this.busy = true; this.error = ''
    try { await action() }
    catch (error) { this.error = error instanceof FieldMobileError ? error.message : 'No fue posible completar la operación. Consulta el estado antes de reintentar.' }
    finally { this.busy = false }
  }

  async open(uuid?: string, page = 1): Promise<void> {
    await this.run(async () => {
      this.activity = null; this.rows = []; this.edgeResult = null; this.serverResult = null; this.notifications = null
      let prepared: Awaited<ReturnType<FieldOfflineSupport['prepare']>> | undefined
      try { prepared = await this.offlineSupport?.prepare() }
      catch (error) {
        // No downloaded context: explain unavailability without inventing an authorized empty list.
        if (this.offlineSupport && error instanceof FieldMobileError && (error.status === 0 || error.status >= 500)) {
          this.error = emptyActivitiesMessage(true)
          return
        }
        throw error
      }
      const capability = prepared ? { available: true, device_uuid: prepared.deviceUuid }
        : await this.api.post<{ available: boolean; device_uuid: string }>('activities/capability', {})
      if (!capability.available) throw new FieldMobileError(403)
      this.deviceUuid = capability.device_uuid
      const pending = await this.store.pending()
      this.pending = pending?.origin === this.api.origin && pending.deviceUuid === this.deviceUuid ? pending : null
      if (prepared?.offline) {
        const rows = await this.offlineSupport!.cached(uuid)
        if (uuid) this.activity = rows[0] ?? null
        else { this.rows = rows; this.page = 1; this.hasMore = false }
        return
      }
      if (uuid) {
        const response = await this.signed<{ activity: Activity }>({ action: 'detail', activity_uuid: uuid })
        this.activity = response.activity
        await this.offlineSupport?.cache([response.activity])
      } else {
        const result = await this.signed<{ data: Activity[]; page: number; has_more: boolean; notifications?: ActivityNotificationFeed }>({ action: 'list', page })
        this.rows = result.data; this.page = result.page; this.hasMore = result.has_more
        this.notifications = result.notifications ?? null
        await this.offlineSupport?.cache(result.data)
      }
    })
  }

  async markNotificationRead(notification: ActivityNotification): Promise<void> {
    if (notification.read_at) return
    await this.run(async () => {
      try {
        // Same signed human identity transport. No optimistic read marker or offline queue.
        this.notifications = await this.signed<ActivityNotificationFeed>({ action: 'notification_read', notification_uuid: notification.id })
      } catch (error) { this.notifications = null; throw error }
    })
  }

  async start(): Promise<void> {
    await this.run(async () => {
      if (!this.activity || this.activity.status !== 'ASSIGNED' || this.pending || this.mismatch) return
      // Refresh authoritative configuration before requesting a NEW native GPS fix.
      this.activity = (await this.signed<{ activity: Activity }>({ action: 'detail', activity_uuid: this.activity.uuid })).activity
      if (!this.activity.geofence) throw new FieldMobileError(422, 'FIELD_NOT_EVALUATED')
      let location: LocationEvidence
      try { location = await this.gps.capture() }
      catch { throw new FieldMobileError(422, 'FIELD_UNCERTAIN') }
      this.edgeResult = this.geofences.validate(this.activity.geofence, location).result
      if (this.edgeResult !== 'INSIDE') throw new FieldMobileError(422, 'FIELD_' + this.edgeResult)
      await this.submit({ action: 'start', activity_uuid: this.activity.uuid, operation_uuid: crypto.randomUUID(), location })
    })
  }

  async complete(): Promise<void> {
    await this.run(async () => {
      if (!this.activity || this.activity.status !== 'IN_PROGRESS' || this.pending || this.mismatch) return
      if (this.offlineSupport) { await this.offlineSupport.complete(this.activity); return }
      // START_ONLY_V1: no new GPS evidence is claimed at completion.
      await this.submit({ action: 'complete', activity_uuid: this.activity.uuid, operation_uuid: crypto.randomUUID() })
    })
  }

  async retry(): Promise<void> {
    await this.run(async () => {
      if (this.pending && this.pending.operation.activity_uuid === this.activity?.uuid && !this.mismatch) await this.submit(this.pending.operation)
    })
  }

  private async submit(operation: Operation): Promise<void> {
    this.pending = { origin: this.api.origin, deviceUuid: this.deviceUuid, operation }
    await this.store.savePending(this.pending)
    let result: { activity: Activity; confirmed: boolean; geofence_result: string }
    try { result = await this.signed<typeof result>(operation) }
    catch (error) {
      // Definite domain rejection: next attempt needs a fresh operation/location.
      // Network/5xx/409 remain unresolved until an explicit status check/retry.
      if (error instanceof FieldMobileError && error.status === 422) {
        await this.store.savePending(null); this.pending = null
      }
      throw error
    }
    if (!result.confirmed) throw new FieldMobileError(503)
    this.activity = result.activity
    await this.offlineSupport?.cache([result.activity])
    this.serverResult = result.geofence_result
    if (operation.action === 'start' && result.geofence_result !== 'INSIDE') {
      this.mismatch = true
      throw new FieldMobileError(503)
    }
    await this.store.savePending(null); this.pending = null
  }
}
