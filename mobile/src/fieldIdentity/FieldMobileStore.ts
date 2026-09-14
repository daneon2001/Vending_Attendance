import { Capacitor } from '@capacitor/core'
import { KeychainAccess, SecureStorage } from '@aparajita/capacitor-secure-storage'
import type { HumanSession, HumanSessionStore } from './FieldMobileTransport'
import type { EnrollmentInput } from './FieldEnrollment'

export interface EnrollmentDraft { origin: string; employeeNumber: string; input: EnrollmentInput }
/** A proved device reference; verifiedOrigin scopes its last proof, not its identity. */
export interface VerifiedFieldIdentity {
  deviceUuid: string
  employeeNumber: string
  keyVersion: number
  keyFingerprint: string
  verifiedOrigin: string
}
export interface EnrollmentDraftStore {
  draft(): Promise<EnrollmentDraft | null>
  saveDraft(value: EnrollmentDraft): Promise<void>
  identity(): Promise<VerifiedFieldIdentity | null>
  saveIdentity(value: VerifiedFieldIdentity): Promise<void>
}

/** Distinct keys within the established secure prefix; never clears that store. */
export class FieldMobileStore implements HumanSessionStore, EnrollmentDraftStore {
  private async initialize(): Promise<void> {
    if (Capacitor.getPlatform() !== 'android') throw new Error('Se requiere Android.')
    await SecureStorage.setKeyPrefix('vending_attendance_')
    await SecureStorage.setSynchronize(false)
    await SecureStorage.setDefaultKeychainAccess(KeychainAccess.whenUnlockedThisDeviceOnly)
  }
  async session(): Promise<HumanSession | null> {
    await this.initialize()
    return await SecureStorage.get('field_mobile_session_v1') as unknown as HumanSession | null
  }
  async saveSession(value: HumanSession | null): Promise<void> {
    await this.initialize()
    await SecureStorage.set('field_mobile_session_v1', value as unknown as Record<string, unknown>)
  }
  async draft(): Promise<EnrollmentDraft | null> {
    await this.initialize()
    return await SecureStorage.get('field_mobile_draft_v1') as unknown as EnrollmentDraft | null
  }
  async saveDraft(value: EnrollmentDraft): Promise<void> {
    await this.initialize()
    await SecureStorage.set('field_mobile_draft_v1', value as unknown as Record<string, unknown>)
  }
  async identity(): Promise<VerifiedFieldIdentity | null> {
    await this.initialize()
    return await SecureStorage.get('field_mobile_identity_v1') as unknown as VerifiedFieldIdentity | null
  }
  async saveIdentity(value: VerifiedFieldIdentity): Promise<void> {
    await this.initialize()
    await SecureStorage.set('field_mobile_identity_v1', value as unknown as Record<string, unknown>)
  }
}
