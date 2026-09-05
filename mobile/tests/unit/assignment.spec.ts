import { describe, expect, it } from 'vitest'
import { isAttendanceAssignmentEffective } from '@/domain/assignment'
import type { EmployeeManifestItem } from '@/domain/types'

function employee(overrides: Partial<EmployeeManifestItem['assignment']> = {}): EmployeeManifestItem {
  return {
    employee_id: '1', employee_number: '14388', name: 'Operador',
    assignment: {
      uuid: 'a', type: 'TEMPORARY', valid_from: '2026-09-01T00:00:00Z', valid_until: '2026-09-30T23:59:59Z',
      attendance_allowed: true, enrollment_allowed: false, maintenance_allowed: false, ...overrides,
    },
  }
}

describe('effective assignment', () => {
  const now = new Date('2026-09-04T12:00:00Z')
  it('accepts an effective temporary assignment', () => expect(isAttendanceAssignmentEffective(employee(), now)).toBe(true))
  it('rejects future, expired and forbidden assignments', () => {
    expect(isAttendanceAssignmentEffective(employee({ valid_from: '2026-09-05T00:00:00Z' }), now)).toBe(false)
    expect(isAttendanceAssignmentEffective(employee({ valid_until: '2026-09-03T00:00:00Z' }), now)).toBe(false)
    expect(isAttendanceAssignmentEffective(employee({ attendance_allowed: false }), now)).toBe(false)
  })
})
