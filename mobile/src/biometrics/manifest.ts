import type { EmployeeBiometricTemplateMetadata, BiometricTemplateDelivery } from './template'
import { validateTemplateDelivery, validateTemplateMetadata } from './template'

export interface BiometricManifestEntry {
  employeeIdentifier: string
  assignmentUuid: string
  template: EmployeeBiometricTemplateMetadata
  hashSha256: string
  delivery: BiometricTemplateDelivery
}

export interface BiometricManifestSnapshot {
  manifestType: 'BIOMETRICS'
  supported: true
  desiredState: 'FULL_SNAPSHOT'
  scope: 'EFFECTIVE_EMPLOYEE_MACHINE_ASSIGNMENTS'
  manifestVersion: number
  manifestHash: string
  generatedAt: string
  serverTime: string
  machineUuid: string
  entries: readonly BiometricManifestEntry[]
}

export interface UnsupportedBiometricManifestStatus {
  supported: false
  serverVersion: null
  appliedVersion: null
  changed: false
}

export function unsupportedBiometricManifestStatus(): UnsupportedBiometricManifestStatus {
  return {
    supported: false,
    serverVersion: null,
    appliedVersion: null,
    changed: false,
  }
}

/**
 * Stable hash material: volatile timestamps and the hash itself are excluded;
 * entries are ordered by template UUID before canonical JSON hashing.
 */
export function biometricManifestHashContent(
  manifest: BiometricManifestSnapshot,
): Record<string, unknown> {
  return {
    manifest_type: manifest.manifestType,
    supported: manifest.supported,
    desired_state: manifest.desiredState,
    scope: manifest.scope,
    manifest_version: manifest.manifestVersion,
    machine_uuid: manifest.machineUuid,
    entries: [...manifest.entries].sort((left, right) => {
      if (left.template.uuid < right.template.uuid) return -1
      if (left.template.uuid > right.template.uuid) return 1
      return 0
    }),
  }
}

export function validateBiometricManifest(manifest: BiometricManifestSnapshot): readonly string[] {
  const errors: string[] = []
  if (manifest.scope !== 'EFFECTIVE_EMPLOYEE_MACHINE_ASSIGNMENTS') {
    errors.push('GLOBAL_SCOPE_FORBIDDEN')
  }
  if (manifest.machineUuid.trim() === '') errors.push('MACHINE_UUID_REQUIRED')
  if (!Number.isSafeInteger(manifest.manifestVersion) || manifest.manifestVersion < 1) {
    errors.push('MANIFEST_VERSION_INVALID')
  }

  for (const entry of manifest.entries) {
    if (entry.employeeIdentifier.trim() === '') errors.push('EMPLOYEE_IDENTIFIER_REQUIRED')
    if (entry.assignmentUuid.trim() === '') errors.push('ASSIGNMENT_UUID_REQUIRED')
    if (!/^[a-f0-9]{64}$/i.test(entry.hashSha256)) errors.push('TEMPLATE_HASH_INVALID')
    errors.push(...validateTemplateMetadata(entry.template))
    errors.push(...validateTemplateDelivery(entry.delivery))
  }

  return errors
}
