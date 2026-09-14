import { FieldEnrollment, type EnrollmentInput, type EnrollmentReceipt } from './FieldEnrollment'
import type { FieldDeviceKey } from './FieldDeviceKey'
import type { EnrollmentDraftStore, EnrollmentDraft } from './FieldMobileStore'
import { FieldMobileError, type FieldMobileTransport } from './FieldMobileTransport'

export interface FieldProfile {
  employee: { name: string; number: string }
  phone: string | null
  phoneVerified: false
  phone_verification_method: 'LOCAL_SIMULATED'
  verified_otp_uuid: string | null
  devices: EnrollmentReceipt[]
}
export class FieldMobileFlow {
  busy = false
  error = ''
  step: 'login' | 'ready' | 'otp' | 'resume' | 'active' | 'blocked' = 'login'
  profile: FieldProfile | null = null
  receipt: EnrollmentReceipt | null = null
  demoCode = ''
  otpUuid = ''
  signatureConfirmed = false
  backing: 'HARDWARE' | 'SOFTWARE' | 'UNKNOWN' = 'UNKNOWN'
  strongBoxAvailable = false
  private readonly enrollment: FieldEnrollment
  constructor(private readonly api: FieldMobileTransport, private readonly store: EnrollmentDraftStore,
    private readonly keys: FieldDeviceKey, private readonly metadata: () => Promise<Pick<EnrollmentInput, 'platform' | 'platformVersion' | 'appVersion' | 'hardwareModel'>>,
    private readonly uuid: () => string = () => crypto.randomUUID()) {
    this.enrollment = new FieldEnrollment(api, keys)
  }

  private async perform(action: () => Promise<void>): Promise<void> {
    if (this.busy) return
    this.busy = true
    this.error = ''
    try { await action() }
    catch (error) {
      this.error = error instanceof FieldMobileError ? error.message : 'No fue posible confirmar el dispositivo. Conservamos el registro para reintentar.'
      this.signatureConfirmed = false
      if (error instanceof FieldMobileError && error.status === 401) { this.step = 'login'; this.profile = null }
    } finally { this.busy = false }
  }
  private async refresh(): Promise<void> {
    this.signatureConfirmed = false
    this.step = 'blocked'
    this.receipt = null
    this.profile = await this.api.post<FieldProfile>('profile', {})
    if (this.profile.phoneVerified !== false || this.profile.phone_verification_method !== 'LOCAL_SIMULATED') throw new FieldMobileError(503)
    const draft = await this.store.draft()
    const identity = await this.store.identity()
    if ((draft && draft.employeeNumber !== this.profile.employee.number)
      || (identity && identity.employeeNumber !== this.profile.employee.number)) {
      this.step = 'blocked'
      throw new FieldMobileError(409)
    }
    const localUuid = identity?.deviceUuid ?? draft?.input.deviceUuid
    const candidate = localUuid
      ? this.profile.devices.find(device => device.device_uuid === localUuid)
      : this.profile.devices.find(device => device.status === 'ACTIVE')
    const previousOrigin = identity?.verifiedOrigin ?? draft?.origin ?? this.api.origin
    const crossOrigin = previousOrigin !== this.api.origin
    if (!candidate && (identity || this.profile.devices.some(device => device.status === 'ACTIVE'))) {
      throw new FieldMobileError(409)
    }
    this.receipt = candidate ?? null
    if (candidate) {
      if (!['ACTIVE', 'PENDING'].includes(candidate.status)) {
        this.step = 'blocked'
        throw new FieldMobileError(409)
      }
      if (crossOrigin && candidate.status !== 'ACTIVE') throw new FieldMobileError(409)
      const inspected = await this.keys.inspectKey?.({ deviceUuid: candidate.device_uuid,
        ...(crossOrigin ? { previousOrigin, currentOrigin: this.api.origin } : {}) })
      if (!inspected?.available) { this.step = 'blocked'; throw new FieldMobileError(409) }
      if (crossOrigin && inspected.originRecoveryAllowed !== true) throw new FieldMobileError(403)
      if (candidate.key_version !== 1 || inspected.keyVersion !== candidate.key_version
        || !/^[a-f0-9]{64}$/.test(inspected.keyFingerprint ?? '')
        || (identity && (identity.keyVersion !== inspected.keyVersion || identity.keyFingerprint !== inspected.keyFingerprint))) {
        throw new FieldMobileError(409)
      }
      this.backing = inspected.backing
      this.strongBoxAvailable = inspected.strongBoxAvailable
      if (candidate.status === 'ACTIVE') {
        await this.enrollment.verifyExisting(candidate.device_uuid, inspected.keyFingerprint)
        await this.store.saveIdentity({ deviceUuid: candidate.device_uuid, employeeNumber: this.profile.employee.number,
          keyVersion: candidate.key_version, keyFingerprint: inspected.keyFingerprint!, verifiedOrigin: this.api.origin })
        this.signatureConfirmed = true
        this.step = 'active'
      } else { this.step = 'resume' }
    } else if (draft && !crossOrigin && this.profile.verified_otp_uuid === draft.input.otpUuid) {
      this.step = 'resume'
    } else {
      this.step = 'ready'
    }
  }
  async open(): Promise<void> {
    await this.perform(async () => {
      this.demoCode = ''; this.otpUuid = ''
      if (!await this.api.hasSession()) { this.step = 'login'; this.profile = null; return }
      await this.refresh()
    })
  }
  async login(email: string, password: string): Promise<void> {
    await this.perform(async () => { await this.api.login(email, password); await this.refresh() })
  }
  async sendOtp(): Promise<void> {
    await this.perform(async () => {
      if (!this.profile?.phone || this.receipt || this.step === 'blocked') throw new FieldMobileError(409)
      const result = await this.enrollment.sendOtp()
      if (!result.simulation || !/^[0-9]{6}$/.test(result.local_code ?? '')) throw new FieldMobileError(503)
      this.otpUuid = result.otp_uuid
      this.demoCode = result.local_code!
      this.step = 'otp'
    })
  }
  private async enroll(draft: EnrollmentDraft): Promise<void> {
    this.receipt = await this.enrollment.register(draft.input)
    if (this.receipt.status !== 'ACTIVE') throw new FieldMobileError(409)
    this.demoCode = ''; this.otpUuid = ''
    await this.refresh()
  }
  async verify(code: string): Promise<void> {
    await this.perform(async () => {
      if (!this.profile || !this.otpUuid || !/^[0-9]{6}$/.test(code)) throw new FieldMobileError(422)
      // Persist idempotency metadata before a possibly ambiguous network result.
      const savedDraft = await this.store.draft()
      const existing = savedDraft?.origin === this.api.origin ? savedDraft : null
      const draft: EnrollmentDraft = {
        origin: this.api.origin, employeeNumber: this.profile.employee.number,
        input: { ...await this.metadata(), operationUuid: this.uuid(),
          deviceUuid: existing?.input.deviceUuid ?? this.uuid(), otpUuid: this.otpUuid },
      }
      await this.store.saveDraft(draft)
      if ((await this.enrollment.verifyOtp(this.otpUuid, code)).verified !== true) throw new FieldMobileError(422)
      this.step = 'resume'
      await this.enroll(draft)
    })
  }
  async resume(): Promise<void> {
    await this.perform(async () => {
      const draft = await this.store.draft()
      if (!draft || !this.profile || draft.origin !== this.api.origin || draft.employeeNumber !== this.profile.employee.number) throw new FieldMobileError(409)
      // Registration retries use exactly the same metadata, operation UUID and key.
      await this.enroll(draft)
    })
  }
  async logout(): Promise<void> {
    await this.perform(async () => {
      await this.api.logout(); this.step = 'login'; this.profile = null; this.receipt = null
      this.clearTransient()
    })
  }
  clearTransient(): void { this.demoCode = ''; this.otpUuid = ''; this.signatureConfirmed = false }
}
