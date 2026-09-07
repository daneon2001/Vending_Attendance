import { Capacitor } from '@capacitor/core'
import { Directory, Filesystem, type FilesystemPlugin } from '@capacitor/filesystem'
import { hashBytes } from './SupportApiClient'
import { assertUuid, SupportError, type EvidenceFile, type LocalEvidence } from './types'

export interface EvidenceFiles {
  preserve(sourcePath: string, evidenceUuid: string, maxBytes: number): Promise<EvidenceFile>
  recover(evidence: LocalEvidence, maxBytes: number): Promise<EvidenceFile | null>
  read(evidence: LocalEvidence): Promise<Uint8Array>
  cleanupCameraSource(evidence: LocalEvidence): Promise<boolean>
  purgeConfirmed(evidence: LocalEvidence): Promise<void>
  preview(evidence: LocalEvidence): Promise<string>
}

function missing(error: unknown): boolean {
  return typeof error === 'object' && error !== null && 'code' in error
    && ['OS-PLUG-FILE-0008', 'ENOENT'].includes(String(error.code))
}
function privatePath(uuid: string): string { return `support-evidence/${assertUuid(uuid)}.jpg` }

export class PrivateEvidenceFiles implements EvidenceFiles {
  constructor(private readonly fs: FilesystemPlugin = Filesystem, private readonly native = () => Capacitor.isNativePlatform()) {}

  private assertNative(): void {
    if (!this.native()) throw new SupportError('NATIVE_REQUIRED', 'Las fotografías requieren almacenamiento privado nativo.')
  }
  private async exists(path: string): Promise<boolean> {
    try { await this.fs.stat({ path, directory: Directory.Data }); return true }
    catch (error) { if (missing(error)) return false; throw error }
  }
  private async describe(path: string, maxBytes: number): Promise<EvidenceFile> {
    const stat = await this.fs.stat({ path, directory: Directory.Data })
    if (stat.type !== 'file' || stat.size < 1 || stat.size > maxBytes) throw new SupportError('EVIDENCE_TOO_LARGE', 'La fotografía supera el tamaño permitido.')
    const bytes = await this.readPath(path, maxBytes)
    if (bytes.length < 4 || bytes[0] !== 0xff || bytes[1] !== 0xd8 || bytes[2] !== 0xff || bytes[bytes.length - 2] !== 0xff || bytes[bytes.length - 1] !== 0xd9) throw new SupportError('INVALID_IMAGE', 'La cámara no entregó una fotografía JPEG válida.')
    return { path, sizeBytes: bytes.length, mime: 'image/jpeg', uploadSha256: await hashBytes(bytes) }
  }
  private async readPath(path: string, maxBytes: number): Promise<Uint8Array> {
    const { data } = await this.fs.readFile({ path, directory: Directory.Data })
    // Capacitor's native bridge transfers base64 transiently; neither DB nor browser storage receives it.
    if (typeof data !== 'string' || data.length > Math.ceil(maxBytes / 3) * 4 + 4) throw new SupportError('INVALID_IMAGE', 'No se pudo leer la fotografía.')
    let binary: string
    try { binary = atob(data) } catch { throw new SupportError('INVALID_IMAGE', 'No se pudo leer la fotografía.') }
    if (binary.length > maxBytes) throw new SupportError('EVIDENCE_TOO_LARGE', 'La fotografía supera el tamaño permitido.')
    return Uint8Array.from(binary, char => char.charCodeAt(0))
  }
  async preserve(sourcePath: string, evidenceUuid: string, maxBytes: number): Promise<EvidenceFile> {
    this.assertNative()
    if (!/^(file|content):\/\//.test(sourcePath)) throw new SupportError('INVALID_CAMERA_FILE', 'No se pudo conservar la fotografía.')
    const path = privatePath(evidenceUuid)
    if (!(await this.exists('support-evidence'))) await this.fs.mkdir({ path: 'support-evidence', directory: Directory.Data, recursive: true })
    if (!(await this.exists(path))) {
      const stage = `${path}.pending`
      // A previous interrupted copy can be retried from its retained native source.
      const source = await this.fs.stat({ path: sourcePath })
      if (source.type !== 'file' || source.size < 1 || source.size > maxBytes) throw new SupportError('EVIDENCE_TOO_LARGE', 'La fotografía supera el tamaño permitido.')
      if (await this.exists(stage)) await this.fs.deleteFile({ path: stage, directory: Directory.Data })
      await this.fs.copy({ from: sourcePath, to: stage, toDirectory: Directory.Data })
      await this.describe(stage, maxBytes)
      await this.fs.rename({ from: stage, to: path, directory: Directory.Data, toDirectory: Directory.Data })
    }
    return this.describe(path, maxBytes)
  }
  async recover(evidence: LocalEvidence, maxBytes: number): Promise<EvidenceFile | null> {
    this.assertNative()
    const path = privatePath(evidence.localUuid)
    if (await this.exists(path)) return this.describe(path, maxBytes)
    // Copy may have completed before the process died and before the rename/SQLite commit.
    const stage = `${path}.pending`
    if (await this.exists(stage)) {
      try { await this.describe(stage, maxBytes) }
      catch (error) {
        if (error instanceof SupportError && error.code === 'INVALID_IMAGE' && evidence.sourcePath) return this.preserve(evidence.sourcePath, evidence.localUuid, maxBytes)
        throw error
      }
      await this.fs.rename({ from: stage, to: path, directory: Directory.Data, toDirectory: Directory.Data })
      return this.describe(path, maxBytes)
    }
    if (evidence.sourcePath) return this.preserve(evidence.sourcePath, evidence.localUuid, maxBytes)
    return null
  }
  async read(evidence: LocalEvidence): Promise<Uint8Array> {
    this.assertNative()
    if (evidence.path !== privatePath(evidence.localUuid) || !evidence.sizeBytes || !evidence.uploadSha256 || evidence.purgedAt) throw new SupportError('EVIDENCE_UNAVAILABLE', 'La fotografía no está disponible en este equipo.')
    const stat = await this.fs.stat({ path: evidence.path, directory: Directory.Data })
    if (stat.size !== evidence.sizeBytes) throw new SupportError('EVIDENCE_CHANGED', 'La fotografía cambió después de la captura.')
    const bytes = await this.readPath(evidence.path, evidence.sizeBytes)
    if (await hashBytes(bytes) !== evidence.uploadSha256) throw new SupportError('EVIDENCE_CHANGED', 'La fotografía cambió después de la captura.')
    return bytes
  }
  async purgeConfirmed(evidence: LocalEvidence): Promise<void> {
    this.assertNative()
    if (evidence.state !== 'CONFIRMED' || !evidence.serverUuid || evidence.path !== privatePath(evidence.localUuid)) throw new SupportError('EVIDENCE_UNCONFIRMED', 'La fotografía debe confirmarse antes de retirarla del equipo.')
    try { await this.fs.deleteFile({ path: evidence.path, directory: Directory.Data }) }
    catch (error) { if (!missing(error)) throw error }
  }
  async cleanupCameraSource(evidence: LocalEvidence): Promise<boolean> {
    this.assertNative()
    if (!['READY', 'CONFIRMED'].includes(evidence.state) || !evidence.sourcePath) return false
    // Camera7's Android Camera/URI path is the generated file in External/Pictures.
    // Its fallback may be in Cache. Never delete a content URI, gallery file or sibling path.
    const source = evidence.sourcePath
    if (!/^file:\/\/\//.test(source) || /[%?#\\\u0000-\u001f]/.test(source)) return false
    for (const directory of [Directory.External, Directory.Cache]) {
      const parent = directory === Directory.External ? 'Pictures' : ''
      const { uri } = await this.fs.getUri({ path: parent, directory })
      const prefix = `${uri.replace(/\/+$/, '')}/`
      if (!source.startsWith(prefix)) continue
      const filename = source.slice(prefix.length)
      if (!/^JPEG_\d{8}_\d{6}_\d+\.jpg$/.test(filename)) return false
      const path = parent ? `${parent}/${filename}` : filename
      try { await this.fs.stat({ path, directory }) }
      catch (error) { if (missing(error)) return true; throw error }
      // A committed metadata snapshot alone is insufficient: verify the durable private copy.
      await this.read(evidence)
      await this.fs.deleteFile({ path, directory })
      return true
    }
    return false
  }
  async preview(evidence: LocalEvidence): Promise<string> {
    this.assertNative()
    if (!evidence.path || evidence.path !== privatePath(evidence.localUuid) || evidence.purgedAt) throw new SupportError('EVIDENCE_UNAVAILABLE', 'La fotografía no está disponible en este equipo.')
    const { uri } = await this.fs.getUri({ path: evidence.path, directory: Directory.Data })
    return Capacitor.convertFileSrc(uri)
  }
}
