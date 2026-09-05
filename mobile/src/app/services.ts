import { runtimeConfig } from '@/config/runtime'
import { DeviceApiClient } from '@/api/DeviceApiClient'
import { EdgeApiService } from '@/api/EdgeApiService'
import { ProvisioningApiClient } from '@/api/ProvisioningApiClient'
import { NativeDeviceCredentialStore } from '@/security/DeviceCredentialStore'
import { SqliteEdgeStore } from '@/storage/SqliteEdgeStore'
import { ConnectivityService } from '@/services/ConnectivityService'
import { GeofenceValidationService } from '@/services/GeofenceValidationService'
import { CapacitorLocationService } from '@/services/LocationService'
import { AttendanceCaptureService } from '@/services/AttendanceCaptureService'
import { ProvisioningService } from '@/services/ProvisioningService'
import { EdgeSyncService } from '@/services/EdgeSyncService'

export const credentialStore = new NativeDeviceCredentialStore()
export const edgeStore = new SqliteEdgeStore()
export const connectivityService = new ConnectivityService()
export const deviceApiClient = new DeviceApiClient(
  runtimeConfig.apiBaseUrl,
  credentialStore,
  undefined,
  runtimeConfig.httpTimeoutMs,
)
export const edgeApi = new EdgeApiService(deviceApiClient)
export const provisioningService = new ProvisioningService(
  new ProvisioningApiClient(runtimeConfig.apiBaseUrl, undefined, runtimeConfig.httpTimeoutMs),
  credentialStore,
  edgeStore,
)
export const geofenceValidationService = new GeofenceValidationService()
export const attendanceCaptureService = new AttendanceCaptureService(
  edgeStore,
  new CapacitorLocationService(),
  geofenceValidationService,
)
export const edgeSyncService = new EdgeSyncService(
  edgeApi,
  edgeStore,
  connectivityService,
  runtimeConfig.attendanceBatchSize,
)
