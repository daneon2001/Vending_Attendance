import type {
  BiometricCapabilities,
  BiometricEnrollmentRequest,
  BiometricHealthCheck,
  BiometricModality,
  BiometricOperation,
  BiometricOperationOutcome,
  BiometricPlatform,
  BiometricProvider,
  BiometricTemplateDeletionOutcome,
  BiometricTemplateDeletionRequest,
  BiometricVerificationRequest,
} from './contracts'

/**
 * Safe default used until a real, licensed native SDK is selected.
 * It never simulates capture or returns MATCH.
 */
export class UnsupportedBiometricProvider implements BiometricProvider {
  readonly id = 'unsupported'

  constructor(
    private readonly platform: BiometricPlatform,
    private readonly modalities: readonly BiometricModality[] = ['FINGERPRINT', 'FACE'],
  ) {}

  async capabilities(): Promise<readonly BiometricCapabilities[]> {
    return this.modalities.map((modality) => ({
      platform: this.platform,
      provider: this.id,
      modality,
      capture: false,
      enrollment: false,
      verification: false,
      identification: false,
      offlineMatching: false,
      templateExport: false,
      templateImport: false,
      hardwareRequired: false,
      hardwareConnections: [],
    }))
  }

  async enroll(request: BiometricEnrollmentRequest): Promise<BiometricOperationOutcome> {
    return this.notSupported('ENROLL', request.modality)
  }

  async verify(request: BiometricVerificationRequest): Promise<BiometricOperationOutcome> {
    return this.notSupported('VERIFY', request.modality)
  }

  async deleteTemplate(request: BiometricTemplateDeletionRequest): Promise<BiometricTemplateDeletionOutcome> {
    return {
      provider: this.id,
      templateUuid: request.templateUuid,
      result: 'NOT_SUPPORTED',
      reason: 'TEMPLATE_DELETION_NOT_SUPPORTED',
    }
  }

  async healthCheck(): Promise<BiometricHealthCheck> {
    return {
      provider: this.id,
      state: 'NOT_SUPPORTED',
      reason: 'NO_VENDOR_SDK_CONFIGURED',
    }
  }

  private notSupported(
    operation: BiometricOperation,
    modality: BiometricModality,
    reason = 'NO_VENDOR_SDK_CONFIGURED',
  ): BiometricOperationOutcome {
    return {
      operation,
      modality,
      provider: this.id,
      result: 'NOT_SUPPORTED',
      reason,
    }
  }
}
