export type DeviceStatus = 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'REVOKED' | 'RETIRED'
export type ConnectivityState = 'ONLINE' | 'OFFLINE' | 'UNKNOWN'
export type GeofenceResult = 'INSIDE' | 'OUTSIDE' | 'UNCERTAIN'
export type AttendanceEventType = 'CHECK_IN' | 'CHECK_OUT'
export type OutboxStatus = 'PENDING' | 'SYNCING' | 'SYNCED' | 'REJECTED'
export type ManifestType = 'CONFIGURATION' | 'EMPLOYEES'

export interface DeviceCredentials {
  deviceUuid: string
  credential: string
  credentialVersion: number
}

export interface ProvisioningInput {
  provisioning_token: string
  device_serial: string
  device_name?: string
  platform?: string
  platform_version?: string
  app_version?: string
  hardware_model?: string
}

export interface ProvisioningResponse {
  device: { uuid: string; status: DeviceStatus }
  machine: { uuid: string; config_version: number }
  credentials: {
    scheme: 'HMAC-SHA256'
    device_id: string
    credential: string
    credential_version: number
    identity_header: 'X-Device-Id'
  }
  message: string
  server_time: string
}

export interface GeofenceSnapshot {
  uuid: string
  version: number
  type: 'CIRCLE'
  latitude: number
  longitude: number
  radius_m: number
  minimum_acceptable_accuracy_m: number | null
  tolerance_m: number | null
}

export interface BootstrapResponse {
  device: { uuid: string; status: DeviceStatus }
  machine: {
    uuid: string
    machine_code: string
    status: string
    config_version: number
    timezone: string
  }
  geofence: null | {
    uuid: string
    version: number
    type: 'CIRCLE'
    center_latitude: number
    center_longitude: number
    radius_m: number
    minimum_acceptable_accuracy_m: number | null
    tolerance_m: number | null
  }
  configuration_changed: boolean
  server_time: string
  sync: {
    employee_manifest_version: number | null
    biometric_manifest_version: null
  }
}

export interface ManifestStatusResponse {
  configuration: ManifestVersionStatus
  employees: ManifestVersionStatus
  biometrics: {
    server_version: null
    applied_version: null
    changed: false
    supported: false
  }
  sync_state: string
  server_time: string
}

export interface ManifestVersionStatus {
  server_version: number
  server_hash: string
  applied_version: number | null
  applied_hash: string | null
  changed: boolean
  state: string
}

export interface ConfigurationManifestResponse {
  manifest_type: 'MACHINE_CONFIGURATION'
  manifest_version: number
  manifest_hash: string
  generated_at: string
  server_time: string
  device: { uuid: string }
  machine: {
    uuid: string
    machine_code: string
    status: string
    timezone: string
  }
  geofence: GeofenceSnapshot | null
}

export interface EmployeeManifestItem {
  employee_id: string
  employee_number: string
  name: string
  assignment: {
    uuid: string
    type: string
    valid_from: string
    valid_until: string | null
    attendance_allowed: boolean
    enrollment_allowed: boolean
    maintenance_allowed: boolean
  }
}

export interface EmployeeManifestResponse {
  manifest_type: 'EMPLOYEES'
  manifest_version: number
  manifest_hash: string
  generated_at: string
  server_time: string
  machine_uuid: string
  changed?: boolean
  employees?: EmployeeManifestItem[]
}

export interface LocationEvidence {
  latitude: number
  longitude: number
  accuracy_m: number
  captured_at: string
}

export interface GeofenceEvaluation {
  distance_m: number
  effective_distance_m: number
  radius_m: number
  accuracy_m: number
  tolerance_m: number
  result: GeofenceResult
  reason: string
}

export interface AttendancePayload {
  event_uuid: string
  employee_id: string
  event_type: AttendanceEventType
  captured_at: string
  employee_manifest_version: number
  configuration_version: number
  assignment_uuid: string
  device_timezone: string
  location: {
    latitude: number
    longitude: number
    accuracy_m: number
  }
  geofence: {
    version: number
    edge_result: GeofenceResult
  }
}

export interface AttendanceSyncResult {
  event_uuid: string
  status: 'STORED' | 'DUPLICATE' | 'REJECTED'
  remote_id?: string
  error_code?: string
  message?: string
}
