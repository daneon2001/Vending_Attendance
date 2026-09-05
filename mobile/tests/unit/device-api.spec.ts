import { describe, expect, it, vi } from 'vitest'
import { DeviceApiClient } from '@/api/DeviceApiClient'
import { MemoryCredentialStore } from '@/security/DeviceCredentialStore'

describe('DeviceApiClient', () => {
  it('signs and sends the exact serialized body without exposing credentials', async () => {
    const store = new MemoryCredentialStore()
    await store.save({ deviceUuid: '80ba7827-f9a4-4da8-a9f6-852b95ca4e8d', credential: 'b'.repeat(64), credentialVersion: 1 })
    const fetcher = vi.fn(async (_url: URL | RequestInfo, init?: RequestInit) => {
      expect(init?.body).toBe('{"events":[{"event_uuid":"abc"}]}')
      expect(new Headers(init?.headers).get('X-Device-Id')).toBe('80ba7827-f9a4-4da8-a9f6-852b95ca4e8d')
      expect(new Headers(init?.headers).get('X-Signature')).not.toContain('b'.repeat(64))
      return new Response('{"results":[]}', { status: 200, headers: { 'Content-Type': 'application/json' } })
    })
    const client = new DeviceApiClient('https://edge.example.test', store, fetcher as typeof fetch)
    await client.request('POST', '/api/v1/device/attendance/events/batch', { events: [{ event_uuid: 'abc' }] })
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('rejects requests without a provisioned identity', async () => {
    const client = new DeviceApiClient('https://edge.example.test', new MemoryCredentialStore(), vi.fn() as unknown as typeof fetch)
    await expect(client.request('GET', '/api/v1/device/bootstrap')).rejects.toMatchObject({ code: 'NOT_PROVISIONED' })
  })

  it('aborts a stalled request at the configured timeout', async () => {
    const store = new MemoryCredentialStore()
    await store.save({ deviceUuid: '80ba7827-f9a4-4da8-a9f6-852b95ca4e8d', credential: 'b'.repeat(64), credentialVersion: 1 })
    const fetcher = vi.fn((_url: URL | RequestInfo, init?: RequestInit) => new Promise<Response>((_resolve, reject) => {
      init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
    }))
    const client = new DeviceApiClient('https://edge.example.test', store, fetcher as typeof fetch, 5)
    await expect(client.request('GET', '/api/v1/device/bootstrap'))
      .rejects.toMatchObject({ code: 'NETWORK_TIMEOUT', retryable: true })
  })

  it('classifies DNS or transport failures as retryable offline errors', async () => {
    const store = new MemoryCredentialStore()
    await store.save({ deviceUuid: '80ba7827-f9a4-4da8-a9f6-852b95ca4e8d', credential: 'b'.repeat(64), credentialVersion: 1 })
    const fetcher = vi.fn(async () => { throw new TypeError('Failed to fetch') })
    const client = new DeviceApiClient('https://unresolvable.example.test', store, fetcher as typeof fetch)

    await expect(client.request('GET', '/api/v1/device/bootstrap'))
      .rejects.toMatchObject({ code: 'OFFLINE', retryable: true })
  })
})
