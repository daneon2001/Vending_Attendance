import { describe, expect, it, vi } from 'vitest'
import { AttendanceCaptureService } from '@/services/AttendanceCaptureService'
import { GeofenceValidationService } from '@/services/GeofenceValidationService'
import { terminalOutboxStatus } from '@/domain/outbox'
import type { EdgeStore, EffectiveEmployee } from '@/storage/EdgeStore'

const employee: EffectiveEmployee = {
  employee_id: '42', employee_number: '14388', name: 'Operador', effective: true,
  assignment: {
    uuid: 'ef9eb0fa-785a-4a9d-a775-ebae36b71af0', type: 'PRIMARY',
    valid_from: '2026-09-01T00:00:00Z', valid_until: null,
    attendance_allowed: true, enrollment_allowed: false, maintenance_allowed: false,
  },
}

describe('offline attendance capture', () => {
  it('creates UUIDv4-shaped evidence and enqueues before any network operation', async () => {
    const enqueueAttendance = vi.fn(async () => undefined)
    const store = {
      getAttendanceContext: async () => ({
        timezone: 'America/Mexico_City', configurationVersion: 14, employeeManifestVersion: 31,
        geofence: { uuid: 'g', version: 4, type: 'CIRCLE', latitude: 19.432608, longitude: -99.133209,
          radius_m: 50, minimum_acceptable_accuracy_m: 30, tolerance_m: 10 },
      }),
      enqueueAttendance,
    } as unknown as EdgeStore
    const location = { capture: async () => ({ latitude: 19.432608, longitude: -99.133209, accuracy_m: 5, captured_at: '2026-09-04T12:00:00Z' }) }
    const service = new AttendanceCaptureService(store, location, new GeofenceValidationService())
    const result = await service.capture(employee, 'CHECK_IN')
    expect(result.payload.event_uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i)
    expect(result.payload.assignment_uuid).toBe(employee.assignment.uuid)
    expect(result.evaluation.result).toBe('INSIDE')
    expect(enqueueAttendance).toHaveBeenCalledOnce()
  })

  it('maps STORED and DUPLICATE to SYNCED, and REJECTED to retained rejection', () => {
    expect(terminalOutboxStatus({ event_uuid: 'a', status: 'STORED' })).toBe('SYNCED')
    expect(terminalOutboxStatus({ event_uuid: 'a', status: 'DUPLICATE' })).toBe('SYNCED')
    expect(terminalOutboxStatus({ event_uuid: 'a', status: 'REJECTED' })).toBe('REJECTED')
  })
})
