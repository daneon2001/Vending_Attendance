import { describe, expect, it } from 'vitest'
import { canonicalDeviceRequest, createDeviceHmacHeaders, sha256 } from '@/security/hmac'

describe('device HMAC contract', () => {
  it('uses the Laravel canonical method, request target, timestamp, nonce and raw-body hash', async () => {
    const bodyHash = await sha256('')
    expect(bodyHash).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
    expect(canonicalDeviceRequest('get', '/api/v1/device/bootstrap?config_version_applied=4', '1725451200', '550e8400-e29b-41d4-a716-446655440000', bodyHash)).toBe(
      'GET\n/api/v1/device/bootstrap?config_version_applied=4\n1725451200\n550e8400-e29b-41d4-a716-446655440000\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    )
  })

  it('signs the exact body and issues a new UUID nonce per request', async () => {
    const input = {
      deviceUuid: 'ba10e6f2-1b73-4b33-9baa-156a183824e7',
      credential: 'a'.repeat(64),
      method: 'POST',
      requestTarget: '/api/v1/device/attendance/events/batch',
      rawBody: '{"events":[]}',
      timestamp: 1725451200,
    }
    const first = await createDeviceHmacHeaders(input)
    const second = await createDeviceHmacHeaders(input)
    expect(first['X-Device-Id']).toBe(input.deviceUuid)
    expect(first['X-Timestamp']).toBe('1725451200')
    expect(first['X-Nonce']).not.toBe(second['X-Nonce'])
    expect(first['X-Signature']).toMatch(/^[A-Za-z0-9+/]{43}=$/)
  })
})
