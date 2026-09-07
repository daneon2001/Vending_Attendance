import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { SqliteSupportStore, SUPPORT_DATABASE_NAME } from '@/support/SqliteSupportStore'
import { hashBytes } from '@/support/SupportApiClient'
import { context, DEVICE, OTHER_DEVICE, EVIDENCE, input, serverTicket, sqliteFixture, TICKET } from './support-fixtures'
import type { LocalEvidence, SupportChanges, SupportOperation } from '@/support/types'

describe('support durable SQLite projection', () => {
  let fixture: Awaited<ReturnType<typeof sqliteFixture>>
  let store: SqliteSupportStore
  beforeEach(async () => { fixture = await sqliteFixture(); store = new SqliteSupportStore(fixture.open); await store.initialize(); await store.saveContext(context) })
  afterEach(async () => { await fixture.dispose() })

  async function reportWithPhoto() {
    const draft = await store.createDraft(DEVICE, input)
    const photo = await store.reserveCapture(draft.localUuid, DEVICE)
    await store.saveCaptureSource(photo.localUuid, DEVICE, 'file:///private/camera-test.jpg')
    const file = { path: `support-evidence/${photo.localUuid}.jpg`, mime: 'image/jpeg', sizeBytes: 4, uploadSha256: await hashBytes(new Uint8Array([255, 216, 255, 217])) }
    await store.completeCapture(photo.localUuid, DEVICE, file)
    return { draft, photo, file }
  }

  it('reopens a real on-disk SQLite database with the same report, captured timestamp and evidence metadata', async () => {
    const { draft, photo, file } = await reportWithPhoto()
    await store.submit(draft.localUuid, DEVICE)
    await fixture.closeConnections()
    const reopened = new SqliteSupportStore(fixture.open)
    await reopened.initialize()
    expect((await reopened.getTicket(draft.localUuid, DEVICE))?.payload).toEqual(input)
    expect(await reopened.getEvidence(photo.localUuid, DEVICE)).toMatchObject({ ...file, capturedAt: photo.capturedAt, deviceUuid: DEVICE, state: 'READY' })
    expect((await reopened.claim(DEVICE))?.uuid).toBe(draft.localUuid)
  })

  it('persists the camera ownership marker/source across a process-like connection close before file confirmation', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const pending = await store.reserveCapture(draft.localUuid, DEVICE)
    await store.saveCaptureSource(pending.localUuid, DEVICE, 'file:///private/recover.jpg')
    await fixture.closeConnections()
    const reopened = new SqliteSupportStore(fixture.open)
    await reopened.initialize()
    expect(await reopened.pendingCapture()).toMatchObject({ localUuid: pending.localUuid, ticketLocalUuid: draft.localUuid, deviceUuid: DEVICE, sourcePath: 'file:///private/recover.jpg' })
    await expect(reopened.submit(draft.localUuid, DEVICE)).rejects.toMatchObject({ code: 'CAPTURE_PENDING' })
  })

  it('rolls back submission and every dependent operation if the evidence enqueue fails', async () => {
    const { draft, photo } = await reportWithPhoto()
    const db = await fixture.open()
    await db.execute("CREATE TRIGGER fail_upload BEFORE INSERT ON support_operations WHEN NEW.kind='UPLOAD_EVIDENCE' BEGIN SELECT RAISE(ABORT,'synthetic enqueue failure'); END;")
    await expect(store.submit(draft.localUuid, DEVICE)).rejects.toThrow('synthetic enqueue failure')
    expect((await store.getTicket(draft.localUuid, DEVICE))?.status).toBe('DRAFT')
    expect(await store.operation(draft.localUuid, DEVICE)).toBeNull()
    expect(await store.operation(photo.localUuid, DEVICE)).toBeNull()
    expect((await store.getEvidence(photo.localUuid, DEVICE))?.state).toBe('READY')
  })

  it('requires ticket ACK then evidence reservation then upload ACK; repeated submit creates no additional intent', async () => {
    const { draft, photo } = await reportWithPhoto()
    await Promise.all([store.submit(draft.localUuid, DEVICE), store.submit(draft.localUuid, DEVICE)])
    const [first, second] = await Promise.all([store.claim(DEVICE), store.claim(DEVICE)])
    expect([first, second].filter(Boolean)).toHaveLength(1)
    const create = (first ?? second)!
    expect(create.kind).toBe('CREATE_TICKET')
    expect(await store.claim(DEVICE)).toBeNull()
    await store.acknowledge(create, { ticket: serverTicket })
    const reserve = (await store.claim(DEVICE))!
    expect(reserve.kind).toBe('RESERVE_EVIDENCE')
    await store.acknowledge(reserve, { evidence: { uuid: EVIDENCE, status: 'PENDING' } })
    const upload = (await store.claim(DEVICE))!
    expect(upload.kind).toBe('UPLOAD_EVIDENCE')
    expect(await store.confirmedFiles(DEVICE)).toEqual([])
    await expect(store.acknowledge(upload, { evidence: { uuid: EVIDENCE, status: 'PENDING' } })).rejects.toMatchObject({ code: 'UNCONFIRMED_EVIDENCE' })
    expect((await store.operation(upload.uuid, DEVICE))?.status).toBe('SENDING')
    await store.acknowledge(upload, { evidence: { uuid: EVIDENCE, status: 'CONFIRMED' } })
    expect(await store.confirmedFiles(DEVICE)).toHaveLength(1)
    await store.markPurged(photo.localUuid, DEVICE)
    expect(await store.confirmedFiles(DEVICE)).toEqual([])
    expect(await store.claim(DEVICE)).toBeNull()
  })

  it('recovers an interrupted sender with original operation UUID/payload and durable backoff', async () => {
    const draft = await store.createDraft(DEVICE, input)
    await store.submit(draft.localUuid, DEVICE)
    const claimed = (await store.claim(DEVICE))!
    await fixture.closeConnections()
    const reopened = new SqliteSupportStore(fixture.open)
    await reopened.initialize()
    const recovered = await reopened.operation(claimed.uuid, DEVICE)
    expect(recovered).toMatchObject({ uuid: claimed.uuid, payload: claimed.payload, status: 'PENDING', retryCount: 1, errorCode: 'PROCESS_INTERRUPTED' })
    expect(await reopened.claim(DEVICE)).toBeNull()
    const db = await fixture.open()
    await db.run('UPDATE support_operations SET next_attempt_at=? WHERE uuid=?', ['2000-01-01T00:00:00Z', claimed.uuid])
    expect((await reopened.claim(DEVICE))?.uuid).toBe(claimed.uuid)
  })

  it('keeps cross-device reads and claims empty and denies cross-device mutation', async () => {
    const { draft, photo } = await reportWithPhoto()
    await store.submit(draft.localUuid, DEVICE)
    expect(await store.listTickets(OTHER_DEVICE)).toEqual([])
    expect(await store.getTicket(draft.localUuid, OTHER_DEVICE)).toBeNull()
    expect(await store.getEvidence(photo.localUuid, OTHER_DEVICE)).toBeNull()
    expect(await store.claim(OTHER_DEVICE)).toBeNull()
    await expect(store.queueComment(draft.localUuid, OTHER_DEVICE, 'forged')).rejects.toMatchObject({ code: 'REPORT_NOT_FOUND' })
    expect((await store.getTicket(draft.localUuid, DEVICE))?.payload).toEqual(input)
  })

  it('retains evidence and blocks dependencies after definitive rejection', async () => {
    const { draft, photo } = await reportWithPhoto()
    await store.submit(draft.localUuid, DEVICE)
    const claimed = (await store.claim(DEVICE))!
    await store.fail(claimed, 'VALIDATION_FAILED', false)
    expect(await store.claim(DEVICE)).toBeNull()
    expect((await store.operation(photo.localUuid, DEVICE))?.status).toBe('BLOCKED')
    expect((await store.getEvidence(photo.localUuid, DEVICE))?.path).toBeTruthy()
    expect(await store.confirmedFiles(DEVICE)).toEqual([])
  })

  it('stores explicit retry-after and no error message or credential payload', async () => {
    const draft = await store.createDraft(DEVICE, { ...input, credential: 'never-save-this' } as typeof input)
    await store.submit(draft.localUuid, DEVICE)
    const claimed = (await store.claim(DEVICE))!
    const before = Date.now()
    await store.fail(claimed, 'RATE_LIMITED', true, 900)
    const operation = (await store.operation(claimed.uuid, DEVICE))!
    expect(Date.parse(operation.nextAttemptAt!) - before).toBeGreaterThanOrEqual(900_000)
    expect(JSON.stringify(operation)).not.toContain('never-save-this')
    expect(operation.status).toBe('PENDING')
    expect(SUPPORT_DATABASE_NAME).toBe('vending_support')
    const db = await fixture.open()
    const names = (await db.query("SELECT name FROM sqlite_master WHERE type='table'")).values.map((row: { name: string }) => row.name)
    expect(names).not.toContain('attendance_events')
    expect(names).not.toContain('sync_outbox')
  })

  it('atomically applies feed snapshots, notification and cursor; duplicate delivery preserves read state', async () => {
    const eventUuid = crypto.randomUUID()
    const page: SupportChanges = { events: [{ uuid: eventUuid, sequence: 1, ticket_uuid: TICKET, kind: 'STATUS_CHANGED', created_at: input.reported_at, ticket: { ...serverTicket, status: 'IN_PROGRESS' } }], next_sequence: 1, has_more: false }
    await store.applyChanges(DEVICE, page)
    await store.markRead(DEVICE, eventUuid)
    await store.applyChanges(DEVICE, page)
    expect(await store.getCursor(DEVICE)).toBe(1)
    expect(await store.notifications(DEVICE)).toHaveLength(1)
    expect((await store.notifications(DEVICE))[0].read).toBe(true)
    expect((await store.listTickets(DEVICE))[0].server?.status).toBe('IN_PROGRESS')
    expect(await store.notifications(OTHER_DEVICE)).toEqual([])
    const invalid: SupportChanges = { events: [{ ...page.events[0], uuid: crypto.randomUUID(), sequence: 2 }, { ...page.events[0], uuid: crypto.randomUUID(), sequence: 2 }], next_sequence: 2, has_more: false }
    await expect(store.applyChanges(DEVICE, invalid)).rejects.toMatchObject({ code: 'INVALID_FEED' })
    expect(await store.getCursor(DEVICE)).toBe(1)
    expect(await store.notifications(DEVICE)).toHaveLength(1)
  })

  it('queues a bounded allowlisted verification and rejects secret-like diagnostic dumps', async () => {
    const verification = { started_at: input.reported_at, completed_at: input.reported_at, checks: [{ code: 'NETWORK', result: 'WARNING' as const, details: { state: 'OFFLINE' } }] }
    const uuid = await store.queueVerification(DEVICE, verification)
    expect((await store.operation(uuid, DEVICE))?.payload).toEqual({ client_operation_uuid: uuid, captured_machine_uuid: context.machine.uuid, ...verification })
    await expect(store.queueVerification(DEVICE, { ...verification, checks: [{ code: 'NETWORK', result: 'WARNING', details: { token: 'sensitive' } }] })).rejects.toMatchObject({ code: 'INVALID_VERIFICATION' })
  })

  it('archives the old machine projection without deleting it and restarts only the support feed cursor', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const event = { uuid: crypto.randomUUID(), sequence: 42, ticket_uuid: TICKET, kind: 'STATUS_CHANGED', created_at: input.reported_at, ticket: { ...serverTicket, machine: context.machine } }
    await store.applyChanges(DEVICE, { events: [event], next_sequence: 42, has_more: false })
    expect(await store.notifications(DEVICE)).toHaveLength(1)
    await store.saveContext({ ...context, machine: { ...context.machine, uuid: OTHER_DEVICE } })
    expect(await store.getCursor(DEVICE)).toBe(0)
    expect(await store.notifications(DEVICE)).toHaveLength(0)
    expect(await store.getTicket(draft.localUuid, DEVICE)).toMatchObject({ machineUuid: context.machine.uuid, status: 'DRAFT' })
    await expect(store.updateDraft(draft.localUuid, DEVICE, input)).rejects.toMatchObject({ code: 'MACHINE_CHANGED' })
    await expect(store.reserveCapture(draft.localUuid, DEVICE)).rejects.toMatchObject({ code: 'MACHINE_CHANGED' })
    await expect(store.applyChanges(DEVICE, { events: [event], next_sequence: 42, has_more: false })).rejects.toMatchObject({ code: 'INVALID_FEED' })
    expect(await store.getCursor(DEVICE)).toBe(0)
  })
})
