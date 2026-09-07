import { describe, expect, it, vi } from 'vitest'
import { Directory, type FilesystemPlugin } from '@capacitor/filesystem'
import { PrivateEvidenceFiles } from '@/support/PrivateEvidenceFiles'
import { hashBytes } from '@/support/SupportApiClient'
import type { LocalEvidence } from '@/support/types'
import { DEVICE, EVIDENCE, TICKET } from './support-fixtures'

const jpeg = new Uint8Array([255, 216, 255, 217])
const source = 'file:///private/test-camera.jpg'
const path = `support-evidence/${EVIDENCE}.jpg`
function nativeFiles(initial = jpeg) {
  const files = new Map<string, Uint8Array>([[source, initial]])
  let directory = false
  const absent = () => { throw { code: 'OS-PLUG-FILE-0008' } }
  const fs = {
    stat: vi.fn(async ({ path: requested, directory: dir }) => {
      if (requested !== source) expect(dir).toBe(Directory.Data)
      if (requested === 'support-evidence') { if (!directory) return absent(); return { type: 'directory', size: 0 } }
      const bytes = files.get(requested)
      if (!bytes) return absent()
      return { type: 'file', size: bytes.length }
    }),
    mkdir: vi.fn(async options => { expect(options).toMatchObject({ directory: Directory.Data, path: 'support-evidence' }); directory = true }),
    copy: vi.fn(async options => { expect(options.toDirectory).toBe(Directory.Data); files.set(options.to, Uint8Array.from(files.get(options.from)!)) }),
    rename: vi.fn(async options => {
      expect(options.directory).toBe(Directory.Data); expect(options.toDirectory).toBe(Directory.Data)
      const content = files.get(options.from); if (!content) return absent()
      files.set(options.to, content); files.delete(options.from)
    }),
    readFile: vi.fn(async options => {
      expect(options.directory).toBe(Directory.Data)
      const bytes = files.get(options.path); if (!bytes) return absent()
      return { data: btoa(String.fromCharCode(...bytes)) }
    }),
    deleteFile: vi.fn(async options => {
      expect(options.directory).toBe(Directory.Data)
      if (!files.has(options.path)) return absent()
      files.delete(options.path)
    }),
    getUri: vi.fn(async options => ({ uri: `file:///app-private/${options.path}` })),
  }
  return { files, fs, adapter: new PrivateEvidenceFiles(fs as unknown as FilesystemPlugin, () => true) }
}
async function local(): Promise<LocalEvidence> {
  return { localUuid: EVIDENCE, ticketLocalUuid: TICKET, deviceUuid: DEVICE, capturedAt: '2026-09-07T12:00:00Z',
    sourcePath: source, path, mime: 'image/jpeg', sizeBytes: jpeg.length, uploadSha256: await hashBytes(jpeg), state: 'READY', serverUuid: null, purgedAt: null }
}

describe('private support evidence adapter', () => {
  it('copies a native URI to private Data, confirms bytes/hash and retains the durable file before any upload', async () => {
    const h = nativeFiles()
    const evidence = await h.adapter.preserve(source, EVIDENCE, 100)
    expect(evidence).toEqual({ path, mime: 'image/jpeg', sizeBytes: 4, uploadSha256: await hashBytes(jpeg) })
    expect(h.files.has(path)).toBe(true)
    expect(evidence).not.toHaveProperty('data')
    expect(evidence).not.toHaveProperty('base64')
    expect(h.fs.copy).toHaveBeenCalledWith({ from: source, to: `${path}.pending`, toDirectory: Directory.Data })
    expect(await h.adapter.read(await local())).toEqual(jpeg)
  })

  it('refuses oversize before copy and rejects a MIME-spoofed non-JPEG camera file', async () => {
    const large = nativeFiles(new Uint8Array(101))
    await expect(large.adapter.preserve(source, EVIDENCE, 100)).rejects.toMatchObject({ code: 'EVIDENCE_TOO_LARGE' })
    expect(large.fs.copy).not.toHaveBeenCalled()
    const spoof = nativeFiles(new TextEncoder().encode('<script>bad</script>'))
    await expect(spoof.adapter.preserve(source, EVIDENCE, 100)).rejects.toMatchObject({ code: 'INVALID_IMAGE' })
    expect(spoof.fs.rename).not.toHaveBeenCalled()
  })

  it('recovers the already-copied bytes after restart without overwriting them', async () => {
    const h = nativeFiles()
    await h.adapter.preserve(source, EVIDENCE, 100)
    h.files.set(source, new Uint8Array([255, 216, 255, 0]))
    const recovered = await h.adapter.recover({ ...await local(), state: 'CAPTURING', path: null }, 100)
    expect(recovered?.uploadSha256).toBe(await hashBytes(jpeg))
    expect(h.fs.copy).toHaveBeenCalledOnce()
  })

  it('detects same-length changed evidence before upload', async () => {
    const h = nativeFiles(); await h.adapter.preserve(source, EVIDENCE, 100)
    h.files.set(path, new Uint8Array([255, 216, 255, 0]))
    await expect(h.adapter.read(await local())).rejects.toMatchObject({ code: 'EVIDENCE_CHANGED' })
  })
  it('recovers a complete private staging file even if Android already removed the camera source cache', async () => {
    const h = nativeFiles()
    h.files.set(`${path}.pending`, jpeg)
    h.files.delete(source)
    const result = await h.adapter.recover({ ...await local(), state: 'CAPTURING', path: null }, 100)
    expect(result?.uploadSha256).toBe(await hashBytes(jpeg))
    expect(h.files.has(path)).toBe(true)
    expect(h.fs.copy).not.toHaveBeenCalled()
    expect(h.fs.deleteFile).not.toHaveBeenCalled()
  })

  it('refuses deletion before server confirmation and makes confirmed cleanup retry-safe', async () => {
    const h = nativeFiles(); await h.adapter.preserve(source, EVIDENCE, 100)
    await expect(h.adapter.purgeConfirmed(await local())).rejects.toMatchObject({ code: 'EVIDENCE_UNCONFIRMED' })
    expect(h.fs.deleteFile).not.toHaveBeenCalled()
    const confirmed: LocalEvidence = { ...await local(), state: 'CONFIRMED', serverUuid: EVIDENCE }
    await h.adapter.purgeConfirmed(confirmed)
    await h.adapter.purgeConfirmed(confirmed)
    expect(h.files.has(path)).toBe(false)
  })

  it('rejects public or traversal paths and refuses browser persistence', async () => {
    const h = nativeFiles()
    await expect(h.adapter.purgeConfirmed({ ...await local(), state: 'CONFIRMED', serverUuid: EVIDENCE, path: '../../Downloads/private.jpg' })).rejects.toMatchObject({ code: 'EVIDENCE_UNCONFIRMED' })
    expect(h.fs.deleteFile).not.toHaveBeenCalled()
    const browser = new PrivateEvidenceFiles(h.fs as unknown as FilesystemPlugin, () => false)
    await expect(browser.preserve(source, EVIDENCE, 100)).rejects.toMatchObject({ code: 'NATIVE_REQUIRED' })
    expect(h.fs.copy).not.toHaveBeenCalled()
  })

  it('removes only a referenced generated Camera source after verifying a committed private copy', async () => {
    const h = nativeFiles(); await h.adapter.preserve(source, EVIDENCE, 100)
    const cameraName = 'JPEG_20260907_120000_123456789.jpg'
    const externalSource = `file:///app-owned/Pictures/${cameraName}`
    h.fs.getUri.mockImplementation(async options => ({ uri: options.directory === Directory.External ? 'file:///app-owned/Pictures' : 'file:///private-cache' }))
    h.fs.stat.mockImplementation(async options => {
      if (options.directory === Directory.External) { expect(options.path).toBe(`Pictures/${cameraName}`); return { type: 'file', size: 4 } }
      expect(options.directory).toBe(Directory.Data); return { type: 'file', size: 4 }
    })
    h.fs.deleteFile.mockImplementation(async options => {
      expect(options).toEqual({ directory: Directory.External, path: `Pictures/${cameraName}` })
    })
    const evidence = { ...await local(), sourcePath: externalSource }
    expect(await h.adapter.cleanupCameraSource(evidence)).toBe(true)
    expect(h.fs.readFile).toHaveBeenCalledWith({ directory: Directory.Data, path })
    expect(h.files.has(path)).toBe(true)
    expect(h.fs.deleteFile).toHaveBeenCalledOnce()
  })

  it('never cleans a source before durable metadata or when its private copy was lost or altered', async () => {
    const h = nativeFiles(); await h.adapter.preserve(source, EVIDENCE, 100)
    h.fs.getUri.mockResolvedValue({ uri: 'file:///app-owned/Pictures' })
    h.fs.stat.mockResolvedValue({ type: 'file', size: 4 })
    const evidence = { ...await local(), sourcePath: 'file:///app-owned/Pictures/JPEG_20260907_120000_123456789.jpg' }
    expect(await h.adapter.cleanupCameraSource({ ...evidence, state: 'CAPTURING' })).toBe(false)
    h.files.set(path, new Uint8Array([255, 216, 0, 217]))
    await expect(h.adapter.cleanupCameraSource(evidence)).rejects.toMatchObject({ code: 'EVIDENCE_CHANGED' })
    expect(h.fs.deleteFile).not.toHaveBeenCalled()
  })

  it.each([
    'file:///shared/Pictures/JPEG_20260907_120000_123.jpg',
    'file:///app-owned/Pictures/../JPEG_20260907_120000_123.jpg',
    'file:///app-owned/Pictures/%2e%2e/JPEG_20260907_120000_123.jpg',
    'file:///app-owned/Pictures/photo.jpg',
    'file:///app-owned/Pictures/nested/JPEG_20260907_120000_123.jpg',
    'content://gallery/JPEG_20260907_120000_123.jpg',
  ])('refuses Camera source cleanup outside the exact generated file contract: %s', async unsafe => {
    const h = nativeFiles()
    h.fs.getUri.mockResolvedValue({ uri: 'file:///app-owned/Pictures' })
    expect(await h.adapter.cleanupCameraSource({ ...await local(), sourcePath: unsafe })).toBe(false)
    expect(h.fs.deleteFile).not.toHaveBeenCalled()
    expect(h.fs.readFile).not.toHaveBeenCalled()
  })
})
