import { Geolocation, type Position } from '@capacitor/geolocation'
import { EdgeError } from '@/domain/errors'
import type { LocationEvidence } from '@/domain/types'

export interface LocationProvider {
  capture(): Promise<LocationEvidence>
}

export class CapacitorLocationService implements LocationProvider {
  constructor(
    private readonly timeoutMs: number,
    private readonly diagnosticsEnabled = false,
  ) {}

  async capture(): Promise<LocationEvidence> {
    let startedTick: number | null = null
    try {
      const permission = await Geolocation.checkPermissions()
      const resolved = permission.location === 'granted'
        ? permission
        : await Geolocation.requestPermissions({ permissions: ['location'] })
      if (resolved.location !== 'granted') {
        throw new EdgeError('GPS_PERMISSION_DENIED', 'Se requiere permiso de ubicación para checar.')
      }
      const requestedAt = Date.now()
      startedTick = performance.now()
      this.logCapture('GPS_CAPTURE_STARTED', startedTick)
      const position = await this.currentPosition()
      if (performance.now() - startedTick >= this.timeoutMs) throw this.timeoutError()
      // Fail closed even if a platform ignores maximumAge or returns a cached fix.
      if (!Number.isFinite(position.timestamp) || position.timestamp < requestedAt || position.timestamp > Date.now()) {
        throw new EdgeError('GPS_UNAVAILABLE', 'No fue posible obtener una ubicación nueva. Inténtalo nuevamente en un lugar con mejor recepción GPS.', true)
      }
      const { latitude, longitude, accuracy } = position.coords
      if (![latitude, longitude, accuracy].every(Number.isFinite)) {
        throw new EdgeError('GPS_UNAVAILABLE', 'El dispositivo no entregó una ubicación válida.', true)
      }
      this.logCapture('GPS_CAPTURE_SUCCESS', startedTick)
      return {
        latitude,
        longitude,
        accuracy_m: accuracy,
        captured_at: new Date(position.timestamp).toISOString(),
      }
    } catch (error) {
      const failure = this.classifyError(error)
      if (failure.code === 'GPS_TIMEOUT' && startedTick !== null) {
        this.logCapture('GPS_CAPTURE_TIMEOUT', startedTick)
      }
      throw failure
    }
  }

  private async currentPosition(): Promise<Position> {
    let timer: ReturnType<typeof setTimeout> | undefined
    try {
      const deadline = new Promise<never>((_resolve, reject) => {
        timer = setTimeout(() => reject(this.timeoutError()), this.timeoutMs)
      })
      return await Promise.race([
        Geolocation.getCurrentPosition({
          enableHighAccuracy: true,
          timeout: this.timeoutMs,
          maximumAge: 0,
        }),
        deadline,
      ])
    } finally {
      if (timer !== undefined) clearTimeout(timer)
    }
  }

  private timeoutError(): EdgeError {
    return new EdgeError('GPS_TIMEOUT', 'No fue posible obtener tu ubicación. Verifica que la ubicación esté activada e inténtalo nuevamente en un lugar con mejor recepción.', true)
  }

  private classifyError(error: unknown): EdgeError {
    if (error instanceof EdgeError) return error
    const code = typeof error === 'object' && error !== null && 'code' in error
      ? String(error.code)
      : ''
    const message = error instanceof Error ? error.message.toLowerCase() : ''
    if (code.endsWith('0003') || code.endsWith('0008')) {
      return new EdgeError('GPS_PERMISSION_DENIED', 'Se requiere permiso de ubicación para checar.')
    }
    if (code.endsWith('0007') || code.endsWith('0009') || message.includes('disabled') || message.includes('location services')) {
      return new EdgeError('GPS_DISABLED', 'Activa los servicios de ubicación.', true)
    }
    if (code.endsWith('0010') || code === '3' || message.includes('timeout')) {
      return this.timeoutError()
    }
    return new EdgeError('GPS_UNAVAILABLE', 'No fue posible obtener la ubicación.', true)
  }

  private logCapture(category: 'GPS_CAPTURE_STARTED' | 'GPS_CAPTURE_SUCCESS' | 'GPS_CAPTURE_TIMEOUT', startedTick: number): void {
    if (this.diagnosticsEnabled) {
      console.info(category, JSON.stringify({ elapsed_ms: Math.max(0, Math.round(performance.now() - startedTick)) }))
    }
  }
}
