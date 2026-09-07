import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryCredentialStore } from '@/security/DeviceCredentialStore'
import { SqliteSupportStore } from '@/support/SqliteSupportStore'
import { SupportSyncService } from '@/support/SupportSyncService'
import { hashBytes, type SupportApi } from '@/support/SupportApiClient'
import { SupportError } from '@/support/types'
import type { EvidenceFiles } from '@/support/PrivateEvidenceFiles'
import { context, DEVICE, EVIDENCE, input, OTHER_DEVICE, serverTicket, sqliteFixture } from './support-fixtures'

describe('support offline sync dependencies and lost responses', () => {
  let fixture: Awaited<ReturnType<typeof sqliteFixture>>
  let store: SqliteSupportStore
  let credentials: MemoryCredentialStore
  let online: boolean
  const bytes = new Uint8Array([255, 216, 255, 217])
  beforeEach(async () => {
    fixture = await sqliteFixture(); store = new SqliteSupportStore(fixture.open); await store.initialize(); await store.saveContext(context)
    credentials = new MemoryCredentialStore(); await credentials.save({ deviceUuid: DEVICE, credential: 'synthetic-test-secret', credentialVersion: 1 })
    online = true
  })
  afterEach(async () => { await fixture.dispose() })
  function harness() {
    const calls: string[] = []
    const api: SupportApi = {
      context: vi.fn(async () => context),
      createTicket: vi.fn(async () => { calls.push('ticket'); return { ticket: serverTicket } }),
      reserveEvidence: vi.fn(async () => { calls.push('reserve'); return { evidence: { uuid: EVIDENCE, status: 'PENDING' } } }),
      uploadEvidence: vi.fn(async () => { calls.push('upload'); return { evidence: { uuid: EVIDENCE, status: 'CONFIRMED' } } }),
      comment: vi.fn(async () => ({ event: { uuid: crypto.randomUUID() } })),
      verification: vi.fn(async () => ({ verification: { uuid: crypto.randomUUID() } })),
      changes: vi.fn(async (_device, sequence) => ({ events: [], next_sequence: sequence, has_more: false })),
    }
    const files: EvidenceFiles = {
      preserve: vi.fn(), recover: vi.fn(), preview: vi.fn(), cleanupCameraSource: vi.fn(async () => false),
      read: vi.fn(async () => bytes),
      purgeConfirmed: vi.fn(async evidence => {
        expect(evidence.state).toBe('CONFIRMED')
        expect((await store.confirmedFiles(DEVICE)).some(item => item.localUuid === evidence.localUuid)).toBe(true)
        calls.push('purge')
      }),
    }
    const connectivity = { current: () => online ? 'ONLINE' as const : 'OFFLINE' as const, subscribe: vi.fn(() => vi.fn()) }
    return { api, files, calls, sync: new SupportSyncService(api, store, files, credentials, connectivity) }
  }
  async function enqueue() {
    const draft = await store.createDraft(DEVICE, input)
    const evidence = await store.reserveCapture(draft.localUuid, DEVICE)
    await store.completeCapture(evidence.localUuid, DEVICE, { path: `support-evidence/${evidence.localUuid}.jpg`, mime: 'image/jpeg', sizeBytes: bytes.length, uploadSha256: await hashBytes(bytes) })
    await store.submit(draft.localUuid, DEVICE)
    return { draft, evidence }
  }
  async function makePendingDue() { const db = await fixture.open(); await db.run("UPDATE support_operations SET next_attempt_at=NULL WHERE status='PENDING'") }

  it('blocks an offline report and its photo when the same Device is reassigned to another machine', async () => {
    const { draft, evidence } = await enqueue()
    await fixture.closeConnections(); store = new SqliteSupportStore(fixture.open); await store.initialize()
    const h = harness()
    vi.mocked(h.api.context).mockResolvedValue({ ...context, machine: { ...context.machine, uuid: OTHER_DEVICE } })
    await h.sync.syncNow()
    expect(h.api.createTicket).not.toHaveBeenCalled()
    expect(h.api.uploadEvidence).not.toHaveBeenCalled()
    expect(h.files.purgeConfirmed).not.toHaveBeenCalled()
    expect(await store.operation(draft.localUuid, DEVICE)).toMatchObject({ status: 'BLOCKED', errorCode: 'MACHINE_CHANGED', payload: { captured_machine_uuid: context.machine.uuid } })
    expect(await store.getEvidence(evidence.localUuid, DEVICE)).toMatchObject({ state: 'READY', purgedAt: null })
  })

  it('binds an offline verification to the capture machine instead of the reassigned current machine', async () => {
    const operation = await store.queueVerification(DEVICE, { started_at: '2026-09-07T12:00:00Z', completed_at: '2026-09-07T12:00:01Z', checks: [] })
    const h = harness()
    vi.mocked(h.api.context).mockResolvedValue({ ...context, machine: { ...context.machine, uuid: OTHER_DEVICE } })
    await h.sync.syncNow()
    expect(h.api.verification).not.toHaveBeenCalled()
    expect(await store.operation(operation, DEVICE)).toMatchObject({ status: 'BLOCKED', errorCode: 'MACHINE_CHANGED', payload: { captured_machine_uuid: context.machine.uuid } })
  })

  it('creates offline, reopens, reconnects and confirms ticket before photo upload; purge is last', async () => {
    const { draft, evidence } = await enqueue()
    const offline = harness(); online = false
    await offline.sync.syncNow()
    expect(offline.api.createTicket).not.toHaveBeenCalled()
    expect(offline.files.purgeConfirmed).not.toHaveBeenCalled()
    await fixture.closeConnections()
    store = new SqliteSupportStore(fixture.open); await store.initialize()
    const connected = harness(); online = true
    await Promise.all([connected.sync.syncNow(), connected.sync.syncNow()])
    expect(connected.calls).toEqual(['ticket', 'reserve', 'upload', 'purge'])
    expect((await store.getTicket(draft.localUuid, DEVICE))?.server?.folio).toBe(serverTicket.folio)
    expect((await store.getEvidence(evidence.localUuid, DEVICE))?.purgedAt).toBeTruthy()
    await connected.sync.syncNow()
    expect(connected.api.createTicket).toHaveBeenCalledOnce()
    expect(connected.api.uploadEvidence).toHaveBeenCalledOnce()
  })

  it('retries identical create and upload operations after lost acknowledgements, without a second logical resource', async () => {
    const { draft, evidence } = await enqueue()
    const h = harness()
    const remoteTickets = new Set<string>()
    let createCalls = 0; let uploadCalls = 0
    h.api.createTicket = vi.fn(async (_device, payload) => {
      remoteTickets.add(String(payload.client_operation_uuid))
      if (++createCalls === 1) throw new SupportError('NETWORK_TIMEOUT', 'Synthetic lost create receipt', true)
      return { ticket: serverTicket }
    })
    h.api.uploadEvidence = vi.fn(async () => {
      if (++uploadCalls === 1) throw new SupportError('NETWORK_TIMEOUT', 'Synthetic lost upload receipt', true)
      return { evidence: { uuid: EVIDENCE, status: 'CONFIRMED' } }
    })
    await h.sync.syncNow()
    expect(h.files.read).not.toHaveBeenCalled()
    expect(h.files.purgeConfirmed).not.toHaveBeenCalled()
    await makePendingDue(); await h.sync.syncNow()
    expect(h.files.purgeConfirmed).not.toHaveBeenCalled()
    expect((await store.getEvidence(evidence.localUuid, DEVICE))?.state).toBe('READY')
    await makePendingDue(); await h.sync.syncNow()
    expect(remoteTickets).toEqual(new Set([draft.localUuid]))
    expect(h.api.createTicket).toHaveBeenCalledTimes(2)
    expect(h.api.uploadEvidence).toHaveBeenCalledTimes(2)
    expect(h.files.purgeConfirmed).toHaveBeenCalledOnce()
  })

  it('retains unconfirmed files after upload rejection and never retries a definitive invalid image automatically', async () => {
    await enqueue(); const h = harness()
    h.api.uploadEvidence = vi.fn(async () => { throw new SupportError('MIME_MISMATCH', 'Synthetic MIME rejection', false, 422) })
    await h.sync.syncNow(); await h.sync.syncNow()
    expect(h.api.uploadEvidence).toHaveBeenCalledOnce()
    expect(h.files.purgeConfirmed).not.toHaveBeenCalled()
    expect(await store.confirmedFiles(DEVICE)).toEqual([])
  })

  it('does not dispatch old operations under a replacement Device identity', async () => {
    const { draft } = await enqueue(); const h = harness()
    await credentials.save({ deviceUuid: OTHER_DEVICE, credential: 'another-synthetic-secret', credentialVersion: 1 })
    h.api.context = vi.fn(async () => ({ ...context, device: { ...context.device, uuid: OTHER_DEVICE } }))
    await h.sync.syncNow()
    expect(h.api.createTicket).not.toHaveBeenCalled()
    expect((await store.operation(draft.localUuid, DEVICE))?.status).toBe('PENDING')
  })

  it('recovers purge after its file removal succeeded but the local purge receipt failed', async () => {
    const { evidence } = await enqueue(); const h = harness()
    const original = store.markPurged.bind(store)
    vi.spyOn(store, 'markPurged').mockRejectedValueOnce(new Error('Synthetic SQLite failure')).mockImplementation(original)
    await h.sync.syncNow()
    expect((await store.getEvidence(evidence.localUuid, DEVICE))?.purgedAt).toBeNull()
    await h.sync.syncNow()
    expect((await store.getEvidence(evidence.localUuid, DEVICE))?.purgedAt).toBeTruthy()
    expect(h.api.uploadEvidence).toHaveBeenCalledOnce()
  })
})
