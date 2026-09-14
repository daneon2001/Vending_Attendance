import { describe, expect, it, vi } from 'vitest'
import { FieldEnrollment, type HumanIdentityTransport, type EnrollmentReceipt } from './FieldEnrollment'
import type { FieldDeviceKey } from './FieldDeviceKey'

const input = {
  operationUuid: 'operation-uuid', deviceUuid: 'device-uuid', otpUuid: 'otp-uuid',
  platform: 'android' as const, platformVersion: '15', appVersion: 'test', hardwareModel: 'Synthetic',
}
const receipt: EnrollmentReceipt = { device_uuid: input.deviceUuid, status: 'ACTIVE', phone: '******0001', key_version: 1 }
function setup(failProof = false) {
  const post = vi.fn(async (action: string) => {
    if (action === 'register') return { ...receipt, status: 'PENDING' }
    if (action === 'challenge') return { challenge_uuid: 'challenge-uuid', message: 'FIELD_MOBILE_V1\nopaque-challenge' }
    if (action === 'prove' && failProof) throw new Error('Sin confirmación del servidor')
    return receipt
  })
  const keys: FieldDeviceKey = {
    createKey: vi.fn(async () => ({ deviceUuid: input.deviceUuid, publicKey: 'PUBLIC', keyVersion: 1, keyStore: 'AndroidKeyStore' })),
    sign: vi.fn(async () => ({ signature: 'DER_BASE64' })),
  }
  const transport = { post } as unknown as HumanIdentityTransport
  return { client: new FieldEnrollment(transport, keys), keys, post }
}

describe('isolated field enrollment foundation', () => {
  it('sends only public key metadata, then signs the exact backend challenge', async () => {
    const { client, keys, post } = setup()
    expect(await client.register(input)).toEqual(receipt)
    expect(post.mock.calls.map(call => call[0])).toEqual(['register', 'challenge', 'prove'])
    expect(keys.sign).toHaveBeenCalledWith({ deviceUuid: input.deviceUuid, message: 'FIELD_MOBILE_V1\nopaque-challenge' })
    const serialized = JSON.stringify(post.mock.calls)
    for (const forbidden of ['private_key', 'credential', 'employee_id', 'HMAC', 'accuracy', 'imei']) {
      expect(serialized).not.toContain(forbidden)
    }
  })

  it('does not report ACTIVE when server proof confirmation fails', async () => {
    const { client } = setup(true)
    await expect(client.register(input)).rejects.toThrow('Sin confirmación')
  })

  it('never substitutes a different local key identity', async () => {
    const { client, keys, post } = setup()
    vi.mocked(keys.createKey).mockResolvedValue({ deviceUuid: 'other', publicKey: 'PUBLIC', keyVersion: 1, keyStore: 'AndroidKeyStore' })
    await expect(client.register(input)).rejects.toThrow('no corresponde')
    expect(post).not.toHaveBeenCalled()
  })

  it('resumes an already acknowledged registration without activating twice', async () => {
    const { client, post, keys } = setup()
    post.mockResolvedValue(receipt)
    expect(await client.register(input)).toEqual(receipt)
    expect(keys.sign).not.toHaveBeenCalled()
    expect(post).toHaveBeenCalledTimes(1)
  })

  it('supports a fresh challenge before activation without generating another key', async () => {
    const { client, post, keys } = setup()
    await client.activate(input.deviceUuid)
    expect(keys.createKey).not.toHaveBeenCalled()
    expect(post.mock.calls.map(call => call[0])).toEqual(['challenge', 'prove'])
  })

  it('does not derive user identity from phone or terminal credentials', async () => {
    const { client, post } = setup()
    await client.sendOtp()
    expect(post).toHaveBeenCalledWith('otp-send', {})
    await client.verifyOtp(input.otpUuid, '123456')
    expect(post).toHaveBeenCalledWith('otp-verify', { otp_uuid: input.otpUuid, code: '123456' })
    await client.revoke(input.deviceUuid)
    expect(post).toHaveBeenCalledWith('revoke', { device_uuid: input.deviceUuid })
  })
})
