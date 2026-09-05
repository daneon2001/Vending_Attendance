import { describe, expect, it } from 'vitest'
import {
  biometricManifestHashContent,
  unsupportedBiometricManifestStatus,
  validateBiometricManifest,
  validateTemplateDelivery,
  validateTemplateMetadata,
} from '@/biometrics'
import type {
  BiometricManifestEntry,
  BiometricManifestSnapshot,
  EmployeeBiometricTemplateMetadata,
} from '@/biometrics'

function metadata(overrides: Partial<EmployeeBiometricTemplateMetadata> = {}): EmployeeBiometricTemplateMetadata {
  return {
    uuid: 'template-1',
    employeeId: 'employee-1',
    modality: 'FINGERPRINT',
    provider: 'provider-a',
    format: 'PROVIDER_OPAQUE',
    formatVersion: '1',
    templateVersion: 1,
    status: 'ACTIVE',
    qualityScore: null,
    qualityScale: null,
    enrolledAt: '2026-09-05T12:00:00Z',
    revokedAt: null,
    sourceDeviceId: 'device-1',
    metadata: { fingerPosition: 'RIGHT_INDEX' },
    ...overrides,
  }
}

function entry(templateUuid: string): BiometricManifestEntry {
  return {
    employeeIdentifier: `employee-${templateUuid}`,
    assignmentUuid: `assignment-${templateUuid}`,
    template: metadata({ uuid: templateUuid }),
    hashSha256: 'a'.repeat(64),
    delivery: {
      transport: 'REFERENCE',
      encryptedReference: `opaque:${templateUuid}`,
    },
  }
}

function manifest(overrides: Partial<BiometricManifestSnapshot> = {}): BiometricManifestSnapshot {
  return {
    manifestType: 'BIOMETRICS',
    supported: true,
    desiredState: 'FULL_SNAPSHOT',
    scope: 'EFFECTIVE_EMPLOYEE_MACHINE_ASSIGNMENTS',
    manifestVersion: 1,
    manifestHash: 'b'.repeat(64),
    generatedAt: '2026-09-05T12:00:00Z',
    serverTime: '2026-09-05T12:00:01Z',
    machineUuid: 'machine-1',
    entries: [entry('template-1')],
    ...overrides,
  }
}

describe('biometric template and manifest contracts', () => {
  it('keeps metadata separate from biometric material', () => {
    const value = metadata()
    expect(validateTemplateMetadata(value)).toEqual([])
    expect(value).not.toHaveProperty('templateData')
    expect(value).not.toHaveProperty('image')
  })

  it('rejects sensitive material hidden in metadata', () => {
    expect(validateTemplateMetadata(metadata({ metadata: { rawImage: 'forbidden' } })))
      .toContain('SENSITIVE_METADATA_FORBIDDEN')
  })

  it('requires machine-scoped encrypted delivery and an independent version', () => {
    expect(validateBiometricManifest(manifest())).toEqual([])
    expect(unsupportedBiometricManifestStatus()).toEqual({
      supported: false,
      serverVersion: null,
      appliedVersion: null,
      changed: false,
    })
  })

  it('rejects incomplete encrypted envelopes instead of accepting plaintext fallback', () => {
    expect(validateTemplateDelivery({
      transport: 'ENCRYPTED_PAYLOAD',
      envelope: {
        envelopeVersion: 1,
        algorithm: 'AEAD_PROVIDER_PENDING',
        keyReference: 'device-key-1',
        nonceB64: '',
        ciphertextB64: 'encrypted-data',
        authenticationTagB64: '',
      },
    })).toEqual(['NONCE_REQUIRED', 'AUTHENTICATION_TAG_REQUIRED'])
  })

  it('produces deterministic hash material independent of timestamps and entry order', () => {
    const first = manifest({ entries: [entry('template-2'), entry('template-1')] })
    const second = manifest({
      generatedAt: '2030-01-01T00:00:00Z',
      serverTime: '2030-01-01T00:00:01Z',
      entries: [entry('template-1'), entry('template-2')],
    })

    expect(biometricManifestHashContent(first)).toEqual(biometricManifestHashContent(second))
  })
})
