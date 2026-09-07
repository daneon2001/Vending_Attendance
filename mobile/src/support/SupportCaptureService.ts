import { App, type AppPlugin, type RestoredListenerEvent } from '@capacitor/app'
import { Camera, CameraResultType, CameraSource, type CameraPlugin, type Photo } from '@capacitor/camera'
import type { PluginListenerHandle } from '@capacitor/core'
import type { CredentialStore } from '@/security/DeviceCredentialStore'
import type { LocationProvider } from '@/services/LocationService'
import type { LocationEvidence } from '@/domain/types'
import type { SupportStore } from './SupportStore'
import type { EvidenceFiles } from './PrivateEvidenceFiles'
import { SupportError, SupportMutex, type LocalEvidence } from './types'

export class SupportCaptureService {
  private handle: PluginListenerHandle | null = null
  private readonly mutex = new SupportMutex()
  private lastRecoveryError: string | null = null
  constructor(
    private readonly store: SupportStore,
    private readonly files: EvidenceFiles,
    private readonly credentials: CredentialStore,
    private readonly location: LocationProvider,
    private readonly camera: CameraPlugin = Camera,
    private readonly app: AppPlugin = App,
  ) {}

  async initialize(): Promise<void> {
    if (this.handle) return
    this.handle = await this.app.addListener('appRestoredResult', result => {
      void this.restore(result).catch(() => { this.lastRecoveryError = 'CAPTURE_RECOVERY_PENDING' })
    })
    await this.recoverPending().catch(() => { this.lastRecoveryError = 'CAPTURE_RECOVERY_PENDING' })
  }
  async stop(): Promise<void> { await this.handle?.remove(); this.handle = null }
  recoveryError(): string | null { return this.lastRecoveryError }

  private async identity(expected?: string): Promise<string> {
    const identity = await this.credentials.get()
    if (!identity) throw new SupportError('NOT_PROVISIONED', 'El equipo no está configurado.')
    if (expected && expected !== identity.deviceUuid) throw new SupportError('IDENTITY_CHANGED', 'La fotografía pertenece a otra identidad del equipo.')
    return identity.deviceUuid
  }
  async captureLocation(): Promise<LocationEvidence | null> {
    // Support remains available for GPS/permission failures. Attendance keeps its strict behavior.
    try { return await this.location.capture() } catch { return null }
  }
  capture(ticketLocalUuid: string): Promise<LocalEvidence> {
    return this.mutex.run(async () => {
      const deviceUuid = await this.identity()
      const pending = await this.store.reserveCapture(ticketLocalUuid, deviceUuid)
      let photo: Photo
      try {
        photo = await this.camera.getPhoto({ resultType: CameraResultType.Uri, source: CameraSource.Camera,
          saveToGallery: false, allowEditing: false, quality: 80, width: 1600, height: 1600, correctOrientation: true })
      } catch {
        await this.store.cancelCapture(pending.localUuid, deviceUuid)
        throw new SupportError('CAMERA_CANCELLED', 'No se agregó una fotografía. Puedes intentarlo nuevamente.')
      }
      await this.accept(pending, photo)
      return (await this.store.getEvidence(pending.localUuid, deviceUuid))!
    })
  }
  private async accept(pending: LocalEvidence, photo: Photo): Promise<void> {
    await this.identity(pending.deviceUuid)
    if (!photo.path || !['jpeg', 'jpg'].includes(photo.format?.toLowerCase())) throw new SupportError('INVALID_CAMERA_FILE', 'La cámara no entregó una fotografía válida.')
    // Save the native source before copying. Neither EXIF nor base64 enters persistent metadata.
    await this.store.saveCaptureSource(pending.localUuid, pending.deviceUuid, photo.path)
    const context = await this.store.getContext(pending.deviceUuid)
    if (!context) throw new SupportError('CONTEXT_UNAVAILABLE', 'Falta configuración para guardar la fotografía.')
    const file = await this.files.preserve(photo.path, pending.localUuid, context.evidence_policy.max_size_bytes)
    await this.store.completeCapture(pending.localUuid, pending.deviceUuid, file)
    await this.cleanupSource(pending)
    this.lastRecoveryError = null
  }
  private async cleanupSource(pending: LocalEvidence): Promise<void> {
    try {
      const committed = await this.store.getEvidence(pending.localUuid, pending.deviceUuid)
      if (committed && await this.files.cleanupCameraSource(committed)) await this.store.markSourceCleaned(committed.localUuid, committed.deviceUuid)
    } catch { /* Canonical private bytes and metadata are durable; retry only the redundant source later. */ }
  }
  restore(result: RestoredListenerEvent): Promise<void> {
    return this.mutex.run(async () => {
      if (result.pluginId !== 'Camera' || result.methodName !== 'getPhoto') return
      const pending = await this.store.pendingCapture()
      if (!pending) return
      await this.identity(pending.deviceUuid)
      if (!result.success) { await this.store.cancelCapture(pending.localUuid, pending.deviceUuid); return }
      await this.accept(pending, result.data as Photo)
    })
  }
  recoverPending(): Promise<void> {
    return this.mutex.run(async () => {
      const pending = await this.store.pendingCapture()
      if (!pending) return
      await this.identity(pending.deviceUuid)
      const context = await this.store.getContext(pending.deviceUuid)
      if (!context) return
      const file = await this.files.recover(pending, context.evidence_policy.max_size_bytes)
      if (file) {
        await this.store.completeCapture(pending.localUuid, pending.deviceUuid, file)
        await this.cleanupSource(pending)
        this.lastRecoveryError = null
      }
    })
  }
  cancelPending(): Promise<void> {
    return this.mutex.run(async () => {
      const pending = await this.store.pendingCapture()
      if (!pending) return
      await this.identity(pending.deviceUuid)
      // Cancellation retires the metadata marker. It does not delete unconfirmed evidence.
      await this.store.cancelCapture(pending.localUuid, pending.deviceUuid)
      this.lastRecoveryError = null
    })
  }
}
