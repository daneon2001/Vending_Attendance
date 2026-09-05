import { describe, expect, it } from 'vitest'
import { calculateManifestHash, configurationManifestContent, manifestHashMatches } from '@/security/manifestHash'
import type { ConfigurationManifestResponse } from '@/domain/types'

const manifest: ConfigurationManifestResponse = {
  manifest_type: 'MACHINE_CONFIGURATION',
  manifest_version: 3,
  device: { uuid: 'device-1' },
  machine: { uuid: 'machine-1', machine_code: 'VM-001', status: 'ACTIVE', timezone: 'America/Mexico_City' },
  geofence: {
    uuid: 'geo-1', version: 2, type: 'CIRCLE', latitude: 19, longitude: -99.133209,
    radius_m: 50, minimum_acceptable_accuracy_m: 30, tolerance_m: 10,
  },
  manifest_hash: '219e7d36b857a3d54e9d24fa11407d5f05181a2f85a52873a2261c966479e748',
  generated_at: '2026-09-04T12:00:00Z',
  server_time: '2026-09-04T12:00:00Z',
}

describe('Laravel manifest hash interoperability', () => {
  it('matches PHP key sorting and JSON_PRESERVE_ZERO_FRACTION semantics', async () => {
    expect(await calculateManifestHash(configurationManifestContent(manifest))).toBe(manifest.manifest_hash)
    expect(await manifestHashMatches(manifest)).toBe(true)
  })

  it('rejects changed desired state', async () => {
    expect(await manifestHashMatches({ ...manifest, machine: { ...manifest.machine, status: 'INACTIVE' } })).toBe(false)
  })
})
