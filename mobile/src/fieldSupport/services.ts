import { SecureStorage } from '@aparajita/capacitor-secure-storage'
import { runtimeConfig } from '@/config/runtime'
import { FieldMobileStore } from '@/fieldIdentity/FieldMobileStore'
import { FieldMobileTransport } from '@/fieldIdentity/FieldMobileTransport'
import { fieldDeviceKey } from '@/fieldIdentity/FieldDeviceKey'
import { CapacitorLocationService } from '@/services/LocationService'
import { FieldActivityFlow, type PendingOperation } from './FieldActivityFlow'
import { markRaw } from 'vue'
import { supportStore, supportFiles } from '@/support/services'
import { connectivityService } from '@/app/services'
import { FieldOfflineStore } from './FieldOfflineStore'
import { FieldOfflineSupport } from './FieldOfflineSupport'

class FieldActivityStore extends FieldMobileStore {
  async pending(): Promise<PendingOperation | null> {
    await this.session() // Established secure prefix and Android-only storage settings.
    return await SecureStorage.get('field_support_online_retry_v1') as unknown as PendingOperation | null
  }
  async savePending(value: PendingOperation | null): Promise<void> {
    await this.session()
    await SecureStorage.set('field_support_online_retry_v1', value as unknown as Record<string, unknown>)
  }
}
function api() {
  const store = new FieldActivityStore()
  return { store, transport: new FieldMobileTransport(import.meta.env.VITE_FIELD_IDENTITY_BASE_URL?.trim() || runtimeConfig.apiBaseUrl, store) }
}
let offline: FieldOfflineSupport | null = null
function offlineSupport(): FieldOfflineSupport {
  if (!offline) {
    const { store, transport } = api()
    offline = markRaw(new FieldOfflineSupport(new FieldOfflineStore(supportStore), store, transport, fieldDeviceKey, supportFiles, connectivityService))
  }
  return offline
}
export async function fieldActivitiesAvailable(): Promise<boolean> {
  try {
    const { transport } = api()
    if (!await transport.hasSession()) return false
    const service = offlineSupport()
    await service.prepare()
    void service.sync()
    return true
  } catch { return false }
}
export function fieldActivityFlow(): FieldActivityFlow {
  const { store, transport } = api()
  return new FieldActivityFlow(transport, fieldDeviceKey, new CapacitorLocationService(runtimeConfig.gpsTimeoutMs), store, undefined, offlineSupport())
}
