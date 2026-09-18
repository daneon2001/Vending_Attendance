import { describe, expect, it, vi } from 'vitest'
import { FieldMobileFlow, type FieldProfile } from './FieldMobileFlow'
import { FieldMobileError, FieldMobileTransport, fieldMobileMessage, type HumanSession } from './FieldMobileTransport'
import type { EnrollmentDraft, VerifiedFieldIdentity } from './FieldMobileStore'
import type { FieldDeviceKey } from './FieldDeviceKey'
import { readFileSync } from 'node:fs'

const origin = 'https://field.test'
const mask = '******MASK'
function setup(deviceUuid = 'fixture-device') {
  let draft: EnrollmentDraft | null = null
  let identity: VerifiedFieldIdentity | null = null
  const profile: FieldProfile = { employee: { name: 'Synthetic technician', number: 'FIXTURE' }, phone: mask,
    phoneVerified: false, phone_verification_method: 'LOCAL_SIMULATED', verified_otp_uuid: null, devices: [] }
  const receipt = { device_uuid: deviceUuid, status: 'ACTIVE' as const, phone: mask, key_version: 1 }
  const api = {
    origin, login: vi.fn(async () => {}), hasSession: vi.fn(async () => true), logout: vi.fn(async () => {}),
    post: vi.fn(async (action: string) => {
      if (action === 'profile') return profile
      if (action === 'otp-send') return { otp_uuid: 'fixture-otp', local_code: '123456', phone: mask, simulation: true }
      if (action === 'otp-verify') { profile.verified_otp_uuid = 'fixture-otp'; return { verified: true } }
      if (action === 'register') return { ...receipt, status: 'PENDING' }
      if (action === 'challenge') {
        const id: string = crypto.randomUUID()
        return { challenge_uuid: id, message: 'FIELD_MOBILE_V1\n' + JSON.stringify({ purpose: 'ACTOR', device_uuid: deviceUuid, challenge_uuid: id, key_fingerprint: 'a'.repeat(64) }) }
      }
      if (action === 'prove') {
        profile.devices = [receipt]
        return { ...receipt, context: { deviceVerified: true, phoneVerified: false } }
      }
      throw new Error('Unexpected call')
    }),
  }
  const keys: FieldDeviceKey = {
    createKey: vi.fn(async () => ({ deviceUuid, publicKey: 'PUBLIC_ONLY_' + deviceUuid, keyVersion: 1, keyStore: 'AndroidKeyStore' })),
    sign: vi.fn(async () => ({ signature: 'FIXTURE_SIGNATURE' })),
    inspectKey: vi.fn(async () => ({ available: true, backing: 'UNKNOWN' as const, strongBoxAvailable: false,
      keyVersion: 1, keyFingerprint: 'a'.repeat(64), originRecoveryAllowed: true })),
  }
  const store = { draft: vi.fn(async () => draft), saveDraft: vi.fn(async (value: EnrollmentDraft) => { draft = structuredClone(value) }),
    identity: vi.fn(async () => identity), saveIdentity: vi.fn(async (value: VerifiedFieldIdentity) => { identity = structuredClone(value) }) }
  let i = 0
  const make = () => new FieldMobileFlow(api as unknown as FieldMobileTransport, store, keys,
    async () => ({ platform: 'android', platformVersion: 'test', appVersion: 'test', hardwareModel: 'Fixture' }),
    () => ++i % 2 ? deviceUuid : 'fixture-operation-' + deviceUuid)
  return { flow: make(), make, api, keys, store, profile, receipt }
}

describe('human mobile identity flow', () => {
  it('binds send, verification and registration to the same installation UUID before creating a key', async () => {
    const { flow, api, keys } = setup('installation-context')
    await flow.open(); await flow.sendOtp()
    expect(keys.createKey).not.toHaveBeenCalled()
    expect(api.post).toHaveBeenCalledWith('otp-send', { device_uuid: 'installation-context' })
    await flow.verify('123456')
    expect(api.post).toHaveBeenCalledWith('otp-verify', {
      otp_uuid: 'fixture-otp', code: '123456', device_uuid: 'installation-context',
    })
    expect(api.post).toHaveBeenCalledWith('register', expect.objectContaining({ device_uuid: 'installation-context' }))
    expect(flow.step).toBe('active')
  })

  it('independent phones retain independent IDs, drafts and public keys without copying a binding', async () => {
    const first = setup('phone-one'); const second = setup('phone-two')
    for (const phone of [first, second]) {
      await phone.flow.open(); await phone.flow.sendOtp(); await phone.flow.verify('123456')
      expect(phone.flow.step).toBe('active')
    }
    expect((await first.store.draft())?.input.deviceUuid).toBe('phone-one')
    expect((await second.store.draft())?.input.deviceUuid).toBe('phone-two')
    expect(first.keys.createKey).toHaveBeenCalledExactlyOnceWith({ deviceUuid: 'phone-one' })
    expect(second.keys.createKey).toHaveBeenCalledExactlyOnceWith({ deviceUuid: 'phone-two' })
    expect(await first.keys.createKey({ deviceUuid: 'phone-one' })).not.toEqual(await second.keys.createKey({ deviceUuid: 'phone-two' }))
  })
  it('a different phone cannot resume an existing server binding without its native key', async () => {
    const first = setup('phone-one'); const second = setup('phone-two')
    second.profile.devices = [first.receipt]
    vi.mocked(second.keys.inspectKey!).mockResolvedValue({ available: false, backing: 'UNKNOWN', strongBoxAvailable: false })
    await second.flow.open()
    expect(second.flow.step).toBe('blocked')
    expect(second.keys.createKey).not.toHaveBeenCalled()
    expect(second.keys.sign).not.toHaveBeenCalled()
    expect(await second.store.draft()).toBeNull()
  })
  it('generates no key before OTP and confirms ACTIVE only after server proof', async () => {
    const { flow, keys, api, store } = setup()
    await flow.open()
    expect(flow.step).toBe('ready')
    expect(keys.createKey).not.toHaveBeenCalled()
    await flow.sendOtp()
    expect(flow.step).toBe('otp')
    expect(keys.createKey).not.toHaveBeenCalled()
    await flow.verify('123456')
    expect(flow.step).toBe('active')
    expect(flow.signatureConfirmed).toBe(true)
    expect(flow.profile?.phoneVerified).toBe(false)
    const actions = api.post.mock.calls.map(([a]) => a)
    expect(actions.indexOf('otp-verify')).toBeLessThan(actions.indexOf('register'))
    const persisted = JSON.stringify(store.saveDraft.mock.calls)
    expect(persisted).not.toContain('123456')
    expect(persisted).not.toContain(mask)
    expect(persisted).not.toContain('private')
  })

  it('wrong OTP creates no key and shows a friendly error', async () => {
    const { flow, api, keys } = setup()
    await flow.open(); await flow.sendOtp()
    api.post.mockRejectedValueOnce(new FieldMobileError(422, 'OTP_INCORRECT'))
    await flow.verify('654321')
    expect(keys.createKey).not.toHaveBeenCalled()
    expect(flow.error).toContain('no es correcto')
    expect(flow.signatureConfirmed).toBe(false)
  })

  it('restart uses retained metadata and a fresh signature, not OTP or replacement key', async () => {
    const { flow, make, api, keys } = setup()
    await flow.open(); await flow.sendOtp(); await flow.verify('123456')
    vi.mocked(keys.createKey).mockClear(); api.post.mockClear()
    const restarted = make()
    await restarted.open()
    expect(restarted.step).toBe('active')
    expect(restarted.signatureConfirmed).toBe(true)
    expect(keys.createKey).not.toHaveBeenCalled()
    expect(api.post.mock.calls.map(([a]) => a)).toEqual(['profile', 'challenge', 'prove'])
  })

  it('an HTTP success without explicit OTP verification creates no key', async () => {
    const { flow, api, keys } = setup()
    await flow.open(); await flow.sendOtp()
    api.post.mockResolvedValueOnce({ verified: false } as never)
    await flow.verify('123456')
    expect(keys.createKey).not.toHaveBeenCalled()
    expect(flow.signatureConfirmed).toBe(false)
  })

  it('revoked local binding is blocked without generating a replacement key', async () => {
    const { flow, profile, keys } = setup()
    await flow.open(); await flow.sendOtp(); await flow.verify('123456')
    profile.devices[0]!.status = 'REVOKED'
    vi.mocked(keys.createKey).mockClear()
    await flow.open()
    expect(flow.step).toBe('blocked')
    expect(keys.createKey).not.toHaveBeenCalled()
  })

  it('proof/network failure never confirms identity and retry preserves operation metadata', async () => {
    const { flow, api, store } = setup()
    await flow.open(); await flow.sendOtp()
    const original = api.post.getMockImplementation()!
    api.post.mockImplementation(async action => {
      if (action === 'prove') throw new FieldMobileError(0)
      return original(action)
    })
    await flow.verify('123456')
    expect(flow.step).toBe('resume')
    expect(flow.signatureConfirmed).toBe(false)
    const first = await store.draft()
    api.post.mockImplementation(original)
    await flow.resume()
    expect(await store.draft()).toEqual(first)
    expect(flow.step).toBe('active')
  })

  it('lost OTP verification response can recover through own server profile', async () => {
    const { flow, api, make } = setup()
    await flow.open(); await flow.sendOtp()
    const original = api.post.getMockImplementation()!
    api.post.mockImplementation(async action => {
      const result = await original(action)
      if (action === 'otp-verify') throw new FieldMobileError(0)
      return result
    })
    await flow.verify('123456')
    api.post.mockImplementation(original)
    const restarted = make()
    await restarted.open()
    expect(restarted.step).toBe('resume')
    await restarted.resume()
    expect(restarted.signatureConfirmed).toBe(true)
  })

  it('missing native key blocks automatic replacement and never generates a new key', async () => {
    const { flow, profile, receipt, keys } = setup()
    profile.devices = [receipt]
    vi.mocked(keys.inspectKey!).mockResolvedValue({ available: false, backing: 'UNKNOWN', strongBoxAvailable: false })
    await flow.open()
    expect(flow.step).toBe('blocked')
    expect(keys.createKey).not.toHaveBeenCalled()
  })

  it('draft from another employee is not silently replaced', async () => {
    const { flow, profile, store } = setup()
    await flow.open(); await flow.sendOtp(); await flow.verify('123456')
    const draft = await store.draft()
    profile.employee.number = 'OTHER'
    await flow.open()
    expect(flow.step).toBe('blocked')
    expect(await store.draft()).toEqual(draft)
  })

  it('transient OTP disappears on leaving enrollment', async () => {
    const { flow } = setup()
    await flow.open(); await flow.sendOtp()
    expect(flow.demoCode).toBe('123456')
    flow.clearTransient()
    expect(flow.demoCode).toBe('')
  })

  it('expiry requires human login but keeps enrollment draft', async () => {
    const { flow, api, store } = setup()
    await flow.open(); await flow.sendOtp(); await flow.verify('123456')
    const before = await store.draft()
    api.hasSession.mockResolvedValue(false)
    await flow.open()
    expect(flow.step).toBe('login')
    expect(await store.draft()).toEqual(before)
  })
})

describe('origin-safe ACTIVE recovery', () => {
  async function stale() {
    const f = setup()
    await f.store.saveDraft({ origin: 'https://192.168.101.15:8443', employeeNumber: 'FIXTURE',
      input: { deviceUuid: f.receipt.device_uuid, operationUuid: 'old-operation', otpUuid: 'old-consumed-otp',
        platform: 'android', platformVersion: '15', appVersion: 'old', hardwareModel: 'Fixture' } })
    f.api.origin = 'https://192.168.1.80:8443'
    f.profile.devices = [f.receipt]
    return f
  }
  it('recovers after new login and proof, retaining stale draft and key across repeated opens', async () => {
    const f = await stale(); const old = structuredClone(await f.store.draft())
    await f.flow.login('fixture@example.test', 'synthetic')
    expect(f.flow.step).toBe('active'); expect(f.flow.signatureConfirmed).toBe(true)
    expect(await f.store.draft()).toEqual(old)
    expect(await f.store.identity()).toMatchObject({ deviceUuid: f.receipt.device_uuid, verifiedOrigin: f.api.origin,
      keyVersion: 1, keyFingerprint: 'a'.repeat(64) })
    expect(f.keys.inspectKey).toHaveBeenCalledWith({ deviceUuid: f.receipt.device_uuid,
      previousOrigin: old!.origin, currentOrigin: f.api.origin })
    expect(f.api.post.mock.calls.map(([a]) => a)).toEqual(['profile', 'challenge', 'prove'])
    f.api.post.mockClear(); await f.make().open()
    expect(f.api.post.mock.calls.map(([a]) => a)).toEqual(['profile', 'challenge', 'prove'])
    expect(f.keys.createKey).not.toHaveBeenCalled()
  })
  it('rejects unauthorized/RELEASE origin transitions before asking for a challenge', async () => {
    const f = await stale()
    vi.mocked(f.keys.inspectKey!).mockResolvedValue({ available: true, backing: 'UNKNOWN', strongBoxAvailable: false,
      keyVersion: 1, keyFingerprint: 'a'.repeat(64), originRecoveryAllowed: false })
    await f.flow.open()
    expect(f.flow.step).toBe('blocked'); expect(f.api.post.mock.calls.map(([a]) => a)).toEqual(['profile'])
    expect(f.keys.sign).not.toHaveBeenCalled(); expect(f.store.saveIdentity).not.toHaveBeenCalled()
  })
  it('does not sign a challenge with another device or fingerprint', async () => {
    for (const alteration of [{ device_uuid: 'different-device' }, { key_fingerprint: 'b'.repeat(64) }, { purpose: 'ENROLLMENT' }]) {
      const f = await stale(); const original = f.api.post.getMockImplementation()!
      f.api.post.mockImplementation(async action => {
        const result = await original(action)
        if (action !== 'challenge') return result
        const challenge = result as { challenge_uuid: string; message: string }
        const claims = JSON.parse(challenge.message.split('\n')[1]!)
        return { ...challenge, message: 'FIELD_MOBILE_V1\n' + JSON.stringify({ ...claims, ...alteration }) }
      })
      await f.flow.open(); expect(f.flow.step).toBe('blocked')
      expect(f.keys.sign).not.toHaveBeenCalled(); expect(f.store.saveIdentity).not.toHaveBeenCalled()
    }
  })
  it('rejects a changed key version and missing local key without registration', async () => {
    for (const available of [true, false]) {
      const f = await stale()
      vi.mocked(f.keys.inspectKey!).mockResolvedValue({ available, backing: 'UNKNOWN', strongBoxAvailable: false,
        keyVersion: 2, keyFingerprint: 'a'.repeat(64), originRecoveryAllowed: true })
      await f.flow.open(); expect(f.flow.step).toBe('blocked')
      expect(f.keys.createKey).not.toHaveBeenCalled(); expect(f.keys.sign).not.toHaveBeenCalled()
    }
  })
  it('never selects another ACTIVE UUID when a local reference exists', async () => {
    const f = await stale(); f.profile.devices = [{ ...f.receipt, device_uuid: 'another-phone' }]
    await f.flow.open(); expect(f.flow.step).toBe('blocked'); expect(f.keys.inspectKey).not.toHaveBeenCalled()
  })
  it.each(['REVOKED', 'REPLACED', 'PENDING'] as const)('does not recover a stale %s binding', async status => {
    const f = await stale(); f.profile.devices = [{ ...f.receipt, status }]
    await f.flow.open(); expect(f.flow.step).toBe('blocked'); expect(f.keys.sign).not.toHaveBeenCalled()
  })
  it('does not persist identity or show ACTIVE when backend rejects proof', async () => {
    const f = await stale(); const original = f.api.post.getMockImplementation()!
    f.api.post.mockImplementation(async action => { if (action === 'prove') throw new FieldMobileError(403); return original(action) })
    await f.flow.open(); expect(f.flow.step).toBe('blocked'); expect(f.flow.signatureConfirmed).toBe(false)
    expect(f.store.saveIdentity).not.toHaveBeenCalled(); expect(f.keys.createKey).not.toHaveBeenCalled()
  })
  it('ignores stale OTP metadata when no ACTIVE binding exists, leaving registration explicit', async () => {
    const f = await stale(); f.profile.devices = []; f.profile.verified_otp_uuid = 'old-consumed-otp'
    await f.flow.open(); expect(f.flow.step).toBe('ready')
    expect(f.api.post.mock.calls.map(([a]) => a)).toEqual(['profile'])
    expect(f.keys.createKey).not.toHaveBeenCalled()
    await f.flow.sendOtp(); await f.flow.verify('123456')
    expect((await f.store.draft())?.input.operationUuid).not.toBe('old-operation')
    expect((await f.store.draft())?.origin).toBe(f.api.origin)
    expect(f.flow.step).toBe('active')
  })
  it('blocks another employee even with an ACTIVE candidate and never edits the stale draft', async () => {
    const f = await stale(); const old = await f.store.draft(); f.profile.employee.number = 'OTHER'
    await f.flow.open(); expect(f.flow.step).toBe('blocked'); expect(await f.store.draft()).toEqual(old)
    expect(f.keys.sign).not.toHaveBeenCalled()
  })
  it('pre-login UI offers consultation; enrolment title is restricted to ready state', () => {
    const source = readFileSync(new URL('./FieldMobilePage.vue', import.meta.url), 'utf8')
    expect(source).toContain("flow?.step === 'login' ? 'Inicia sesión para consultar tu dispositivo.'")
    expect(source).toContain("flow?.step === 'ready' ? 'Registrar dispositivo'")
  })
})

describe('human credential transport and presentation safety', () => {
  function transport() {
    let session: HumanSession | null = null
    const store = { session: async () => session, saveSession: async (next: HumanSession | null) => { session = next } }
    const request = vi.fn(async () => ({ status: 200, data: { token: 'fm_' + 'a'.repeat(64), expires_at: new Date(Date.now() + 3600000).toISOString() }, headers: {}, url: origin }))
    return { api: new FieldMobileTransport(origin, store, request, () => true), request, store }
  }

  it('rejects HTTP, embedded credentials and redirected origins before sending a password', () => {
    const store = { session: async () => null, saveSession: async () => {} }
    for (const url of ['http://field.test', 'https://user:pass@field.test', 'https://field.test/path', 'https://field.test?query=1']) {
      expect(() => new FieldMobileTransport(url, store)).toThrow('HTTPS')
    }
  })

  it('uses only dedicated native endpoint with no HMAC, cookies or redirects', async () => {
    const { api, request } = transport()
    await api.login('fixture@example.test', 'synthetic')
    await api.post('profile', {})
    expect(request).toHaveBeenLastCalledWith(expect.objectContaining({
      url: origin + '/api/v1/field-mobile/profile', disableRedirects: true,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer fm_' + 'a'.repeat(64) },
    }))
    expect(JSON.stringify(request.mock.calls)).not.toContain('X-Device')
  })

  it('rejects a token saved for another origin', async () => {
    const { api, request, store } = transport()
    await store.saveSession({ origin: 'https://other.test', token: 'fixture', expires_at: new Date(Date.now() + 10000).toISOString() })
    await expect(api.post('profile', {})).rejects.toThrow('sesión')
    expect(request).not.toHaveBeenCalled()
  })

  it('never displays raw native error payloads', async () => {
    const { api, request } = transport()
    request.mockRejectedValue(new Error('request body with sensitive fixture'))
    await expect(api.login('fixture@example.test', 'synthetic')).rejects.toThrow('No hay confirmación')
  })

  it('maps OTP states to Spanish without exposing unknown server text', () => {
    for (const reason of ['OTP_INCORRECT', 'OTP_EXPIRED', 'OTP_USED', 'OTP_LOCKED', 'OTP_WAIT']) {
      const message = fieldMobileMessage(422, reason)
      expect(message).not.toContain(reason)
      expect(message.length).toBeGreaterThan(20)
    }
    expect(fieldMobileMessage(503, '<script>')).not.toContain('<script>')
  })

  it('UI hides full UUID and native bridge logging is disabled', () => {
    const source = readFileSync(new URL('./FieldMobilePage.vue', import.meta.url), 'utf8')
    const config = readFileSync(new URL('../../capacitor.config.ts', import.meta.url), 'utf8')
    expect(source).toContain('device_uuid.slice(0, 8)')
    expect(source).toContain('Simulada para demostración')
    expect(source).not.toContain('Teléfono verificado')
    expect(source).not.toMatch(/<details[^>]+open/)
    expect(source).not.toContain('publicKey')
    expect(config).toContain("loggingBehavior: 'none'")
  })
})
