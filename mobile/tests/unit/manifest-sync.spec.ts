import { describe, expect, it, vi } from 'vitest'
import { EdgeSyncService } from '@/services/EdgeSyncService'
import type { EdgeStore } from '@/storage/EdgeStore'
import type { ConnectivityService } from '@/services/ConnectivityService'
import type { EdgeApiService } from '@/api/EdgeApiService'

vi.mock('@capacitor/device', () => ({ Device: { getInfo: vi.fn(async () => ({ osVersion: '15' })) } }))
vi.mock('@capacitor/app', () => ({
  App: {
    getInfo: vi.fn(async () => ({ version: '1.0.0' })),
    addListener: vi.fn(async () => ({ remove: vi.fn() })),
  },
}))
vi.mock('@/security/manifestHash', () => ({ manifestHashMatches: vi.fn(async () => true) }))

describe('manifest synchronization', () => {
  it('persists each full snapshot before sending APPLIED ACK', async () => {
    const calls: string[] = []
    const store = {
      getAppliedManifestVersion: vi.fn(async (type: string) => type === 'CONFIGURATION' ? 1 : 2),
      applyBootstrap: vi.fn(async () => { calls.push('bootstrap-local') }),
      applyConfigurationManifest: vi.fn(async () => { calls.push('configuration-commit') }),
      applyEmployeeManifest: vi.fn(async () => { calls.push('employees-commit') }),
      markManifestAcknowledged: vi.fn(async (type: string) => { calls.push(`${type}-ack-local`) }),
      markManifestAckFailed: vi.fn(),
      getPendingOutbox: vi.fn(async () => []),
      getSummary: vi.fn(async () => ({ machineCode: 'VM-1', deviceStatus: 'ACTIVE', configurationVersion: 3, employeeManifestVersion: 4, pendingEvents: 0, lastSyncAt: null })),
      updateClockDrift: vi.fn(),
    } as unknown as EdgeStore
    const api = {
      bootstrap: vi.fn(async () => ({ device: { uuid: 'd', status: 'ACTIVE' }, machine: { uuid: 'm', machine_code: 'VM-1', status: 'ACTIVE', config_version: 3, timezone: 'America/Mexico_City' }, geofence: null, configuration_changed: true, server_time: '2026-09-04T12:00:00Z', sync: { employee_manifest_version: 4, biometric_manifest_version: null } })),
      manifestStatus: vi.fn(async () => ({ configuration: { changed: true }, employees: { changed: true } })),
      configurationManifest: vi.fn(async () => ({ manifest_type: 'MACHINE_CONFIGURATION', manifest_version: 3, manifest_hash: 'c'.repeat(64), generated_at: '', server_time: '', device: { uuid: 'd' }, machine: { uuid: 'm', machine_code: 'VM-1', status: 'ACTIVE', timezone: 'America/Mexico_City' }, geofence: null })),
      employeeManifest: vi.fn(async () => ({ manifest_type: 'EMPLOYEES', manifest_version: 4, manifest_hash: 'e'.repeat(64), generated_at: '', server_time: '', machine_uuid: 'm', employees: [] })),
      acknowledgeManifest: vi.fn(async ({ manifestType }: { manifestType: string }) => { calls.push(`${manifestType}-ack-server`); return {} }),
      heartbeat: vi.fn(async () => ({ clock_drift_seconds: 0, clock_drift_warning: false })),
    } as unknown as EdgeApiService
    const connectivity = { current: () => 'ONLINE' } as unknown as ConnectivityService
    const service = new EdgeSyncService(api, store, connectivity)
    await service.syncNow('manual')
    expect(calls.indexOf('configuration-commit')).toBeLessThan(calls.indexOf('CONFIGURATION-ack-server'))
    expect(calls.indexOf('employees-commit')).toBeLessThan(calls.indexOf('EMPLOYEES-ack-server'))
    expect(api.employeeManifest).toHaveBeenCalledWith(null)
  })

  it('does not download unchanged manifests', async () => {
    const store = {
      getAppliedManifestVersion: vi.fn(async () => 7), applyBootstrap: vi.fn(), getPendingOutbox: vi.fn(async () => []),
      getSummary: vi.fn(async () => ({ configurationVersion: 7, pendingEvents: 0 })), updateClockDrift: vi.fn(),
    } as unknown as EdgeStore
    const api = {
      bootstrap: vi.fn(async () => ({})), manifestStatus: vi.fn(async () => ({ configuration: { changed: false }, employees: { changed: false } })),
      configurationManifest: vi.fn(), employeeManifest: vi.fn(), heartbeat: vi.fn(async () => ({ clock_drift_seconds: 0, clock_drift_warning: false })),
    } as unknown as EdgeApiService
    const service = new EdgeSyncService(api, store, { current: () => 'ONLINE' } as unknown as ConnectivityService)
    await service.syncNow('manual')
    expect(api.configurationManifest).not.toHaveBeenCalled()
    expect(api.employeeManifest).not.toHaveBeenCalled()
  })

  it('starts a foreground sync when connectivity is restored', async () => {
    let networkListener: ((state: string) => void) | undefined
    let connectivityState = 'OFFLINE'
    const connectivity = {
      start: vi.fn(), stop: vi.fn(), current: () => connectivityState,
      subscribe: (listener: (state: string) => void) => { networkListener = listener; listener(connectivityState); return vi.fn() },
    } as unknown as ConnectivityService
    const store = {
      getAppliedManifestVersion: vi.fn(async () => 1), applyBootstrap: vi.fn(),
      getPendingOutbox: vi.fn(async () => []),
      getSummary: vi.fn(async () => ({ configurationVersion: 1, pendingEvents: 0 })),
      updateClockDrift: vi.fn(),
    } as unknown as EdgeStore
    const api = {
      bootstrap: vi.fn(async () => ({})),
      manifestStatus: vi.fn(async () => ({ configuration: { changed: false }, employees: { changed: false } })),
      heartbeat: vi.fn(async () => ({ clock_drift_seconds: 0, clock_drift_warning: false })),
    } as unknown as EdgeApiService
    const service = new EdgeSyncService(api, store, connectivity)
    await service.start()
    connectivityState = 'ONLINE'
    networkListener?.('ONLINE')
    await vi.waitFor(() => expect(api.bootstrap).toHaveBeenCalledOnce())
    await service.stop()
  })
})
