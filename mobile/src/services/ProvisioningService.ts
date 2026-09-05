import { Device } from '@capacitor/device'
import { Capacitor } from '@capacitor/core'
import { App } from '@capacitor/app'
import type { ProvisioningApiClient } from '@/api/ProvisioningApiClient'
import type { CredentialStore } from '@/security/DeviceCredentialStore'
import type { EdgeStore } from '@/storage/EdgeStore'

export class ProvisioningService {
  constructor(
    private readonly api: ProvisioningApiClient,
    private readonly credentials: CredentialStore,
    private readonly store: EdgeStore,
  ) {}

  async provision(token: string, serialOverride?: string): Promise<void> {
    const [info, identity, appInfo] = await Promise.all([Device.getInfo(), Device.getId(), App.getInfo()])
    const response = await this.api.provision({
      provisioning_token: token.trim(),
      device_serial: serialOverride?.trim() || identity.identifier,
      device_name: info.name,
      platform: Capacitor.getPlatform(),
      platform_version: info.osVersion,
      app_version: appInfo.version,
      hardware_model: info.model,
    })
    const issued = {
      deviceUuid: response.credentials.device_id,
      credential: response.credentials.credential,
      credentialVersion: response.credentials.credential_version,
    }
    await this.credentials.save(issued)
    try {
      await this.store.saveProvisionedDevice(
        response.device.uuid,
        response.device.status,
        response.credentials.credential_version,
      )
    } catch (error) {
      await this.credentials.clear()
      throw error
    }
  }
}
