import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import AttendanceResultCard from '@/components/AttendanceResultCard.vue'
import type { AttendanceCaptureResult } from '@/services/AttendanceCaptureService'
import type { AttendanceReceipt } from '@/storage/EdgeStore'
import type { AttendanceEventType, GeofenceResult, OutboxStatus } from '@/domain/types'
import { attendanceDistance, attendanceResultColor, attendanceSyncMessage } from '@/presentation/attendanceResult'

vi.mock('@ionic/vue', async () => {
  const { defineComponent, h } = await import('vue')
  const card = defineComponent({
    setup(_props, { slots }) { return () => h('div', slots.default?.()) },
  })
  return { IonCard: card, IonCardContent: card, IonCardHeader: card, IonCardTitle: card }
})

function capture(location: GeofenceResult = 'OUTSIDE', type: AttendanceEventType = 'CHECK_IN'): AttendanceCaptureResult {
  return {
    payload: {
      event_uuid: '2dd39e13-0aa5-4d10-89db-344c52c2aac4',
      employee_id: '42', event_type: type, captured_at: '2026-09-07T03:13:00Z',
      employee_manifest_version: 5, configuration_version: 2,
      assignment_uuid: '23fd640d-e5b7-4135-9cc7-d74268d4790e',
      device_timezone: 'America/Mexico_City',
      location: { latitude: 19.43, longitude: -99.13, accuracy_m: 14.501 },
      geofence: { version: 2, edge_result: location },
    },
    evaluation: {
      distance_m: 20962.85, effective_distance_m: 20938.349,
      radius_m: 100, accuracy_m: 14.501, tolerance_m: 10,
      result: location, reason: 'OUTSIDE_RADIUS',
    },
  }
}

function render(result: AttendanceCaptureResult, receipt: AttendanceReceipt | null) {
  return renderToString(createSSRApp({ render: () => h(AttendanceResultCard, { result, receipt }) }))
}

describe('attendance result presentation', () => {
  it.each([
    ['INSIDE', 'Dentro de la zona permitida'],
    ['OUTSIDE', 'Fuera de la zona permitida'],
    ['UNCERTAIN', 'No fue posible confirmar tu ubicación'],
  ] as const)('translates %s without rendering technical evidence or enums', async (location, label) => {
    const result = capture(location)
    const html = await render(result, { status: 'SYNCED', errorCode: null })
    expect(html).toContain(label)
    expect(html).toContain('Distancia: 21 km')
    expect(html).toContain('role="status"')
    expect(html).toContain('aria-live="polite"')
    for (const hidden of [
      result.payload.event_uuid, result.payload.assignment_uuid,
      'event_uuid', 'Evento:', 'Precisión', 'accuracy', '14.501', '20962.85',
      'INSIDE', 'OUTSIDE', 'UNCERTAIN', 'SYNCED', 'CHECK_IN',
    ]) expect(html).not.toContain(hidden)
  })

  it.each([['CHECK_IN', 'Entrada'], ['CHECK_OUT', 'Salida']] as const)('translates %s', async (type, label) => {
    const html = await render(capture('INSIDE', type), { status: 'SYNCED', errorCode: null })
    expect(html).toContain(label)
    expect(html).not.toContain(type)
  })

  it.each([
    [0, '0 m'], [43.2, '43 m'], [999, '999 m'], [999.9, '1 km'],
    [1000, '1 km'], [1250, '1.3 km'], [20962.85, '21 km'],
    [NaN, null], [Infinity, null], [-1, null],
  ])('formats %s metres as %s', (meters, label) => {
    expect(attendanceDistance(meters)).toBe(label)
  })

  it.each(['PENDING', 'SYNCING'] as const)('shows local storage, not server success, for %s', async (status) => {
    const html = await render(capture(), { status, errorCode: null })
    expect(html).toContain('Asistencia guardada en este dispositivo. Se enviará automáticamente cuando haya conexión.')
    expect(html).not.toContain('Asistencia registrada correctamente.')
    expect(html).not.toContain(status)
  })

  it('shows success for the confirmed receipt', async () => {
    const html = await render(capture('INSIDE'), { status: 'SYNCED', errorCode: null })
    expect(html).toContain('Asistencia registrada correctamente.')
    expect(html).toContain('color="success"')
  })

  it('does not invent confirmation if the receipt cannot be read', async () => {
    const html = await render(capture(), null)
    expect(html).toContain('Aún no se ha confirmado su envío.')
    expect(html).not.toContain('Asistencia registrada correctamente.')
  })

  it.each([
    ['INVALID_EMPLOYEE', 'No se pudo validar al empleado.'],
    ['INVALID_TIMESTAMP', 'No se pudo validar la fecha y hora del registro.'],
    ['INVALID_EVENT', 'No se pudo validar este registro.'],
    ['EVENT_UUID_CONFLICT', 'No se pudo registrar la asistencia.'],
    ['BATCH_LIMIT_EXCEEDED', 'No se pudo registrar la asistencia.'],
    ['INTERNAL_RECEIVER_ERROR', 'No se pudo registrar la asistencia.'],
    ['UNKNOWN_REASON <script>secret</script> at Receiver.php:42', 'No se pudo registrar la asistencia.'],
  ])('renders a safe rejection for %s', async (errorCode, message) => {
    const html = await render(capture('INSIDE'), { status: 'REJECTED', errorCode })
    expect(html).toContain(message)
    expect(html).toMatch(/comunícate con tu supervisor\./i)
    expect(html).toContain('color="danger"')
    expect(html).not.toContain('REJECTED')
    expect(html).not.toContain(errorCode)
    expect(html).not.toContain('secret')
    expect(html).not.toContain('Receiver.php')
  })

  it.each([
    ['OUTSIDE', 'SYNCED', 'warning'], ['OUTSIDE', 'PENDING', 'warning'],
    ['UNCERTAIN', 'SYNCED', 'warning'], ['INSIDE', 'SYNCED', 'success'],
    ['INSIDE', 'PENDING', 'light'], ['INSIDE', 'SYNCING', 'light'],
    ['OUTSIDE', 'REJECTED', 'danger'],
  ] as const)('uses %s + %s as %s, without changing meaning', (location, status, color) => {
    expect(attendanceResultColor(location, { status, errorCode: null })).toBe(color)
  })

  it('leaves all original evidence and internal enums intact across presentations', async () => {
    const result = capture()
    Object.freeze(result.payload.location)
    Object.freeze(result.payload.geofence)
    Object.freeze(result.payload)
    Object.freeze(result.evaluation)
    Object.freeze(result)
    const original = JSON.stringify(result)
    for (const status of ['PENDING', 'SYNCING', 'SYNCED', 'REJECTED'] satisfies OutboxStatus[]) {
      const receipt = Object.freeze({ status, errorCode: 'INVALID_EMPLOYEE' })
      await render(result, receipt)
      attendanceSyncMessage(receipt)
      expect(receipt.status).toBe(status)
    }
    expect(JSON.stringify(result)).toBe(original)
    expect(result.payload.event_type).toBe('CHECK_IN')
    expect(result.payload.geofence.edge_result).toBe('OUTSIDE')
    expect(result.evaluation.result).toBe('OUTSIDE')
    expect(result.evaluation.distance_m).toBe(20962.85)
    expect(result.payload.location.accuracy_m).toBe(14.501)
  })
})
