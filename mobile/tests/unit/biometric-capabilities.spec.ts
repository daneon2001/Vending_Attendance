import { describe, expect, it } from 'vitest'
import {
  supportsBiometricOperation,
  UnsupportedBiometricProvider,
} from '@/biometrics'
import type { BiometricCapabilities } from '@/biometrics'

describe('biometric provider capabilities', () => {
  it('evaluates operations from explicit capabilities instead of modality assumptions', () => {
    const capabilities: BiometricCapabilities = {
      platform: 'ANDROID',
      provider: 'licensed-provider',
      modality: 'FINGERPRINT',
      capture: true,
      enrollment: true,
      verification: true,
      identification: false,
      offlineMatching: true,
      templateExport: true,
      templateImport: true,
      hardwareRequired: true,
      hardwareConnections: ['USB'],
    }

    expect(supportsBiometricOperation(capabilities, 'ENROLL')).toBe(true)
    expect(supportsBiometricOperation(capabilities, 'VERIFY')).toBe(true)
    expect(supportsBiometricOperation(capabilities, 'IDENTIFY')).toBe(false)
  })

  it('reports unsupported without simulating capture, identification, or a match', async () => {
    const provider = new UnsupportedBiometricProvider('ANDROID')
    const capabilities = await provider.capabilities()
    const result = await provider.verify({
      employeeId: 'employee-1',
      templateUuid: 'template-1',
      modality: 'FINGERPRINT',
      capturedAt: '2026-09-05T12:00:00Z',
    })

    expect(capabilities).toHaveLength(2)
    expect(capabilities.every((item) => !item.capture && !item.verification)).toBe(true)
    expect(provider.identify).toBeUndefined()
    expect(result.result).toBe('NOT_SUPPORTED')
    expect(result).not.toHaveProperty('matchedEmployeeId')
    await expect(provider.deleteTemplate({ templateUuid: 'template-1', reason: 'REVOKED' }))
      .resolves.toEqual({
        provider: 'unsupported',
        templateUuid: 'template-1',
        result: 'NOT_SUPPORTED',
        reason: 'TEMPLATE_DELETION_NOT_SUPPORTED',
      })
    await expect(provider.healthCheck()).resolves.toEqual({
      provider: 'unsupported',
      state: 'NOT_SUPPORTED',
      reason: 'NO_VENDOR_SDK_CONFIGURED',
    })
  })
})
