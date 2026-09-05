import { describe, expect, it, vi } from 'vitest'
import { ProvisioningService } from '@/services/ProvisioningService'
import type { CredentialStore } from '@/security/DeviceCredentialStore'
import type { ProvisioningApiClient } from '@/api/ProvisioningApiClient'
import type { EdgeStore } from '@/storage/EdgeStore'

vi.mock('@capacitor/device', () => ({
  Device: {
    getInfo: vi.fn(async () => ({ name: 'Edge tablet', model: 'Model X', osVersion: '15' })),
    getId: vi.fn(async () => ({ identifier: 'serial-local' })),
  },
}))
vi.mock('@capacitor/app', () => ({ App: { getInfo: vi.fn(async () => ({ version: '1.0.0' })) } }))
vi.mock('@capacitor/core', () => ({ Capacitor: { getPlatform: () => 'android' } }))

describe('device provisioning', () => {
  it('stores the one-time credential only in the credential store', async () => {
    let saved: { deviceUuid: string; credential: string; credentialVersion: number } | null = null
    const credentials = {
      get: async () => saved,
      save: async (value: typeof saved) => { saved = value },
      clear: async () => { saved = null },
    } as CredentialStore
    const saveProvisionedDevice = vi.fn(async () => undefined)
    const api = {
      provision: vi.fn(async () => ({
        device: { uuid: 'device-uuid', status: 'ACTIVE' },
        machine: { uuid: 'machine-uuid', config_version: 1 },
        credentials: {
          scheme: 'HMAC-SHA256', device_id: 'device-uuid', credential: 'c'.repeat(64),
          credential_version: 1, identity_header: 'X-Device-Id',
        },
        message: 'ok', server_time: '2026-09-04T12:00:00Z',
      })),
    } as unknown as ProvisioningApiClient
    const service = new ProvisioningService(api, credentials, { saveProvisionedDevice } as unknown as EdgeStore)
    await service.provision('a'.repeat(64))
    expect(saved).toEqual({ deviceUuid: 'device-uuid', credential: 'c'.repeat(64), credentialVersion: 1 })
    expect(saveProvisionedDevice).toHaveBeenCalledWith('device-uuid', 'ACTIVE', 1)
    expect(JSON.stringify(saveProvisionedDevice.mock.calls)).not.toContain('c'.repeat(64))
  })
})
