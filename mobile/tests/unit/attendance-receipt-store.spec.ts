import { describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { SqliteEdgeStore } from '@/storage/SqliteEdgeStore'
import { EdgeSyncService } from '@/services/EdgeSyncService'
import { useAttendanceReceipt } from '@/composables/useAttendanceReceipt'
import { attendanceSyncMessage } from '@/presentation/attendanceResult'
import type { AttendancePayload, AttendanceSyncResult, OutboxStatus } from '@/domain/types'
import type { EdgeApiService } from '@/api/EdgeApiService'
import type { ConnectivityService } from '@/services/ConnectivityService'

function database(values: Record<string, unknown>[] = []) {
  return {
    query: vi.fn(async () => ({ values })),
    run: vi.fn(async () => ({})),
    beginTransaction: vi.fn(async () => undefined),
    commitTransaction: vi.fn(async () => undefined),
    rollbackTransaction: vi.fn(async () => undefined),
  }
}

describe('read-only attendance receipt', () => {
  it.each(['PENDING', 'SYNCING', 'SYNCED', 'REJECTED'] satisfies OutboxStatus[])('reads %s only for the requested UUID', async (status) => {
    const db = database([{ status, last_error_code: status === 'REJECTED' ? 'INVALID_EMPLOYEE' : null }])
    const store = new SqliteEdgeStore()
    Object.assign(store, { db })
    expect(await store.getAttendanceReceipt('event-a')).toEqual({
      status, errorCode: status === 'REJECTED' ? 'INVALID_EMPLOYEE' : null,
    })
    expect(db.query).toHaveBeenCalledExactlyOnceWith(
      'SELECT status, last_error_code FROM sync_outbox WHERE event_uuid=? LIMIT 1', ['event-a'],
    )
    expect(db.run).not.toHaveBeenCalled()
    expect(db.beginTransaction).not.toHaveBeenCalled()
    expect(db.commitTransaction).not.toHaveBeenCalled()
    expect(db.rollbackTransaction).not.toHaveBeenCalled()
  })

  it.each([[], [{ status: 'UNKNOWN' }]])('does not invent a receipt for missing/invalid rows', async (values) => {
    const store = new SqliteEdgeStore()
    Object.assign(store, { db: database(values) })
    expect(await store.getAttendanceReceipt('missing-event')).toBeNull()
  })

  it.each(['STORED', 'DUPLICATE'] as const)('observes SYNCED only after applying the real %s receipt for that UUID', async (status) => {
    const rows = new Map([
      ['event-a', { status: 'SYNCING', last_error_code: null }],
      ['event-b', { status: 'PENDING', last_error_code: null }],
    ])
    const db = database()
    db.query.mockImplementation(async (_sql?: string, params?: string[]) => ({
      values: params && rows.has(params[0]) ? [rows.get(params[0])!] : [],
    }))
    db.run.mockImplementation(async (sql?: string, params?: unknown[]) => {
      expect(sql).toContain("SET status='SYNCED'")
      const row = rows.get(String(params?.[2]))
      if (row) row.status = 'SYNCED'
      return {}
    })
    const store = new SqliteEdgeStore()
    Object.assign(store, { db })
    expect((await store.getAttendanceReceipt('event-a'))?.status).toBe('SYNCING')
    expect(db.run).not.toHaveBeenCalled()
    await store.applyOutboxResults([{ event_uuid: 'event-a', status, remote_id: 'server-receipt' }])
    expect(db.commitTransaction).toHaveBeenCalledOnce()
    expect((await store.getAttendanceReceipt('event-a'))?.status).toBe('SYNCED')
    expect((await store.getAttendanceReceipt('event-b'))?.status).toBe('PENDING')
  })

  it.each(['STORED', 'DUPLICATE', 'REJECTED'] as const)('waits for the immediate sync response (%s), never just ONLINE', async (status) => {
    const row = { status: 'SYNCING', last_error_code: null as string | null }
    const db = database([row])
    db.run.mockImplementation(async (sql?: string, params?: unknown[]) => {
      if (sql?.includes("SET status='SYNCED'")) row.status = 'SYNCED'
      else {
        expect(sql).toContain("SET status='REJECTED'")
        row.status = 'REJECTED'
        row.last_error_code = String(params?.[0])
      }
      return {}
    })
    const store = new SqliteEdgeStore()
    Object.assign(store, { db })
    const payload: AttendancePayload = {
      event_uuid: 'event-a', employee_id: '42', event_type: 'CHECK_IN',
      captured_at: '2026-09-07T03:13:00Z', employee_manifest_version: 5,
      configuration_version: 2, assignment_uuid: 'assignment-a', device_timezone: 'America/Mexico_City',
      location: { latitude: 19.43, longitude: -99.13, accuracy_m: 14.501 },
      geofence: { version: 2, edge_result: 'OUTSIDE' },
    }
    const originalPayload = JSON.stringify(payload)
    vi.spyOn(store, 'getAppliedManifestVersion').mockResolvedValue(2)
    vi.spyOn(store, 'applyBootstrap').mockResolvedValue()
    vi.spyOn(store, 'getPendingOutbox').mockResolvedValue([])
      .mockResolvedValueOnce([{ eventUuid: 'event-a', payload, retryCount: 0 }])
    vi.spyOn(store, 'getSummary').mockResolvedValue({
      machineCode: 'VM-1', deviceStatus: 'ACTIVE', configurationVersion: 2,
      employeeManifestVersion: 5, pendingEvents: 0, lastSyncAt: null,
    })
    let respond!: (results: AttendanceSyncResult[]) => void
    const response = new Promise<AttendanceSyncResult[]>((resolve) => { respond = resolve })
    const sendAttendanceBatch = vi.fn(() => response)
    const api = {
      bootstrap: vi.fn(async () => ({})), sendAttendanceBatch,
      // A later unrelated failure must not hide an already accepted receipt.
      manifestStatus: vi.fn().mockRejectedValue(new Error('TECHNICAL_MANIFEST_ERROR')),
    } as unknown as EdgeApiService
    const sync = new EdgeSyncService(api, store, { current: () => 'ONLINE' } as ConnectivityService)
    const scope = effectScope()
    try {
      const presentation = scope.run(() => useAttendanceReceipt(() => 'event-a', store, sync))!
      const syncing = sync.syncNow('attendance')
      await vi.waitFor(() => expect(sendAttendanceBatch).toHaveBeenCalledOnce())
      await presentation.refresh()
      expect(presentation.receipt.value?.status).toBe('SYNCING')
      expect(attendanceSyncMessage(presentation.receipt.value)).toBe('Enviando asistencia. Esperando confirmación del servidor.')
      expect(attendanceSyncMessage(presentation.receipt.value)).not.toContain('conexión')
      expect(db.run).not.toHaveBeenCalled()
      respond([{ event_uuid: 'event-a', status, error_code: status === 'REJECTED' ? 'INVALID_EMPLOYEE' : undefined }])
      await syncing
      await presentation.refresh()
      expect(presentation.receipt.value?.status).toBe(status === 'REJECTED' ? 'REJECTED' : 'SYNCED')
      expect(attendanceSyncMessage(presentation.receipt.value)).toBe(status === 'REJECTED'
        ? 'No se pudo validar al empleado. Comunícate con tu supervisor.'
        : 'Sincronizada correctamente.')
      if (status !== 'REJECTED') expect(attendanceSyncMessage(presentation.receipt.value)).not.toMatch(/Se enviará|conexión|guardada/)
      expect(sendAttendanceBatch).toHaveBeenCalledWith([payload])
      expect(JSON.stringify(payload)).toBe(originalPayload)
    } finally {
      scope.stop()
    }
  })
})
