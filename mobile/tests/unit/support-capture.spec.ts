import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AppPlugin } from '@capacitor/app'
import type { CameraPlugin } from '@capacitor/camera'
import { MemoryCredentialStore } from '@/security/DeviceCredentialStore'
import { SqliteSupportStore } from '@/support/SqliteSupportStore'
import { SupportCaptureService } from '@/support/SupportCaptureService'
import type { EvidenceFiles } from '@/support/PrivateEvidenceFiles'
import { context, DEVICE, input, OTHER_DEVICE, sqliteFixture } from './support-fixtures'

describe('support camera ownership and restart recovery', () => {
  let fixture: Awaited<ReturnType<typeof sqliteFixture>>
  let store: SqliteSupportStore
  let credentials: MemoryCredentialStore
  beforeEach(async () => {
    fixture = await sqliteFixture(); store = new SqliteSupportStore(fixture.open); await store.initialize(); await store.saveContext(context)
    credentials = new MemoryCredentialStore(); await credentials.save({ deviceUuid: DEVICE, credential: 'synthetic-secret', credentialVersion: 1 })
  })
  afterEach(async () => { await fixture.dispose() })
  function service(camera: Partial<CameraPlugin> = {}, files: Partial<EvidenceFiles> = {}) {
    return new SupportCaptureService(store, files as EvidenceFiles, credentials,
      { capture: vi.fn(async () => { throw new Error('GPS unavailable') }) }, camera as CameraPlugin,
      { addListener: vi.fn(async () => ({ remove: vi.fn() })) } as unknown as AppPlugin)
  }
  const file = (uuid: string) => ({ path: `support-evidence/${uuid}.jpg`, mime: 'image/jpeg', sizeBytes: 4, uploadSha256: 'a'.repeat(64) })

  it('continues reporting when fresh GPS is unavailable', async () => {
    expect(await service().captureLocation()).toBeNull()
    const draft = await store.createDraft(DEVICE, input)
    await store.submit(draft.localUuid, DEVICE)
    expect((await store.operation(draft.localUuid, DEVICE))?.payload.location).toBeUndefined()
  })

  it('commits a pending owner before opening Camera and never requests base64 or gallery storage', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const camera = { getPhoto: vi.fn(async options => {
      expect(await store.pendingCapture()).toMatchObject({ ticketLocalUuid: draft.localUuid, deviceUuid: DEVICE })
      expect(options).toMatchObject({ source: 'CAMERA', resultType: 'uri', saveToGallery: false })
      return { path: 'file:///private/camera.jpg', format: 'jpeg', saved: false, exif: { secret: 'discarded' }, base64String: 'discarded' }
    }) }
    const capture = service(camera, { preserve: vi.fn(async (_path, uuid) => file(uuid)) })
    const evidence = await capture.capture(draft.localUuid)
    expect(evidence.state).toBe('READY')
    expect(JSON.stringify(evidence)).not.toMatch(/base64|exif|discarded/)
  })

  it('restores a Camera result into its original ticket after a real database reopen', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const pending = await store.reserveCapture(draft.localUuid, DEVICE)
    await fixture.closeConnections(); store = new SqliteSupportStore(fixture.open); await store.initialize()
    const capture = service({}, { preserve: vi.fn(async (_path, uuid) => file(uuid)) })
    await capture.restore({ pluginId: 'Camera', methodName: 'getPhoto', success: true, data: { path: 'file:///private/restored.jpg', format: 'jpeg', saved: false } })
    expect((await store.getEvidence(pending.localUuid, DEVICE))?.state).toBe('READY')
    expect((await store.evidenceForTicket(draft.localUuid, DEVICE))).toHaveLength(1)
    expect(await store.pendingCapture()).toBeNull()
  })

  it('retains the source pointer on copy failure and recovers a durable copy later', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const capture = service({ getPhoto: vi.fn(async () => ({ path: 'file:///private/retry.jpg', format: 'jpeg', saved: false })) },
      { preserve: vi.fn(async () => { throw new Error('disk temporarily unavailable') }) })
    await expect(capture.capture(draft.localUuid)).rejects.toThrow()
    const pending = (await store.pendingCapture())!
    expect(pending.sourcePath).toBe('file:///private/retry.jpg')
    const restored = service({}, { recover: vi.fn(async item => file(item.localUuid)) })
    await restored.recoverPending()
    expect((await store.getEvidence(pending.localUuid, DEVICE))?.state).toBe('READY')
  })

  it('does not assign an old restored photo to a new Device identity or unrelated plugin result', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const pending = await store.reserveCapture(draft.localUuid, DEVICE)
    const preserve = vi.fn()
    const capture = service({}, { preserve })
    await capture.restore({ pluginId: 'Other', methodName: 'getPhoto', success: true })
    expect(preserve).not.toHaveBeenCalled()
    await credentials.save({ deviceUuid: OTHER_DEVICE, credential: 'replacement-secret', credentialVersion: 1 })
    await expect(capture.restore({ pluginId: 'Camera', methodName: 'getPhoto', success: true, data: {} })).rejects.toMatchObject({ code: 'IDENTITY_CHANGED' })
    expect((await store.getEvidence(pending.localUuid, DEVICE))?.state).toBe('CAPTURING')
    expect(preserve).not.toHaveBeenCalled()
  })

  it('cancels a failed native capture without deleting an evidence file', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const purge = vi.fn()
    const capture = service({ getPhoto: vi.fn(async () => { throw new Error('cancelled') }) }, { purgeConfirmed: purge })
    await expect(capture.capture(draft.localUuid)).rejects.toMatchObject({ code: 'CAMERA_CANCELLED' })
    expect(await store.pendingCapture()).toBeNull()
    expect(purge).not.toHaveBeenCalled()
    await store.submit(draft.localUuid, DEVICE)
  })

  it('cleans only the redundant Camera source after READY commits and durably forgets that pointer', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const cleanup = vi.fn(async item => {
      expect((await store.getEvidence(item.localUuid, DEVICE))?.state).toBe('READY')
      expect(item.path).toBe(`support-evidence/${item.localUuid}.jpg`)
      return true
    })
    const capture = service({ getPhoto: vi.fn(async () => ({ path: 'file:///owned/camera.jpg', format: 'jpeg', saved: false })) },
      { preserve: vi.fn(async (_path, uuid) => file(uuid)), cleanupCameraSource: cleanup })
    const evidence = await capture.capture(draft.localUuid)
    expect(cleanup).toHaveBeenCalledOnce()
    expect(evidence.sourcePath).toBeNull()
    expect(evidence.state).toBe('READY')
    expect(evidence.purgedAt).toBeNull()
    await fixture.closeConnections(); store = new SqliteSupportStore(fixture.open); await store.initialize()
    expect((await store.getEvidence(evidence.localUuid, DEVICE))?.sourcePath).toBeNull()
  })

  it('retains the durable source pointer for retry if temporary cleanup fails after READY', async () => {
    const draft = await store.createDraft(DEVICE, input)
    const capture = service({ getPhoto: vi.fn(async () => ({ path: 'file:///owned/camera.jpg', format: 'jpeg', saved: false })) },
      { preserve: vi.fn(async (_path, uuid) => file(uuid)), cleanupCameraSource: vi.fn(async () => { throw new Error('temporary native failure') }) })
    const evidence = await capture.capture(draft.localUuid)
    expect(evidence).toMatchObject({ state: 'READY', sourcePath: 'file:///owned/camera.jpg', purgedAt: null })
    expect(await store.cameraSources(DEVICE)).toHaveLength(1)
    expect(await store.cameraSources(OTHER_DEVICE)).toHaveLength(0)
  })
})
