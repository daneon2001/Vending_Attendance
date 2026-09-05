import { describe, expect, it } from 'vitest'
import {
  evaluateBiometricDistribution,
  evaluateBiometricEnrollment,
  evaluateFutureBiometricAttendance,
} from '@/biometrics'
import type { BiometricDistributionContext, BiometricEnrollmentContext } from '@/biometrics'

const distributable: BiometricDistributionContext = {
  employeeActive: true,
  assignmentStatus: 'ACTIVE',
  assignmentEffective: true,
  assignmentMachineUuid: 'machine-1',
  targetMachineUuid: 'machine-1',
  attendanceAllowed: true,
  templateStatus: 'ACTIVE',
  templateRevokedAt: null,
}

const enrollable: BiometricEnrollmentContext = {
  deviceStatus: 'ACTIVE',
  machineStatus: 'ACTIVE',
  employeeActive: true,
  assignmentEffective: true,
  enrollmentAllowed: true,
  geofenceResult: 'INSIDE',
  actorAuthorized: true,
  providerEnrollmentSupported: true,
  templateUniqueness: 'AVAILABLE',
}

describe('biometric desired state and future policies', () => {
  it('removes templates when employee, assignment, machine, or template scope is lost', () => {
    expect(evaluateBiometricDistribution({ ...distributable, employeeActive: false }).reason)
      .toBe('EMPLOYEE_INACTIVE')
    expect(evaluateBiometricDistribution({ ...distributable, assignmentStatus: 'REVOKED' }).reason)
      .toBe('ASSIGNMENT_REVOKED')
    expect(evaluateBiometricDistribution({ ...distributable, assignmentMachineUuid: 'machine-2' }).reason)
      .toBe('MACHINE_REASSIGNED')
    expect(evaluateBiometricDistribution({ ...distributable, templateStatus: 'REVOKED', templateRevokedAt: '2026-09-05T12:00:00Z' }).reason)
      .toBe('TEMPLATE_REVOKED')
    expect(evaluateBiometricDistribution(distributable)).toEqual({
      desiredState: 'INCLUDE',
      reason: 'AUTHORIZED_TEMPLATE',
    })
  })

  it('requires all enrollment gates and never treats uncertain geofence as valid', () => {
    expect(evaluateBiometricEnrollment(enrollable)).toEqual({ allowed: true, reasons: [] })
    const denied = evaluateBiometricEnrollment({
      ...enrollable,
      geofenceResult: 'UNCERTAIN',
      actorAuthorized: false,
    })
    expect(denied.allowed).toBe(false)
    expect(denied.reasons).toEqual(['GEOFENCE_NOT_CONFIRMED', 'ACTOR_NOT_AUTHORIZED'])
  })

  it('allows the future attendance policy only after an actual biometric match', () => {
    expect(evaluateFutureBiometricAttendance({
      authorization: 'AUTHORIZED', geofence: 'INSIDE', biometric: 'MATCH',
    })).toBe('ALLOWED')
    expect(evaluateFutureBiometricAttendance({
      authorization: 'AUTHORIZED', geofence: 'INSIDE', biometric: 'NO_MATCH',
    })).toBe('DENIED')
    expect(evaluateFutureBiometricAttendance({
      authorization: 'AUTHORIZED', geofence: 'INSIDE', biometric: 'NOT_SUPPORTED',
    })).toBe('UNVERIFIABLE')
  })
})
