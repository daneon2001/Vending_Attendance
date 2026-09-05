import { Capacitor } from '@capacitor/core'
import { KeychainAccess, SecureStorage } from '@aparajita/capacitor-secure-storage'
import type { DeviceCredentials } from '@/domain/types'
import { EdgeError } from '@/domain/errors'

const KEY = 'device_identity'

export interface CredentialStore {
  get(): Promise<DeviceCredentials | null>
  save(credentials: DeviceCredentials): Promise<void>
  clear(): Promise<void>
}

export class NativeDeviceCredentialStore implements CredentialStore {
  private initialized = false

  private async initialize(): Promise<void> {
    if (this.initialized) return
    if (!Capacitor.isNativePlatform()) {
      throw new EdgeError(
        'NOT_PROVISIONED',
        'Las credenciales sólo pueden almacenarse en Keychain/Keystore nativo.',
      )
    }
    await SecureStorage.setKeyPrefix('vending_attendance_')
    await SecureStorage.setSynchronize(false)
    await SecureStorage.setDefaultKeychainAccess(KeychainAccess.whenUnlockedThisDeviceOnly)
    this.initialized = true
  }

  async get(): Promise<DeviceCredentials | null> {
    await this.initialize()
    const value = await SecureStorage.get(KEY)
    if (!value) return null
    if (typeof value !== 'object' || Array.isArray(value)) {
      throw new EdgeError('NOT_PROVISIONED', 'La identidad segura del dispositivo es inválida.')
    }
    return value as unknown as DeviceCredentials
  }

  async save(credentials: DeviceCredentials): Promise<void> {
    await this.initialize()
    await SecureStorage.set(KEY, credentials as unknown as Record<string, unknown>)
  }

  async clear(): Promise<void> {
    await this.initialize()
    await SecureStorage.remove(KEY)
  }
}

export class MemoryCredentialStore implements CredentialStore {
  private value: DeviceCredentials | null = null

  async get(): Promise<DeviceCredentials | null> {
    return this.value ? { ...this.value } : null
  }

  async save(credentials: DeviceCredentials): Promise<void> {
    this.value = { ...credentials }
  }

  async clear(): Promise<void> {
    this.value = null
  }
}
