import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Geolocation, type Position } from '@capacitor/geolocation'
import { CapacitorLocationService } from '@/services/LocationService'
import { AttendanceCaptureService } from '@/services/AttendanceCaptureService'
import { GeofenceValidationService } from '@/services/GeofenceValidationService'
import { EdgeSyncService } from '@/services/EdgeSyncService'
import { SqliteEdgeStore } from '@/storage/SqliteEdgeStore'
import { EdgeError } from '@/domain/errors'
import { attendanceSyncMessage } from '@/presentation/attendanceResult'
import type { EffectiveEmployee } from '@/storage/EdgeStore'
import type { AttendancePayload, ConnectivityState } from '@/domain/types'
import type { ConnectivityService } from '@/services/ConnectivityService'
import type { EdgeApiService } from '@/api/EdgeApiService'

vi.mock('@capacitor/geolocation', () => ({
  Geolocation: { checkPermissions: vi.fn(), requestPermissions: vi.fn(), getCurrentPosition: vi.fn() },
}))
vi.mock('@capacitor/app', () => ({ App: { getInfo: vi.fn(async () => ({ version: '1.0', build: '1' })) } }))
vi.mock('@capacitor/device', () => ({ Device: { getInfo: vi.fn(async () => ({ osVersion: '15' })) } }))

const employee: EffectiveEmployee = {
  employee_id: '42', employee_number: 'test-42', name: 'Empleado de prueba', effective: true,
  assignment: {
    uuid: 'assignment-test', type: 'PRIMARY', valid_from: '2026-09-01T00:00:00Z', valid_until: null,
    attendance_allowed: true, enrollment_allowed: false, maintenance_allowed: false,
  },
}
const freshPosition = (): Position => ({
  timestamp: Date.now(),
  coords: { latitude: 19.432608, longitude: -99.133209, accuracy: 14.501,
    altitude: null, altitudeAccuracy: null, speed: null, heading: null },
})

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime('2026-09-07T03:13:00Z')
  vi.mocked(Geolocation.checkPermissions).mockResolvedValue({ location: 'granted', coarseLocation: 'granted' })
  vi.mocked(Geolocation.getCurrentPosition).mockImplementation(async () => freshPosition())
})
afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); vi.resetAllMocks() })

function harness() {
  let network: ConnectivityState = 'OFFLINE'
  const current = vi.fn(() => network)
  // Only the native SQLite boundary is doubled; production enqueue, claim,
  // receipt application, location, geofence and sync orchestration are exercised.
  const events = new Map<string, string>()
  const outbox = new Map<string, { status: string; error: string | null }>()
  const db = {
    beginTransaction: vi.fn(async () => undefined),
    commitTransaction: vi.fn(async () => undefined),
    rollbackTransaction: vi.fn(async () => undefined),
    run: vi.fn(async (sql: string, values: unknown[]) => {
      if (sql.includes('INSERT INTO attendance_events')) events.set(String(values[0]), String(values[5]))
      else if (sql.includes('INSERT INTO sync_outbox')) {
        expect(sql).toContain("'PENDING'")
        outbox.set(String(values[0]), { status: 'PENDING', error: null })
      } else if (sql.includes("SET status='SYNCING'")) outbox.get(String(values[1]))!.status = 'SYNCING'
      else if (sql.includes("SET status='SYNCED'")) outbox.get(String(values[2]))!.status = 'SYNCED'
      else throw new Error('Unexpected SQL in test adapter')
      return {}
    }),
    query: vi.fn(async (sql: string, values: unknown[] = []) => {
      if (sql.includes('JOIN attendance_events')) return {
        values: [...outbox.entries()].filter(([, row]) => row.status === 'PENDING').map(([uuid]) => ({
          event_uuid: uuid, retry_count: 0, payload_json: events.get(uuid),
        })),
      }
      if (sql.startsWith('SELECT status, last_error_code')) {
        const row = outbox.get(String(values[0]))
        return { values: row ? [{ status: row.status, last_error_code: row.error }] : [] }
      }
      throw new Error('Unexpected query in test adapter')
    }),
  }
  const store = new SqliteEdgeStore()
  Object.assign(store, { db })
  vi.spyOn(store, 'getAttendanceContext').mockResolvedValue({
    timezone: 'America/Mexico_City', configurationVersion: 2, employeeManifestVersion: 5,
    geofence: { uuid: 'geofence-test', version: 2, type: 'CIRCLE', latitude: 19.432608, longitude: -99.133209,
      radius_m: 100, minimum_acceptable_accuracy_m: 30, tolerance_m: 10 },
  })
  vi.spyOn(store, 'getSummary').mockImplementation(async () => ({
    machineCode: 'VM-TEST', deviceStatus: 'ACTIVE', configurationVersion: 2, employeeManifestVersion: 5,
    pendingEvents: [...outbox.values()].filter((row) => row.status !== 'SYNCED').length, lastSyncAt: null,
  }))
  vi.spyOn(store, 'getAppliedManifestVersion').mockResolvedValue(2)
  vi.spyOn(store, 'applyBootstrap').mockResolvedValue()
  vi.spyOn(store, 'updateClockDrift').mockResolvedValue()
  const enqueue = vi.spyOn(store, 'enqueueAttendance')
  const api = {
    bootstrap: vi.fn(async () => ({})),
    sendAttendanceBatch: vi.fn(async (payloads: AttendancePayload[]) => {
      expect(db.commitTransaction).toHaveBeenCalled()
      return payloads.map((payload) => ({ event_uuid: payload.event_uuid, status: 'STORED' as const }))
    }),
    manifestStatus: vi.fn(async () => ({ configuration: { changed: false }, employees: { changed: false } })),
    heartbeat: vi.fn(async () => ({ clock_drift_seconds: 0, clock_drift_warning: false, next_heartbeat_seconds: 60 })),
  }
  const capture = new AttendanceCaptureService(store, new CapacitorLocationService(45_000), new GeofenceValidationService())
  const sync = new EdgeSyncService(api as unknown as EdgeApiService, store, { current } as unknown as ConnectivityService)
  return { capture, sync, store, enqueue, api, db, events, outbox, current, online: () => { network = 'ONLINE' } }
}

describe('offline GPS attendance and durable outbox ordering', () => {
  it('writes neither attendance nor outbox when the native GPS request times out', async () => {
    const h = harness()
    vi.mocked(Geolocation.getCurrentPosition).mockRejectedValue({ code: 'OS-PLUG-GLOC-0010' })
    await expect(h.capture.capture(employee, 'CHECK_IN')).rejects.toMatchObject({ code: 'GPS_TIMEOUT' })
    expect(h.enqueue).not.toHaveBeenCalled()
    expect(h.db.run).not.toHaveBeenCalled()
    expect(h.db.beginTransaction).not.toHaveBeenCalled()
    expect(h.events.size).toBe(0)
    expect(h.outbox.size).toBe(0)
    expect(h.api.bootstrap).not.toHaveBeenCalled()
  })

  it('never persists a late native fix after the application GPS deadline', async () => {
    const h = harness()
    let resolvePosition!: (position: Position) => void
    vi.mocked(Geolocation.getCurrentPosition).mockImplementation(() => new Promise((resolve) => { resolvePosition = resolve }))
    const capture = h.capture.capture(employee, 'CHECK_IN')
    const rejected = expect(capture).rejects.toMatchObject({ code: 'GPS_TIMEOUT' })
    await vi.advanceTimersByTimeAsync(45_000)
    await rejected
    resolvePosition(freshPosition())
    await vi.advanceTimersByTimeAsync(1000)
    expect(h.enqueue).not.toHaveBeenCalled()
    expect(h.db.run).not.toHaveBeenCalled()
    expect(h.events.size).toBe(0)
    expect(h.outbox.size).toBe(0)
  })

  it('rejects cached evidence before any persistence', async () => {
    const h = harness()
    vi.mocked(Geolocation.getCurrentPosition).mockResolvedValue({ ...freshPosition(), timestamp: Date.now() - 60_000 })
    await expect(h.capture.capture(employee, 'CHECK_IN')).rejects.toMatchObject({ code: 'GPS_UNAVAILABLE' })
    expect(h.db.run).not.toHaveBeenCalled()
  })

  it.each(['CHECK_IN', 'CHECK_OUT'] as const)('creates %s offline, then synchronizes the same UUID and payload', async (type) => {
    const h = harness()
    const result = await h.capture.capture(employee, type)
    const uuid = result.payload.event_uuid
    const originalPayload = JSON.stringify(result.payload)
    expect(result.payload.event_type).toBe(type)
    expect(result.evaluation.result).toBe('INSIDE')
    expect(result.payload.location).toEqual({ latitude: 19.432608, longitude: -99.133209, accuracy_m: 14.501 })
    expect(h.current).not.toHaveBeenCalled()
    expect(h.api.bootstrap).not.toHaveBeenCalled()
    expect(h.events.get(uuid)).toBe(originalPayload)
    expect(h.events.size).toBe(1)
    expect(h.outbox.get(uuid)?.status).toBe('PENDING')
    expect(h.db.beginTransaction).toHaveBeenCalledOnce()
    expect(h.db.commitTransaction).toHaveBeenCalledOnce()
    await h.sync.syncNow('attendance')
    expect(h.api.bootstrap).not.toHaveBeenCalled()
    expect(h.api.sendAttendanceBatch).not.toHaveBeenCalled()
    expect(attendanceSyncMessage(await h.store.getAttendanceReceipt(uuid))).toContain('Se enviará automáticamente')
    h.online()
    await h.sync.syncNow('network-restored')
    expect(h.api.sendAttendanceBatch).toHaveBeenCalledExactlyOnceWith([result.payload])
    expect((await h.store.getAttendanceReceipt(uuid))?.status).toBe('SYNCED')
    expect(h.events.get(uuid)).toBe(originalPayload)
    expect(h.events.size).toBe(1)
    expect(h.outbox.size).toBe(1)
    expect(h.enqueue).toHaveBeenCalledOnce()
    expect(Geolocation.getCurrentPosition).toHaveBeenCalledOnce()
  })

  it('keeps valid attendance pending if the network reports ONLINE but the server is unreachable', async () => {
    const h = harness()
    h.online()
    h.api.bootstrap.mockRejectedValue(new EdgeError('NETWORK_TIMEOUT', 'Tiempo de conexión agotado.', true))
    const result = await h.capture.capture(employee, 'CHECK_IN')
    expect(h.api.bootstrap).not.toHaveBeenCalled()
    await h.sync.syncNow('attendance')
    expect(h.api.bootstrap).toHaveBeenCalledOnce()
    expect(h.events.has(result.payload.event_uuid)).toBe(true)
    expect(h.outbox.get(result.payload.event_uuid)?.status).toBe('PENDING')
    expect(Geolocation.getCurrentPosition).toHaveBeenCalledOnce()
  })

  it.each([
    { latitude: 0, longitude: 0, accuracy: 5 },
    { latitude: 91, longitude: -99, accuracy: 5 },
    { latitude: 19, longitude: -181, accuracy: 5 },
    { latitude: 19, longitude: -99, accuracy: -1 },
    { latitude: NaN, longitude: -99, accuracy: 5 },
  ])('never creates attendance without valid coordinate evidence: %j', async (coords) => {
    const h = harness()
    vi.mocked(Geolocation.getCurrentPosition).mockResolvedValue({
      ...freshPosition(), coords: { ...freshPosition().coords, ...coords },
    })
    await expect(h.capture.capture(employee, 'CHECK_IN')).rejects.toBeInstanceOf(EdgeError)
    expect(h.events.size).toBe(0)
    expect(h.outbox.size).toBe(0)
    expect(h.db.run).not.toHaveBeenCalled()
  })

  it('does not turn poor precision into an INSIDE result', async () => {
    const h = harness()
    vi.mocked(Geolocation.getCurrentPosition).mockResolvedValue({
      ...freshPosition(), coords: { ...freshPosition().coords, accuracy: 200 },
    })
    const result = await h.capture.capture(employee, 'CHECK_IN')
    expect(result.evaluation.result).toBe('UNCERTAIN')
    expect(result.payload.geofence.edge_result).toBe('UNCERTAIN')
    expect(result.payload.location.accuracy_m).toBe(200)
  })
})
