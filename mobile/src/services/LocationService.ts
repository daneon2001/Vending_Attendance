import { Geolocation } from '@capacitor/geolocation'
import { EdgeError } from '@/domain/errors'
import type { LocationEvidence } from '@/domain/types'

export interface LocationProvider {
  capture(): Promise<LocationEvidence>
}

export class CapacitorLocationService implements LocationProvider {
  async capture(): Promise<LocationEvidence> {
    try {
      const permission = await Geolocation.checkPermissions()
      const resolved = permission.location === 'granted'
        ? permission
        : await Geolocation.requestPermissions({ permissions: ['location'] })
      if (resolved.location !== 'granted') {
        throw new EdgeError('GPS_PERMISSION_DENIED', 'Se requiere permiso de ubicación para checar.')
      }
      const position = await Geolocation.getCurrentPosition({
        enableHighAccuracy: true,
        timeout: 15_000,
        maximumAge: 5_000,
      })
      const { latitude, longitude, accuracy } = position.coords
      if (![latitude, longitude, accuracy].every(Number.isFinite)) {
        throw new EdgeError('GPS_UNAVAILABLE', 'El dispositivo no entregó una ubicación válida.', true)
      }
      return {
        latitude,
        longitude,
        accuracy_m: accuracy,
        captured_at: new Date(position.timestamp).toISOString(),
      }
    } catch (error) {
      if (error instanceof EdgeError) throw error
      const code = typeof error === 'object' && error !== null && 'code' in error
        ? String(error.code)
        : ''
      const message = error instanceof Error ? error.message.toLowerCase() : ''
      if (code.endsWith('0003') || code.endsWith('0008')) {
        throw new EdgeError('GPS_PERMISSION_DENIED', 'Se requiere permiso de ubicación para checar.')
      }
      if (code.endsWith('0007') || code.endsWith('0009') || message.includes('disabled') || message.includes('location services')) {
        throw new EdgeError('GPS_DISABLED', 'Activa los servicios de ubicación.', true)
      }
      if (code.endsWith('0010') || message.includes('timeout')) {
        throw new EdgeError('GPS_TIMEOUT', 'No se obtuvo ubicación dentro del tiempo esperado.', true)
      }
      throw new EdgeError('GPS_UNAVAILABLE', 'No fue posible obtener la ubicación.', true)
    }
  }
}
