import { App, type AppPlugin } from '@capacitor/app'
import { Camera, type CameraPlugin } from '@capacitor/camera'
import { Geolocation } from '@capacitor/geolocation'
import type { EdgeStore } from '@/storage/EdgeStore'
import type { CredentialStore } from '@/security/DeviceCredentialStore'
import type { ConnectivityService } from '@/services/ConnectivityService'
import type { LocationProvider } from '@/services/LocationService'
import type { SupportApi } from './SupportApiClient'
import type { SupportStore } from './SupportStore'
import { SupportError, type VerificationInput } from './types'

type Check = VerificationInput['checks'][number]
export class SupportVerificationService {
  constructor(
    private readonly store: SupportStore, private readonly edge: Pick<EdgeStore, 'getSummary'>,
    private readonly credentials: CredentialStore, private readonly api: SupportApi,
    private readonly connectivity: Pick<ConnectivityService, 'current'>, private readonly location: LocationProvider,
    private readonly app: Pick<AppPlugin, 'getInfo'> = App,
    private readonly camera: Pick<CameraPlugin, 'checkPermissions'> = Camera,
    private readonly gps: Pick<typeof Geolocation, 'checkPermissions'> = Geolocation,
  ) {}

  async run(): Promise<{ operationUuid: string; input: VerificationInput }> {
    const identity = await this.credentials.get()
    if (!identity) throw new SupportError('NOT_PROVISIONED', 'El equipo no está configurado.')
    const capturedContext = await this.store.getContext(identity.deviceUuid)
    if (!capturedContext) throw new SupportError('CONTEXT_UNAVAILABLE', 'Conecta el equipo una vez para preparar soporte.')
    const started = new Date().toISOString()
    const checks: Check[] = []
    const add = (code: string, result: Check['result'], details: Check['details'] = {}) => checks.push({ code, result, observed_at: new Date().toISOString(), details })
    const network = this.connectivity.current()
    add('NETWORK', network === 'ONLINE' ? 'PASS' : 'WARNING', { state: network })
    if (network === 'ONLINE') {
      try { await this.api.context(identity.deviceUuid); add('API_REACHABILITY', 'PASS', { available: true }) }
      catch { add('API_REACHABILITY', 'WARNING', { available: false }) }
    } else add('API_REACHABILITY', 'NOT_AVAILABLE', { available: false })
    const summary = await this.edge.getSummary().catch(() => null)
    add('LOCAL_CONFIGURATION', summary?.configurationVersion ? 'PASS' : 'NOT_AVAILABLE', summary?.configurationVersion ? { version: summary.configurationVersion } : {})
    add('LOCAL_EMPLOYEES', summary?.employeeManifestVersion ? 'PASS' : 'NOT_AVAILABLE', summary?.employeeManifestVersion ? { version: summary.employeeManifestVersion } : {})
    add('LOCAL_OUTBOX', summary ? summary.pendingEvents > 0 ? 'WARNING' : 'PASS' : 'NOT_AVAILABLE', summary ? { pending_count: summary.pendingEvents } : {})
    const gps = await this.gps.checkPermissions().catch(() => null)
    const gpsGranted = gps?.location === 'granted'
    add('GPS_PERMISSION', gpsGranted ? 'PASS' : gps?.location === 'denied' ? 'WARNING' : 'NOT_AVAILABLE', { permission: gpsGranted ? 'granted' : gps?.location === 'denied' ? 'denied' : gps ? 'prompt' : 'unknown' })
    if (gpsGranted) {
      const location = await this.location.capture().catch(() => null)
      add('GPS_AVAILABILITY', location ? 'PASS' : 'WARNING', { available: Boolean(location) })
    } else add('GPS_AVAILABILITY', 'NOT_AVAILABLE', { available: false })
    const camera = await this.camera.checkPermissions().catch(() => null)
    add('CAMERA_PERMISSION', camera?.camera === 'granted' ? 'PASS' : camera?.camera === 'denied' ? 'WARNING' : 'NOT_AVAILABLE',
      { permission: camera?.camera === 'granted' ? 'granted' : camera?.camera === 'denied' ? 'denied' : camera ? 'prompt' : 'unknown' })
    // A permission/plugin registration cannot prove that a camera can capture a photo.
    add('CAMERA_AVAILABILITY', 'NOT_AVAILABLE')
    const info = await this.app.getInfo().catch(() => null)
    const build = info ? Number.parseInt(info.build, 10) : 0
    const input: VerificationInput = { captured_machine_uuid: capturedContext.machine.uuid, started_at: started, completed_at: new Date().toISOString(), checks,
      ...(info ? { app_version: info.version } : {}), ...(build > 0 ? { app_build_number: build } : {}) }
    return { operationUuid: await this.store.queueVerification(identity.deviceUuid, input), input }
  }
}
