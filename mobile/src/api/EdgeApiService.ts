import type { DeviceApiClient } from './DeviceApiClient'
import type {
  AttendancePayload,
  AttendanceSyncResult,
  BootstrapResponse,
  ConfigurationManifestResponse,
  EmployeeManifestResponse,
  ManifestStatusResponse,
  ManifestType,
} from '@/domain/types'

export interface HeartbeatResponse {
  server_time: string
  clock_drift_seconds: number
  clock_drift_warning: boolean
  clock_drift_threshold_seconds: number
  server_config_version: number
  configuration_changed: boolean
}

export class EdgeApiService {
  constructor(private readonly client: DeviceApiClient) {}

  bootstrap(configVersionApplied: number | null): Promise<BootstrapResponse> {
    const query = configVersionApplied === null ? '' : `?config_version_applied=${configVersionApplied}`
    return this.client.request('GET', `/api/v1/device/bootstrap${query}`)
  }

  manifestStatus(): Promise<ManifestStatusResponse> {
    return this.client.request('GET', '/api/v1/device/manifests/status')
  }

  configurationManifest(): Promise<ConfigurationManifestResponse> {
    return this.client.request('GET', '/api/v1/device/manifests/configuration')
  }

  employeeManifest(knownVersion: number | null): Promise<EmployeeManifestResponse> {
    const query = knownVersion === null ? '' : `?known_version=${knownVersion}`
    return this.client.request('GET', `/api/v1/device/manifests/employees${query}`)
  }

  acknowledgeManifest(input: {
    manifestType: ManifestType
    version: number
    hash: string
    status: 'APPLIED' | 'FAILED'
    errorCode?: string
    errorMessage?: string
  }): Promise<Record<string, unknown>> {
    return this.client.request('POST', '/api/v1/device/manifests/ack', {
      manifest_type: input.manifestType,
      manifest_version: input.version,
      manifest_hash: input.hash,
      applied_at: new Date().toISOString(),
      status: input.status,
      ...(input.errorCode ? { error_code: input.errorCode } : {}),
      ...(input.errorMessage ? { error_message: input.errorMessage.slice(0, 500) } : {}),
    })
  }

  heartbeat(payload: {
    configVersionApplied: number | null
    pendingEvents: number
    appVersion?: string
    platformVersion?: string
  }): Promise<HeartbeatResponse> {
    return this.client.request('POST', '/api/v1/device/heartbeat', {
      app_version: payload.appVersion,
      platform_version: payload.platformVersion,
      config_version_applied: payload.configVersionApplied,
      pending_events_count: payload.pendingEvents,
      device_time: new Date().toISOString(),
    })
  }

  async sendAttendanceBatch(events: AttendancePayload[]): Promise<AttendanceSyncResult[]> {
    const response = await this.client.request<{ results: AttendanceSyncResult[] }>(
      'POST',
      '/api/v1/device/attendance/events/batch',
      { events },
    )
    return response.results
  }
}
