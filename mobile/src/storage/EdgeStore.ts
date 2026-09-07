import type {
  AttendancePayload,
  AttendanceSyncResult,
  BootstrapResponse,
  ConfigurationManifestResponse,
  EmployeeManifestItem,
  EmployeeManifestResponse,
  ManifestType,
  GeofenceSnapshot,
  OutboxStatus,
} from '@/domain/types'

export interface EffectiveEmployee extends EmployeeManifestItem {
  effective: true
}

export interface PendingOutboxEvent {
  eventUuid: string
  payload: AttendancePayload
  retryCount: number
}

export interface AttendanceReceipt {
  status: OutboxStatus
  errorCode: string | null
}

export interface LocalSyncSummary {
  machineCode: string | null
  deviceStatus: string | null
  configurationVersion: number | null
  employeeManifestVersion: number | null
  pendingEvents: number
  lastSyncAt: string | null
}

export interface AttendanceContext {
  timezone: string
  configurationVersion: number
  employeeManifestVersion: number
  geofence: GeofenceSnapshot
}

export interface EdgeStore {
  initialize(): Promise<void>
  saveProvisionedDevice(deviceUuid: string, status: string, credentialVersion: number): Promise<void>
  applyBootstrap(response: BootstrapResponse): Promise<void>
  applyConfigurationManifest(manifest: ConfigurationManifestResponse): Promise<void>
  applyEmployeeManifest(manifest: EmployeeManifestResponse): Promise<void>
  markManifestAcknowledged(type: ManifestType, version: number, hash: string): Promise<void>
  markManifestAckFailed(type: ManifestType, message: string): Promise<void>
  getAppliedManifestVersion(type: ManifestType): Promise<number | null>
  getEffectiveEmployees(at: Date): Promise<EffectiveEmployee[]>
  getAttendanceContext(): Promise<AttendanceContext>
  enqueueAttendance(payload: AttendancePayload): Promise<void>
  getAttendanceReceipt(eventUuid: string): Promise<AttendanceReceipt | null>
  getPendingOutbox(limit: number): Promise<PendingOutboxEvent[]>
  applyOutboxResults(results: AttendanceSyncResult[]): Promise<void>
  releaseOutbox(eventUuids: string[], errorCode: string): Promise<void>
  resetInterruptedOutbox(): Promise<void>
  updateClockDrift(seconds: number): Promise<void>
  getSummary(): Promise<LocalSyncSummary>
}
