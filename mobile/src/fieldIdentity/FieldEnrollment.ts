import type { FieldDeviceKey } from './FieldDeviceKey'

/**
 * Inject a separately authenticated HUMAN transport. Never pass DeviceApiClient
 * or HMAC credentials. No human login currently exists in the terminal app.
 */
export interface HumanIdentityTransport {
  post<T>(action: 'profile' | 'otp-send' | 'otp-verify' | 'register' | 'challenge' | 'prove' | 'revoke', body: Record<string, unknown>): Promise<T>
}
export interface EnrollmentReceipt {
  device_uuid: string
  status: 'PENDING' | 'ACTIVE' | 'REVOKED' | 'REPLACED'
  phone: string
  key_version: number
}
export interface EnrollmentInput {
  operationUuid: string
  deviceUuid: string
  otpUuid: string
  platform: 'android' | 'ios'
  platformVersion: string
  appVersion: string
  hardwareModel: string
  replacesUuid?: string
}

/** Personal identity only; never authorizes attendance or a support activity. */
export class FieldEnrollment {
  constructor(private readonly transport: HumanIdentityTransport, private readonly keys: FieldDeviceKey) {}

  sendOtp(deviceUuid?: string) {
    return this.transport.post<{ otp_uuid: string; phone: string; simulation: boolean; local_code?: string }>('otp-send', deviceUuid ? { device_uuid: deviceUuid } : {})
  }

  verifyOtp(otpUuid: string, code: string, deviceUuid?: string) {
    return this.transport.post<{ verified: boolean }>('otp-verify', { otp_uuid: otpUuid, code, ...(deviceUuid ? { device_uuid: deviceUuid } : {}) })
  }

  async register(input: EnrollmentInput): Promise<EnrollmentReceipt> {
    const key = await this.keys.createKey({ deviceUuid: input.deviceUuid })
    if (key.deviceUuid !== input.deviceUuid || key.keyVersion !== 1) {
      throw new Error('La clave no corresponde a este dispositivo.')
    }
    const receipt = await this.transport.post<EnrollmentReceipt>('register', {
      operation_uuid: input.operationUuid, device_uuid: input.deviceUuid, otp_uuid: input.otpUuid,
      public_key: key.publicKey, platform: input.platform, platform_version: input.platformVersion,
      app_version: input.appVersion, hardware_model: input.hardwareModel, replaces_uuid: input.replacesUuid ?? null,
    })
    if (receipt.status !== 'PENDING') return receipt
    return this.activate(input.deviceUuid)
  }

  async activate(deviceUuid: string): Promise<EnrollmentReceipt> {
    const challenge = await this.transport.post<{ challenge_uuid: string; message: string }>('challenge', {
      device_uuid: deviceUuid, purpose: 'ENROLLMENT',
    })
    const { signature } = await this.keys.sign({ deviceUuid, message: challenge.message })
    // ACTIVE is returned only by the server; transport failures never mark active.
    return this.transport.post<EnrollmentReceipt>('prove', {
      challenge_uuid: challenge.challenge_uuid, signature, purpose: 'ENROLLMENT',
    })
  }

  async verifyExisting(deviceUuid: string, expectedFingerprint?: string): Promise<void> {
    const challenge = await this.transport.post<{ challenge_uuid: string; message: string }>('challenge', {
      device_uuid: deviceUuid, purpose: 'ACTOR',
    })
    if (expectedFingerprint !== undefined) {
      if (!challenge.message.startsWith('FIELD_MOBILE_V1\n')) throw new Error('Challenge no válido.')
      const claims = JSON.parse(challenge.message.slice('FIELD_MOBILE_V1\n'.length))
      if (claims.purpose !== 'ACTOR' || claims.device_uuid !== deviceUuid
        || claims.challenge_uuid !== challenge.challenge_uuid || claims.key_fingerprint !== expectedFingerprint) {
        throw new Error('La referencia de clave no corresponde a este dispositivo.')
      }
    }
    const { signature } = await this.keys.sign({ deviceUuid, message: challenge.message })
    const result = await this.transport.post<{ context: { deviceVerified: boolean; phoneVerified: boolean } }>('prove', {
      challenge_uuid: challenge.challenge_uuid, signature, purpose: 'ACTOR',
    })
    if (result.context?.deviceVerified !== true || result.context.phoneVerified !== false) {
      throw new Error('El servidor no confirmó la identidad de este dispositivo.')
    }
  }

  revoke(deviceUuid: string) {
    return this.transport.post<EnrollmentReceipt>('revoke', { device_uuid: deviceUuid })
  }
}
