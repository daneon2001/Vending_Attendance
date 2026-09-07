import { ref } from 'vue'
import { runtimeConfig } from '@/config/runtime'
import { credentialStore, connectivityService, edgeStore } from '@/app/services'
import { CapacitorLocationService } from '@/services/LocationService'
import { SqliteSupportStore } from './SqliteSupportStore'
import { SupportApiClient } from './SupportApiClient'
import { PrivateEvidenceFiles } from './PrivateEvidenceFiles'
import { SupportCaptureService } from './SupportCaptureService'
import { SupportSyncService } from './SupportSyncService'
import { SupportVerificationService } from './SupportVerificationService'

export const supportStore = new SqliteSupportStore()
export const supportApi = new SupportApiClient(runtimeConfig.apiBaseUrl, credentialStore, undefined, runtimeConfig.httpTimeoutMs)
export const supportFiles = new PrivateEvidenceFiles()
const location = new CapacitorLocationService(runtimeConfig.gpsTimeoutMs, runtimeConfig.deploymentMode === 'development')
export const supportCapture = new SupportCaptureService(supportStore, supportFiles, credentialStore, location)
export const supportSync = new SupportSyncService(supportApi, supportStore, supportFiles, credentialStore, connectivityService)
export const supportVerification = new SupportVerificationService(supportStore, edgeStore, credentialStore, supportApi, connectivityService, location)
export const supportReady = ref(false)
export const supportStartupError = ref(false)
let initialization: Promise<void> | null = null

export function initializeSupport(): Promise<void> {
  if (initialization) return initialization
  initialization = (async () => {
    try {
      await supportStore.initialize()
      await supportCapture.initialize()
      supportReady.value = true; supportStartupError.value = false
      void supportSync.start()
    } catch {
      supportStartupError.value = true
      initialization = null
    }
  })()
  return initialization
}
