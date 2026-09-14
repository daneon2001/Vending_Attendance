import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SqliteSupportStore } from '@/support/SqliteSupportStore'
import { FieldOfflineStore, fieldDigest, type FieldCapture } from '@/fieldSupport/FieldOfflineStore'
import { FieldOfflineSupport } from '@/fieldSupport/FieldOfflineSupport'
import { FieldMobileError, type HumanSession } from '@/fieldIdentity/FieldMobileTransport'
import type { Activity, Operation } from '@/fieldSupport/FieldActivityFlow'
import type { EvidenceFiles } from '@/support/PrivateEvidenceFiles'
import { DEVICE, OTHER_DEVICE, TICKET, EVIDENCE, sqliteFixture } from './support-fixtures'

vi.mock('@capacitor/app', () => ({ App: { addListener: vi.fn(async () => ({ remove: vi.fn() })) } }))
const camera = vi.hoisted(() => ({ getPhoto: vi.fn() }))
vi.mock('@capacitor/camera', () => ({ Camera: camera, CameraResultType: { Uri: 'uri' }, CameraSource: { Camera: 'CAMERA' } }))

describe('Field Support durable offline queue', () => {
  let fixture: Awaited<ReturnType<typeof sqliteFixture>>, shared: SqliteSupportStore, store: FieldOfflineStore
  let service: FieldOfflineSupport, online: boolean, session: HumanSession | null
  const services: FieldOfflineSupport[] = []
  const bytes = new Uint8Array([255, 216, 255, 217])
  const activity: Activity = { uuid: TICKET, title: 'Synthetic activity', status: 'IN_PROGRESS', type_label: 'Mantenimiento', employee: 'Fixture', machine: 'TEST',
    contribution_policy: { available: true, max_note_length: 4000, max_notes: 100, max_count: 5, max_size_bytes: 1000000, allowed_mimes: ['image/jpeg'] }, notes: [], evidence: [] }
  let files: EvidenceFiles, calls: Operation[], fail: number, mismatch: boolean
  const keys = { createKey: vi.fn(), sign: vi.fn(async () => ({ signature: 'synthetic signature' })) }
  const make = () => {
    const api = { origin: 'https://field.test', post: vi.fn(async (action: string, input: Record<string, unknown>) => {
      if (!online) throw new FieldMobileError(0)
      if (action === 'activities/capability') return { available: true, device_uuid: DEVICE }
      if (action === 'activities/challenge') return { challenge_uuid: crypto.randomUUID(), message: 'FIELD_MOBILE_V1\n{}' }
      const op = input.operation as Operation; calls.push(structuredClone(op))
      if (fail) throw new FieldMobileError(fail)
      return { confirmed: true, confirmed_status: op.action === 'complete' ? 'COMPLETED' : 'IN_PROGRESS', operation_uuid: op.operation_uuid,
        activity: { ...activity, status: op.action === 'complete' ? 'COMPLETED' : 'IN_PROGRESS' },
        receipt: { uuid: op.operation_uuid, kind: op.action, status: 'CONFIRMED', upload_sha256: mismatch ? 'wrong' : op.evidence?.upload_sha256, sha256: 'a'.repeat(64) } }
    }) }
    const value = new FieldOfflineSupport(store, { session: async () => session, saveSession: async value => { session = value } }, api as never, keys, files,
      { current: () => online ? 'ONLINE' : 'OFFLINE', subscribe: () => () => {} })
    services.push(value); return value
  }
  beforeEach(async () => {
    fixture = await sqliteFixture(); shared = new SqliteSupportStore(fixture.open); store = new FieldOfflineStore(shared)
    online = true; calls = []; fail = 0; mismatch = false
    session = { token: 'synthetic local session', origin: 'https://field.test', expires_at: new Date(Date.now() + 3600000).toISOString() }
    const file = { path: `support-evidence/${EVIDENCE}.jpg`, mime: 'image/jpeg', sizeBytes: bytes.length, uploadSha256: await fieldDigest('synthetic') }
    files = { read: vi.fn(async () => bytes), preserve: vi.fn(async () => file), recover: vi.fn(async evidence => evidence.sourcePath ? { ...file, path: `support-evidence/${evidence.localUuid}.jpg` } : null),
      preview: vi.fn(async () => 'private-preview'), cleanupCameraSource: vi.fn(async () => false), purgeConfirmed: vi.fn() }
    service = make(); await service.prepare(); await service.cache([activity]); camera.getPhoto.mockResolvedValue({ path: 'file:///private/synthetic.jpg', format: 'jpeg' })
  })
  afterEach(async () => { await Promise.all(services.splice(0).map(value => value.stop())); await fixture.dispose() })
  async function reopen() {
    await service.stop(); await fixture.closeConnections()
    shared = new SqliteSupportStore(fixture.open); store = new FieldOfflineStore(shared); service = make(); await service.prepare()
  }
  async function photo(): Promise<FieldCapture> {
    await service.capture(activity)
    return (await service.captures())[0]!
  }
  it('keeps note/photo offline across actual SQLite close/reopen; reconnect confirms in order without attendance queue', async () => {
    online = false
    await service.note(activity, 'Nota offline sintética')
    const captured = await photo(); expect(calls).toHaveLength(0)
    expect(captured.preview).toBe(true); expect(await service.pending()).toHaveLength(1)
    await service.confirmPhoto(captured)
    const original = (await service.pending()).map(item => item.payload)
    await reopen()
    expect((await service.pending()).map(item => item.payload)).toEqual(original)
    expect((await service.captures())[0]?.evidence.path).toBe(captured.evidence.path)
    expect((await service.cached(TICKET))[0]?.status).toBe('IN_PROGRESS')
    expect(await shared.claim(DEVICE)).toBeNull()
    online = true; await service.sync()
    expect(calls.map(item => item.action)).toEqual(['note', 'evidence'])
    expect(await service.pending()).toHaveLength(0)
    expect((await service.captures())[0]?.confirmed).toBe(true)
    expect(files.purgeConfirmed).not.toHaveBeenCalled()
    expect(keys.createKey).not.toHaveBeenCalled()
  })
  it('does not complete optimistically and uses START_ONLY_V1 without final GPS', async () => {
    online = false; await service.note(activity, 'Antes de finalizar'); await service.complete(activity)
    expect((await service.cached(TICKET))[0]?.status).toBe('IN_PROGRESS')
    expect((await service.pending()).map(item => item.kind)).toEqual(['SUPPORT_NOTE_CREATE', 'SUPPORT_ACTIVITY_COMPLETE'])
    online = true; await service.sync()
    expect((await service.cached(TICKET))[0]?.status).toBe('COMPLETED')
    expect(calls[1]?.location).toBeUndefined()
  })
  it('retains photo and same operation UUID after lost response/restart; generates new proof on retry', async () => {
    online = false; const captured = await photo(); await service.confirmPhoto(captured)
    online = true; fail = 503; await service.sync()
    expect((await service.pending())[0]?.status).toBe('PENDING')
    expect((await service.captures())[0]?.confirmed).toBe(false)
    online = false; await reopen(); online = true; fail = 0
    await shared.withDatabase(async db => { await db.run("UPDATE support_operations SET next_attempt_at=NULL WHERE device_uuid=?", [service.scope!.key]) })
    await service.sync()
    expect(calls[0]).toEqual(calls[1]); expect(await service.pending()).toHaveLength(0)
  })
  it('rejects a forged hash receipt and never purges the local photograph', async () => {
    online = false; const captured = await photo(); await service.confirmPhoto(captured)
    mismatch = true; online = true; await service.sync()
    expect((await service.captures())[0]?.confirmed).toBe(false)
    expect(await service.pending()).toHaveLength(1); expect(files.purgeConfirmed).not.toHaveBeenCalled()
  })
  it('isolates cached content by human session and field device and rejects logged out access', async () => {
    online = false; await service.note(activity, 'Privada')
    const other = await store.bind('https://field.test', OTHER_DEVICE, 'other-session')
    expect(await store.activities(other)).toEqual([]); expect(await store.queued(other)).toEqual([])
    session = { ...session!, token: 'different session' }
    await expect(service.cached()).rejects.toMatchObject({ status: 401 })
    await expect(service.prepare()).rejects.toMatchObject({ status: 0 })
    session = null; await expect(service.prepare()).rejects.toMatchObject({ status: 401 })
  })
  it('revoked/unauthorized device keeps data blocked and never marks synchronized', async () => {
    online = false; await service.note(activity, 'Prueba'); await service.complete(activity)
    online = true; fail = 403; await service.sync()
    expect((await service.pending())[0]?.status).toBe('BLOCKED')
    expect((await service.cached(TICKET))[0]?.status).toBe('IN_PROGRESS')
  })
  it('preview discard never queues evidence; oversized/HTML notes are rejected', async () => {
    online = false; const captured = await photo(); await service.discardPhoto(captured)
    expect(await service.captures()).toEqual([]); expect(await service.pending()).toEqual([])
    await expect(service.note(activity, '<b>HTML</b>')).rejects.toMatchObject({ code: 'INVALID_NOTE' })
    await expect(service.note(activity, 'a'.repeat(4001))).rejects.toMatchObject({ code: 'INVALID_NOTE' })
  })
  it('cannot enqueue on an assigned/completed or uncached activity', async () => {
    online = false
    for (const status of ['ASSIGNED', 'COMPLETED']) {
      await service.cache([{ ...activity, status }])
      await expect(service.note(activity, 'No permitido')).rejects.toMatchObject({ code: 'ACTIVITY_NOT_STARTED' })
    }
    await expect(service.note({ ...activity, uuid: OTHER_DEVICE }, 'Otra actividad')).rejects.toMatchObject({ code: 'ACTIVITY_NOT_STARTED' })
  })
})
