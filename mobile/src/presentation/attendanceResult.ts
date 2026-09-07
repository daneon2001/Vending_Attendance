import type { AttendanceEventType, GeofenceResult } from '@/domain/types'
import type { AttendanceReceipt } from '@/storage/EdgeStore'

const locationLabels: Record<GeofenceResult, string> = {
  INSIDE: 'Dentro de la zona permitida',
  OUTSIDE: 'Fuera de la zona permitida',
  UNCERTAIN: 'No fue posible confirmar tu ubicación',
}

const eventLabels: Record<AttendanceEventType, string> = {
  CHECK_IN: 'Entrada',
  CHECK_OUT: 'Salida',
}

export function attendanceEventLabel(type: AttendanceEventType): string {
  return eventLabels[type]
}

export function attendanceLocationLabel(result: GeofenceResult): string {
  return locationLabels[result] ?? locationLabels.UNCERTAIN
}

export function attendanceDistance(distanceM: number): string | null {
  if (!Number.isFinite(distanceM) || distanceM < 0) return null
  if (Math.round(distanceM) < 1000) return String(Math.round(distanceM)) + ' m'
  const kilometers = distanceM / 1000
  return new Intl.NumberFormat('es-MX', {
    maximumFractionDigits: kilometers < 10 ? 1 : 0,
  }).format(kilometers) + ' km'
}

export function attendanceSyncMessage(receipt: AttendanceReceipt | null): string {
  if (receipt?.status === 'SYNCED') return 'Asistencia registrada correctamente.'
  if (receipt?.status === 'REJECTED') {
    // Only known reasons get specific copy; never display a server message or code.
    switch (receipt.errorCode) {
      case 'INVALID_EMPLOYEE':
        return 'No se pudo validar al empleado. Comunícate con tu supervisor.'
      case 'INVALID_TIMESTAMP':
        return 'No se pudo validar la fecha y hora del registro. Revisa el reloj del dispositivo y comunícate con tu supervisor.'
      case 'INVALID_EVENT':
        return 'No se pudo validar este registro. Comunícate con tu supervisor.'
      default:
        return 'No se pudo registrar la asistencia. Comunícate con tu supervisor.'
    }
  }
  if (receipt?.status === 'PENDING' || receipt?.status === 'SYNCING') {
    return 'Asistencia guardada en este dispositivo. Se enviará automáticamente cuando haya conexión.'
  }
  return 'Asistencia guardada en este dispositivo. Aún no se ha confirmado su envío.'
}

export function attendanceResultColor(result: GeofenceResult, receipt: AttendanceReceipt | null): string {
  if (receipt?.status === 'REJECTED') return 'danger'
  if (result !== 'INSIDE') return 'warning'
  return receipt?.status === 'SYNCED' ? 'success' : 'light'
}
