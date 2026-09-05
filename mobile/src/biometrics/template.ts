import type { BiometricModality } from './contracts'

export type BiometricTemplateStatus = 'PENDING' | 'ACTIVE' | 'REVOKED' | 'SUPERSEDED'

/**
 * Metadata only. Biometric material belongs in a separate encrypted envelope
 * or behind an opaque native reference.
 */
export interface EmployeeBiometricTemplateMetadata {
  uuid: string
  employeeId: string
  modality: BiometricModality
  provider: string
  format: string
  formatVersion: string
  templateVersion: number
  status: BiometricTemplateStatus
  qualityScore: number | null
  qualityScale: string | null
  enrolledAt: string
  revokedAt: string | null
  sourceDeviceId: string | null
  metadata: Readonly<Record<string, unknown>>
}

export interface EncryptedBiometricTemplateEnvelope {
  envelopeVersion: 1
  algorithm: string
  keyReference: string
  nonceB64: string
  ciphertextB64: string
  authenticationTagB64: string
}

export type BiometricTemplateDelivery =
  | {
      transport: 'ENCRYPTED_PAYLOAD'
      envelope: EncryptedBiometricTemplateEnvelope
    }
  | {
      transport: 'REFERENCE'
      encryptedReference: string
    }

const SENSITIVE_METADATA_KEY = /(capture|descriptor|embedding|image|photo|secret|template.?data|token)/i

export function validateTemplateMetadata(metadata: EmployeeBiometricTemplateMetadata): readonly string[] {
  const errors: string[] = []
  if (metadata.uuid.trim() === '') errors.push('TEMPLATE_UUID_REQUIRED')
  if (metadata.employeeId.trim() === '') errors.push('EMPLOYEE_ID_REQUIRED')
  if (metadata.provider.trim() === '') errors.push('PROVIDER_REQUIRED')
  if (metadata.format.trim() === '') errors.push('FORMAT_REQUIRED')
  if (metadata.formatVersion.trim() === '') errors.push('FORMAT_VERSION_REQUIRED')
  if (!Number.isSafeInteger(metadata.templateVersion) || metadata.templateVersion < 1) {
    errors.push('TEMPLATE_VERSION_INVALID')
  }
  if (metadata.qualityScore !== null && !Number.isFinite(metadata.qualityScore)) {
    errors.push('QUALITY_SCORE_INVALID')
  }
  if (metadata.qualityScore !== null && !metadata.qualityScale) {
    errors.push('QUALITY_SCALE_REQUIRED')
  }
  if (metadata.status === 'REVOKED' && metadata.revokedAt === null) {
    errors.push('REVOKED_AT_REQUIRED')
  }
  if (metadata.status !== 'REVOKED' && metadata.revokedAt !== null) {
    errors.push('REVOKED_AT_UNEXPECTED')
  }
  if (containsSensitiveMetadata(metadata.metadata)) {
    errors.push('SENSITIVE_METADATA_FORBIDDEN')
  }
  return errors
}

export function validateTemplateDelivery(delivery: BiometricTemplateDelivery): readonly string[] {
  if (delivery.transport === 'REFERENCE') {
    return delivery.encryptedReference.trim() === '' ? ['ENCRYPTED_REFERENCE_REQUIRED'] : []
  }

  const { envelope } = delivery
  const errors: string[] = []
  if (envelope.algorithm.trim() === '') errors.push('ENCRYPTION_ALGORITHM_REQUIRED')
  if (envelope.keyReference.trim() === '') errors.push('KEY_REFERENCE_REQUIRED')
  if (envelope.nonceB64.trim() === '') errors.push('NONCE_REQUIRED')
  if (envelope.ciphertextB64.trim() === '') errors.push('CIPHERTEXT_REQUIRED')
  if (envelope.authenticationTagB64.trim() === '') errors.push('AUTHENTICATION_TAG_REQUIRED')
  return errors
}

function containsSensitiveMetadata(value: unknown, key = ''): boolean {
  if (key !== '' && SENSITIVE_METADATA_KEY.test(key)) return true
  if (Array.isArray(value)) return value.some((item) => containsSensitiveMetadata(item))
  if (value !== null && typeof value === 'object') {
    return Object.entries(value as Record<string, unknown>)
      .some(([childKey, child]) => containsSensitiveMetadata(child, childKey))
  }
  return false
}
