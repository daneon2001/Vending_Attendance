import type { ConfigurationManifestResponse, EmployeeManifestResponse } from '@/domain/types'
import { sha256 } from './hmac'

const PHP_FLOAT_PATHS = new Set([
  'geofence.latitude',
  'geofence.longitude',
  'geofence.minimum_acceptable_accuracy_m',
  'geofence.tolerance_m',
])

function canonicalJson(value: unknown, path = ''): string {
  if (value === null) return 'null'
  if (typeof value === 'string' || typeof value === 'boolean') return JSON.stringify(value)
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) throw new TypeError('Manifest numbers must be finite')
    if (PHP_FLOAT_PATHS.has(path) && Number.isInteger(value)) return Object.is(value, -0) ? '-0.0' : `${value}.0`
    return JSON.stringify(value)
  }
  if (Array.isArray(value)) {
    return `[${value.map((item, index) => canonicalJson(item, `${path}.${index}`)).join(',')}]`
  }
  if (typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>)
      .filter(([, item]) => item !== undefined)
      .sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0)
    return `{${entries.map(([key, item]) => {
      const childPath = path ? `${path}.${key}` : key
      return `${JSON.stringify(key)}:${canonicalJson(item, childPath)}`
    }).join(',')}}`
  }
  throw new TypeError('Unsupported manifest value')
}

export function configurationManifestContent(manifest: ConfigurationManifestResponse): Record<string, unknown> {
  return {
    manifest_type: manifest.manifest_type,
    manifest_version: manifest.manifest_version,
    device: manifest.device,
    machine: manifest.machine,
    geofence: manifest.geofence,
  }
}

export function employeeManifestContent(manifest: EmployeeManifestResponse): Record<string, unknown> {
  return {
    manifest_type: manifest.manifest_type,
    manifest_version: manifest.manifest_version,
    machine_uuid: manifest.machine_uuid,
    employees: manifest.employees ?? [],
  }
}

export async function calculateManifestHash(content: Record<string, unknown>): Promise<string> {
  return sha256(canonicalJson(content))
}

export async function manifestHashMatches(
  manifest: ConfigurationManifestResponse | EmployeeManifestResponse,
): Promise<boolean> {
  const content = manifest.manifest_type === 'MACHINE_CONFIGURATION'
    ? configurationManifestContent(manifest)
    : employeeManifestContent(manifest)
  return (await calculateManifestHash(content)).toLowerCase() === manifest.manifest_hash.toLowerCase()
}
