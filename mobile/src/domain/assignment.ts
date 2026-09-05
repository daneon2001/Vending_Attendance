import type { EmployeeManifestItem } from './types'

export function isAttendanceAssignmentEffective(
  employee: EmployeeManifestItem,
  at: Date,
): boolean {
  const timestamp = at.getTime()
  const validFrom = Date.parse(employee.assignment.valid_from)
  const validUntil = employee.assignment.valid_until
    ? Date.parse(employee.assignment.valid_until)
    : Number.POSITIVE_INFINITY
  return employee.assignment.attendance_allowed
    && Number.isFinite(validFrom)
    && timestamp >= validFrom
    && timestamp <= validUntil
}
