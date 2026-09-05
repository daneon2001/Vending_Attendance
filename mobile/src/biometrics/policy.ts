import type { BiometricResult } from './contracts'
import type { BiometricTemplateStatus } from './template'

export interface BiometricDistributionContext {
  employeeActive: boolean
  assignmentStatus: 'ACTIVE' | 'INACTIVE' | 'REVOKED' | 'EXPIRED'
  assignmentEffective: boolean
  assignmentMachineUuid: string
  targetMachineUuid: string
  attendanceAllowed: boolean
  templateStatus: BiometricTemplateStatus
  templateRevokedAt: string | null
}

export type BiometricDistributionReason =
  | 'AUTHORIZED_TEMPLATE'
  | 'EMPLOYEE_INACTIVE'
  | 'ASSIGNMENT_NOT_EFFECTIVE'
  | 'ASSIGNMENT_REVOKED'
  | 'MACHINE_REASSIGNED'
  | 'ATTENDANCE_NOT_ALLOWED'
  | 'TEMPLATE_NOT_ACTIVE'
  | 'TEMPLATE_REVOKED'

export interface BiometricDistributionDecision {
  desiredState: 'INCLUDE' | 'REMOVE'
  reason: BiometricDistributionReason
}

export function evaluateBiometricDistribution(
  context: BiometricDistributionContext,
): BiometricDistributionDecision {
  if (!context.employeeActive) return { desiredState: 'REMOVE', reason: 'EMPLOYEE_INACTIVE' }
  if (context.assignmentStatus === 'REVOKED') return { desiredState: 'REMOVE', reason: 'ASSIGNMENT_REVOKED' }
  if (context.assignmentStatus !== 'ACTIVE' || !context.assignmentEffective) {
    return { desiredState: 'REMOVE', reason: 'ASSIGNMENT_NOT_EFFECTIVE' }
  }
  if (context.assignmentMachineUuid !== context.targetMachineUuid) {
    return { desiredState: 'REMOVE', reason: 'MACHINE_REASSIGNED' }
  }
  if (!context.attendanceAllowed) return { desiredState: 'REMOVE', reason: 'ATTENDANCE_NOT_ALLOWED' }
  if (context.templateStatus === 'REVOKED' || context.templateRevokedAt !== null) {
    return { desiredState: 'REMOVE', reason: 'TEMPLATE_REVOKED' }
  }
  if (context.templateStatus !== 'ACTIVE') return { desiredState: 'REMOVE', reason: 'TEMPLATE_NOT_ACTIVE' }
  return { desiredState: 'INCLUDE', reason: 'AUTHORIZED_TEMPLATE' }
}

export interface BiometricEnrollmentContext {
  deviceStatus: 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'REVOKED' | 'RETIRED'
  machineStatus: string
  employeeActive: boolean
  assignmentEffective: boolean
  enrollmentAllowed: boolean
  geofenceResult: 'INSIDE' | 'OUTSIDE' | 'UNCERTAIN' | 'NOT_EVALUATED'
  actorAuthorized: boolean
  providerEnrollmentSupported: boolean
  templateUniqueness: 'AVAILABLE' | 'CONFLICT' | 'REPLACEMENT_ALLOWED'
}

export interface BiometricEnrollmentDecision {
  allowed: boolean
  reasons: readonly string[]
}

export function evaluateBiometricEnrollment(
  context: BiometricEnrollmentContext,
): BiometricEnrollmentDecision {
  const reasons: string[] = []
  if (context.deviceStatus !== 'ACTIVE') reasons.push('DEVICE_NOT_ACTIVE')
  if (context.machineStatus !== 'ACTIVE') reasons.push('MACHINE_NOT_ACTIVE')
  if (!context.employeeActive) reasons.push('EMPLOYEE_INACTIVE')
  if (!context.assignmentEffective) reasons.push('ASSIGNMENT_NOT_EFFECTIVE')
  if (!context.enrollmentAllowed) reasons.push('ENROLLMENT_NOT_ALLOWED')
  if (context.geofenceResult !== 'INSIDE') reasons.push('GEOFENCE_NOT_CONFIRMED')
  if (!context.actorAuthorized) reasons.push('ACTOR_NOT_AUTHORIZED')
  if (!context.providerEnrollmentSupported) reasons.push('PROVIDER_NOT_SUPPORTED')
  if (context.templateUniqueness === 'CONFLICT') reasons.push('TEMPLATE_CONFLICT')
  return { allowed: reasons.length === 0, reasons }
}

export type FutureAttendanceDecision = 'ALLOWED' | 'DENIED' | 'UNVERIFIABLE'

export interface FutureBiometricAttendanceContext {
  authorization: 'AUTHORIZED' | 'DENIED' | 'UNVERIFIABLE'
  geofence: 'INSIDE' | 'OUTSIDE' | 'UNCERTAIN' | 'NOT_EVALUATED'
  biometric: BiometricResult
}

/** Pure future policy; it is not wired to the Phase 5 attendance flow. */
export function evaluateFutureBiometricAttendance(
  context: FutureBiometricAttendanceContext,
): FutureAttendanceDecision {
  if (context.authorization === 'DENIED' || context.geofence === 'OUTSIDE' || context.biometric === 'NO_MATCH') {
    return 'DENIED'
  }
  if (context.authorization !== 'AUTHORIZED' || context.geofence !== 'INSIDE' || context.biometric !== 'MATCH') {
    return 'UNVERIFIABLE'
  }
  return 'ALLOWED'
}
