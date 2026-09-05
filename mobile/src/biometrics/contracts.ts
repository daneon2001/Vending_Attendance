export const BIOMETRIC_MODALITIES = ['FINGERPRINT', 'FACE'] as const
export type BiometricModality = typeof BIOMETRIC_MODALITIES[number]

export const BIOMETRIC_OPERATIONS = ['ENROLL', 'VERIFY', 'IDENTIFY'] as const
export type BiometricOperation = typeof BIOMETRIC_OPERATIONS[number]

export const BIOMETRIC_RESULTS = [
  'MATCH',
  'NO_MATCH',
  'UNCERTAIN',
  'ERROR',
  'NOT_SUPPORTED',
] as const
export type BiometricResult = typeof BIOMETRIC_RESULTS[number]

export type BiometricPlatform = 'ANDROID' | 'IOS' | 'WEB'
export type BiometricHardwareConnection = 'BUILT_IN' | 'USB' | 'BLUETOOTH' | 'VENDOR_ACCESSORY'

export interface BiometricCapabilities {
  platform: BiometricPlatform
  provider: string
  modality: BiometricModality
  capture: boolean
  enrollment: boolean
  verification: boolean
  identification: boolean
  offlineMatching: boolean
  templateExport: boolean
  templateImport: boolean
  hardwareRequired: boolean
  hardwareConnections: readonly BiometricHardwareConnection[]
}

export interface BiometricQualityEvidence {
  score: number | null
  scale: string | null
  providerThreshold: number | null
  samples: number | null
}

export interface BiometricTemplateArtifact {
  templateUuid: string
  provider: string
  format: string
  formatVersion: string
  templateVersion: number
  hashSha256: string
  opaqueNativeReference: string
}

export interface BiometricOperationOutcome {
  operation: BiometricOperation
  modality: BiometricModality
  provider: string
  result: BiometricResult
  reason: string
  quality?: BiometricQualityEvidence
  template?: BiometricTemplateArtifact
  matchedEmployeeId?: string
  matchedTemplateUuid?: string
}

export interface BiometricEnrollmentRequest {
  employeeId: string
  assignmentUuid: string
  machineUuid: string
  modality: BiometricModality
  capturedAt: string
}

export interface BiometricVerificationRequest {
  employeeId: string
  templateUuid: string
  modality: BiometricModality
  capturedAt: string
}

export interface BiometricIdentificationRequest {
  candidateTemplateUuids: readonly string[]
  modality: BiometricModality
  capturedAt: string
}

export interface BiometricTemplateDeletionRequest {
  templateUuid: string
  reason: string
}

export interface BiometricTemplateDeletionOutcome {
  provider: string
  templateUuid: string
  result: Extract<BiometricResult, 'ERROR' | 'NOT_SUPPORTED'> | 'DELETED'
  reason: string
}

export type BiometricProviderHealth = 'AVAILABLE' | 'DEGRADED' | 'UNAVAILABLE' | 'NOT_SUPPORTED'

export interface BiometricHealthCheck {
  provider: string
  state: BiometricProviderHealth
  reason: string
}

/**
 * Vendor-neutral boundary. Identification is optional because a provider that
 * only supports 1:1 verification remains a valid implementation.
 *
 * Raw captures and plaintext templates deliberately never cross this boundary.
 * A native provider returns only opaque references and non-sensitive evidence.
 */
export interface BiometricProvider {
  readonly id: string
  capabilities(): Promise<readonly BiometricCapabilities[]>
  enroll(request: BiometricEnrollmentRequest): Promise<BiometricOperationOutcome>
  verify(request: BiometricVerificationRequest): Promise<BiometricOperationOutcome>
  identify?(request: BiometricIdentificationRequest): Promise<BiometricOperationOutcome>
  deleteTemplate(request: BiometricTemplateDeletionRequest): Promise<BiometricTemplateDeletionOutcome>
  healthCheck(): Promise<BiometricHealthCheck>
}

export function supportsBiometricOperation(
  capabilities: BiometricCapabilities,
  operation: BiometricOperation,
): boolean {
  if (operation === 'ENROLL') return capabilities.capture && capabilities.enrollment
  if (operation === 'VERIFY') return capabilities.capture && capabilities.verification
  return capabilities.capture && capabilities.identification
}
