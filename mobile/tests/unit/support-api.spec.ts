import { describe, expect, it, vi } from 'vitest'
import { SupportApiClient, hashBytes } from '@/support/SupportApiClient'
import { MemoryCredentialStore } from '@/security/DeviceCredentialStore'
import { canonicalDeviceRequest, signDeviceRequest } from '@/security/hmac'
import { DEVICE, EVIDENCE, OTHER_DEVICE, TICKET } from './support-fixtures'

async function client(fetcher: typeof fetch, limit = 512_000) {
  const credentials = new MemoryCredentialStore()
  await credentials.save({ deviceUuid: DEVICE, credential: 'test-only-hmac-secret', credentialVersion: 1 })
  return new SupportApiClient('https://support.example.test', credentials, fetcher, 1000, limit)
}

describe('support exact-byte Device transport', () => {
  it('signs original binary bytes and changes nonce for an identical retry without putting credentials in the body', async () => {
    const bytes = new Uint8Array([255, 216, 255, 0, 128, 217])
    const nonces: string[] = []
    const fetcher = vi.fn(async (url: URL | RequestInfo, init?: RequestInit) => {
      expect(init?.body).toBeInstanceOf(ArrayBuffer)
      expect(new Uint8Array(init?.body as ArrayBuffer)).toEqual(bytes)
      const headers = new Headers(init?.headers)
      nonces.push(headers.get('X-Nonce')!)
      const path = new URL(String(url)).pathname
      const canonical = canonicalDeviceRequest('POST', path, headers.get('X-Timestamp')!, headers.get('X-Nonce')!, await hashBytes(bytes))
      expect(headers.get('X-Signature')).toBe(await signDeviceRequest('test-only-hmac-secret', canonical))
      expect(JSON.stringify(init)).not.toContain('test-only-hmac-secret')
      expect(init?.redirect).toBe('error')
      return Response.json({ evidence: { uuid: EVIDENCE, status: 'CONFIRMED' } })
    })
    const api = await client(fetcher as typeof fetch)
    await api.uploadEvidence(DEVICE, TICKET, EVIDENCE, bytes, 'image/jpeg')
    await api.uploadEvidence(DEVICE, TICKET, EVIDENCE, bytes, 'image/jpeg')
    expect(new Set(nonces).size).toBe(2)
  })

  it.each([409, 413, 422, 403, 404])('preserves definitive HTTP %i codes and redacts backend messages', async status => {
    const api = await client(vi.fn(async () => Response.json({ code: 'OPERATION_CONFLICT', message: '/private/path secret-like details' }, { status })) as typeof fetch)
    await expect(api.createTicket(DEVICE, { client_operation_uuid: TICKET })).rejects.toMatchObject({ status, code: 'OPERATION_CONFLICT', retryable: false })
    await expect(api.createTicket(DEVICE, {})).rejects.not.toThrow('/private/path')
  })

  it('honors 429 Retry-After and rejects a changed capture identity before making a request', async () => {
    const fetcher = vi.fn(async () => Response.json({ code: 'RATE_LIMITED' }, { status: 429, headers: { 'Retry-After': '240' } }))
    const api = await client(fetcher as typeof fetch)
    await expect(api.createTicket(DEVICE, {})).rejects.toMatchObject({ retryable: true, retryAfterSeconds: 240 })
    await expect(api.createTicket(OTHER_DEVICE, {})).rejects.toMatchObject({ code: 'IDENTITY_CHANGED' })
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('does not turn malformed success JSON into a confirmed upload', async () => {
    const api = await client(vi.fn(async () => new Response('not-json', { status: 200 })) as typeof fetch)
    await expect(api.uploadEvidence(DEVICE, TICKET, EVIDENCE, new Uint8Array([1]), 'image/jpeg')).rejects.toMatchObject({ code: 'INVALID_RESPONSE', retryable: true })
  })

  it('bounds thumbnail bytes even if Content-Length is missing', async () => {
    const api = await client(vi.fn(async () => new Response(new Uint8Array(33), { headers: { 'Content-Type': 'image/jpeg' } })) as typeof fetch, 32)
    await expect(api.thumbnail(DEVICE, TICKET, EVIDENCE)).rejects.toMatchObject({ code: 'THUMBNAIL_TOO_LARGE' })
  })

  it('rejects path traversal identifiers before a signed request', async () => {
    const fetcher = vi.fn()
    const api = await client(fetcher as typeof fetch)
    expect(() => api.ticket(DEVICE, '../../other')).toThrow()
    expect(fetcher).not.toHaveBeenCalled()
  })
  it('times out a stalled response body even after headers arrived', async () => {
    const credentials = new MemoryCredentialStore()
    await credentials.save({ deviceUuid: DEVICE, credential: 'test-secret', credentialVersion: 1 })
    const api = new SupportApiClient('https://support.example.test', credentials,
      vi.fn(async () => new Response(new ReadableStream({ start() {} }))) as typeof fetch, 10)
    await expect(api.context(DEVICE)).rejects.toMatchObject({ code: 'NETWORK_TIMEOUT', retryable: true })
  })
})
