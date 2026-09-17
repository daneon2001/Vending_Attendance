import { App } from '@capacitor/app'
import { CapacitorHttp } from '@capacitor/core'
import { Network } from '@capacitor/network'
import { Camera } from '@capacitor/camera'
import { Geolocation } from '@capacitor/geolocation'
import { SecureStorage } from '@aparajita/capacitor-secure-storage'
import { runtimeConfig } from '@/config/runtime'
import { credentialStore, edgeStore } from '@/app/services'
import { FieldMobileStore } from '@/fieldIdentity/FieldMobileStore'
import { FieldMobileTransport } from '@/fieldIdentity/FieldMobileTransport'
import type { FieldProfile } from '@/fieldIdentity/FieldMobileFlow'
import { FieldOfflineStore, fieldDigest, type FieldScope } from '@/fieldSupport/FieldOfflineStore'
import { supportStore } from '@/support/services'
import { applicationLabels, fieldStatusLabel, lastConfirmation, pendingLabel, permissionLabel, unavailable } from './presentation'

export interface DiagnosticRow { label: string; value: string }
const safely = async <T>(read: () => Promise<T>): Promise<T | null> => { try { return await read() } catch { return null } }

/** Read/report only: never syncs a queue, captures GPS/photo, creates keys or signs a challenge.
 * Return only explicit display fields, never native exceptions or API objects.
 */
export async function collectDiagnostics(): Promise<DiagnosticRow[]> {
  const [info, network, gps, camera] = await Promise.all([
    safely(() => App.getInfo()), safely(() => Network.getStatus()),
    safely(() => Geolocation.checkPermissions()), safely(() => Camera.checkPermissions()),
  ])
  const app = applicationLabels(info ?? {})
  let server = 'No disponible. Revisa la configuración HTTPS.'
  let field = 'Sin confirmación'
  let pending: number | null = null
  let onlineRetry: number | null = null
  let fieldLast = 'Sin confirmación guardada'
  const store = new FieldMobileStore()
  try {
    const transport = new FieldMobileTransport(import.meta.env.VITE_FIELD_IDENTITY_BASE_URL?.trim() || runtimeConfig.apiBaseUrl, store)
    const health = await safely(() => CapacitorHttp.request({ url: transport.origin + '/up', method: 'GET',
      headers: { Accept: 'application/json' }, connectTimeout: 5000, readTimeout: 5000, disableRedirects: true }))
    server = health?.status === 200 ? 'Accesible por HTTPS' : 'Sin confirmación. Revisa la LAN, el certificado y el servidor.'
    const session = await store.session()
    if (await transport.hasSession()) {
      const draft = await store.draft()
      const identity = await store.identity()
      const profile = await safely(() => transport.post<FieldProfile>('profile', {}))
      const localUuid = identity?.verifiedOrigin === transport.origin && identity.employeeNumber === profile?.employee.number
        ? identity.deviceUuid
        : draft?.origin === transport.origin && draft.employeeNumber === profile?.employee.number ? draft.input.deviceUuid : null
      const device = localUuid ? profile?.devices.find(value => value.device_uuid === localUuid) : null
      field = device ? fieldStatusLabel(device.status) : profile ? 'Sin registro confirmado en este teléfono' : 'Sin confirmación del servidor'
      const offline = new FieldOfflineStore(supportStore)
      const sessionHash = await fieldDigest(session!.token)
      // Consult only an already-open database. initialize/cachedScope can recover
      // interrupted operations; diagnostics must not trigger that recovery.
      const scope = await supportStore.withDatabase(async db => {
        const row = (await db.query('SELECT scope,device_uuid FROM field_support_context WHERE origin=? AND session_hash=?', [transport.origin, sessionHash])).values?.[0]
        return row ? { key: String(row.scope), deviceUuid: String(row.device_uuid), origin: transport.origin, sessionHash } as FieldScope : null
      })
      // Do not claim zero before a personal context has actually been prepared.
      if (scope && scope.deviceUuid === localUuid) {
        pending = await offline.pendingCount(scope)
        const activities = await offline.activities(scope)
        fieldLast = lastConfirmation(activities.flatMap(a => [a.started_at, a.completed_at,
          ...(a.notes ?? []).map(n => n.received_at), ...(a.evidence ?? []).map(e => e.received_at)]))
        const retry = await SecureStorage.get('field_support_online_retry_v1') as { origin?: string; deviceUuid?: string } | null
        onlineRetry = retry ? retry.origin === scope.origin && retry.deviceUuid === scope.deviceUuid ? 1 : null : 0
      }
    } else { field = 'Inicia sesión para consultar tu dispositivo' }
  } catch { /* Only the safe defaults are presented. */ }
  const terminal = await safely(() => credentialStore.get())
  const summary = terminal ? await safely(() => edgeStore.getSummary()) : null
  const ticketPending = terminal ? await safely(async () => {
    return supportStore.withDatabase(async db => Number((await db.query("SELECT COUNT(*) AS total FROM support_operations WHERE device_uuid=? AND status!='ACKNOWLEDGED'", [terminal.deviceUuid])).values?.[0]?.total ?? 0))
  }) : null
  return [
    { label: 'Producto', value: 'Vending Attendance · MEDICAL LIFE ONE' },
    { label: 'Versión instalada', value: app.version }, { label: 'Compilación', value: app.build },
    { label: 'Entorno', value: app.environment },
    { label: 'Red del teléfono', value: network ? network.connected ? 'Disponible (no garantiza acceso al servidor)' : 'Sin conexión' : unavailable },
    { label: 'Servidor de identidad personal', value: server },
    { label: 'Dispositivo personal', value: field },
    { label: 'Trabajo de campo pendiente', value: pendingLabel(pending) },
    { label: 'Operación personal por confirmar', value: pendingLabel(onlineRetry) },
    { label: 'Última confirmación personal guardada', value: fieldLast },
    { label: 'Asistencias pendientes', value: terminal ? pendingLabel(summary?.pendingEvents) : 'No aplica: sin terminal' },
    { label: 'Reportes y verificaciones pendientes', value: terminal ? pendingLabel(ticketPending) : 'No aplica: sin terminal' },
    { label: 'Última sincronización de configuración', value: terminal ? lastConfirmation([summary?.lastSyncAt]) : 'No aplica: sin terminal' },
    { label: 'Permiso de ubicación', value: permissionLabel(gps?.location) },
    { label: 'Permiso de cámara', value: permissionLabel(camera?.camera) },
    { label: 'Notificaciones', value: 'Dentro de la aplicación; sin notificaciones push' },
    { label: 'Verificación telefónica DEMO', value: 'Simulada. No acredita posesión del teléfono.' },
    { label: 'Biometría', value: 'No habilitada' },
  ]
}
