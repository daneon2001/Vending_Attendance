import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryCredentialStore } from '@/security/DeviceCredentialStore'
import { SqliteSupportStore } from '@/support/SqliteSupportStore'
import { SupportVerificationService } from '@/support/SupportVerificationService'
import type { SupportApi } from '@/support/SupportApiClient'
import { context, DEVICE, sqliteFixture } from './support-fixtures'

describe('real-source Device verification collection', () => {
  let fixture: Awaited<ReturnType<typeof sqliteFixture>>
  let store: SqliteSupportStore
  let credentials: MemoryCredentialStore
  beforeEach(async () => {
    fixture = await sqliteFixture(); store = new SqliteSupportStore(fixture.open); await store.initialize(); await store.saveContext(context)
    credentials = new MemoryCredentialStore(); await credentials.save({ deviceUuid: DEVICE, credential: 'synthetic-private-credential', credentialVersion: 1 })
  })
  afterEach(async () => { await fixture.dispose() })

  it('persists a bounded offline session and never treats permissions as working camera hardware', async () => {
    const summary = { machineCode: 'TEST', deviceStatus: 'ACTIVE', configurationVersion: 2, employeeManifestVersion: 3, pendingEvents: 2, lastSyncAt: null }
    const location = { capture: vi.fn(async () => ({ latitude: 19.4, longitude: -99.1, accuracy_m: 10, captured_at: new Date().toISOString() })) }
    const api = { context: vi.fn() } as unknown as SupportApi
    const service = new SupportVerificationService(store, { getSummary: vi.fn(async () => summary) }, credentials, api,
      { current: () => 'OFFLINE' }, location,
      { getInfo: vi.fn(async () => ({ version: '1.0.0', build: '3', name: 'Test', id: 'com.test' })) },
      { checkPermissions: vi.fn(async () => ({ camera: 'granted' as const, photos: 'prompt' as const })) },
      { checkPermissions: vi.fn(async () => ({ location: 'granted' as const, coarseLocation: 'granted' as const })) })
    const result = await service.run()
    const checks = Object.fromEntries(result.input.checks.map(check => [check.code, check]))
    expect(result.input.checks).toHaveLength(9)
    expect(checks.CAMERA_PERMISSION.result).toBe('PASS')
    expect(checks.CAMERA_AVAILABILITY.result).toBe('NOT_AVAILABLE')
    expect(checks.API_REACHABILITY.result).toBe('NOT_AVAILABLE')
    expect(checks.LOCAL_OUTBOX).toMatchObject({ result: 'WARNING', details: { pending_count: 2 } })
    expect(api.context).not.toHaveBeenCalled()
    expect(location.capture).toHaveBeenCalledOnce()
    const operation = await store.operation(result.operationUuid, DEVICE)
    expect(JSON.stringify(operation)).not.toMatch(/latitude|longitude|synthetic-private-credential/)
    await fixture.closeConnections(); const reopened = new SqliteSupportStore(fixture.open); await reopened.initialize()
    expect((await reopened.lastVerification(DEVICE))?.uuid).toBe(result.operationUuid)
  })

  it('records missing local data and denied GPS truthfully, and makes no capture call', async () => {
    const location = { capture: vi.fn() }
    const service = new SupportVerificationService(store, { getSummary: vi.fn(async () => { throw new Error('sqlite unavailable') }) }, credentials,
      { context: vi.fn(async () => { throw new Error('network unavailable') }) } as unknown as SupportApi,
      { current: () => 'ONLINE' }, location,
      { getInfo: vi.fn(async () => { throw new Error('not available') }) },
      { checkPermissions: vi.fn(async () => { throw new Error('camera unavailable') }) },
      { checkPermissions: vi.fn(async () => ({ location: 'denied' as const, coarseLocation: 'denied' as const })) })
    const result = await service.run()
    const checks = Object.fromEntries(result.input.checks.map(check => [check.code, check]))
    expect(checks.API_REACHABILITY.result).toBe('WARNING')
    expect(checks.LOCAL_CONFIGURATION.result).toBe('NOT_AVAILABLE')
    expect(checks.GPS_PERMISSION.result).toBe('WARNING')
    expect(checks.GPS_AVAILABILITY.result).toBe('NOT_AVAILABLE')
    expect(location.capture).not.toHaveBeenCalled()
  })
})
