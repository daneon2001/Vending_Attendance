import { afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope, ref } from 'vue'
import { useAttendanceReceipt } from '@/composables/useAttendanceReceipt'
import { attendanceSyncMessage } from '@/presentation/attendanceResult'
import type { AttendanceReceipt } from '@/storage/EdgeStore'
import type { SyncViewState } from '@/services/EdgeSyncService'

const scopes: ReturnType<typeof effectScope>[] = []
afterEach(() => { scopes.splice(0).forEach((scope) => scope.stop()) })

function harness(initialUuid: string | null = null) {
  const event = ref(initialUuid)
  const read = vi.fn<(uuid: string) => Promise<AttendanceReceipt | null>>()
    .mockResolvedValue({ status: 'PENDING', errorCode: null })
  let listener: (state: SyncViewState) => void = () => undefined
  const unsubscribe = vi.fn()
  const scope = effectScope()
  scopes.push(scope)
  const presentation = scope.run(() => useAttendanceReceipt(() => event.value, {
    getAttendanceReceipt: read,
  }, {
    subscribe: (next) => { listener = next; return unsubscribe },
  }))!
  const notify = (phase: SyncViewState['phase'] = 'IDLE') => listener({
    phase, message: 'Sincronización completa.',
    summary: { machineCode: 'VM-1', deviceStatus: 'ACTIVE', configurationVersion: 2,
      employeeManifestVersion: 5, pendingEvents: 0, lastSyncAt: '2026-09-07T03:13:00Z' },
    clockDriftWarning: false,
  })
  return { event, read, presentation, notify, scope, unsubscribe }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => { resolve = done })
  return { promise, resolve }
}

describe('per-event receipt presentation', () => {
  it('does not query without a captured event', async () => {
    const h = harness()
    h.notify()
    await h.presentation.refresh()
    expect(h.read).not.toHaveBeenCalled()
    expect(h.presentation.receipt.value).toBeNull()
  })

  it('does not infer success from connectivity, global completion or zero pending events', async () => {
    const h = harness('event-a')
    h.notify('IDLE')
    await h.presentation.refresh()
    expect(h.read).toHaveBeenLastCalledWith('event-a')
    expect(h.presentation.receipt.value?.status).toBe('PENDING')
    expect(attendanceSyncMessage(h.presentation.receipt.value)).not.toContain('Sincronizada correctamente')
  })

  it.each(['IDLE', 'ERROR'] as const)('reads the individual persisted confirmation after sync %s', async (phase) => {
    const h = harness('event-a')
    await h.presentation.refresh()
    expect(h.presentation.receipt.value?.status).toBe('PENDING')
    h.read.mockResolvedValue({ status: 'SYNCED', errorCode: null })
    h.notify(phase)
    await vi.waitFor(() => expect(h.presentation.receipt.value?.status).toBe('SYNCED'))
    // Later manifest/heartbeat errors do not erase a confirmed attendance receipt.
    expect(attendanceSyncMessage(h.presentation.receipt.value)).toBe('Sincronizada correctamente.')
    expect(attendanceSyncMessage(h.presentation.receipt.value)).not.toMatch(/Se enviará|conexión|guardada/)
  })

  it('refreshes rejection reasons without publishing technical codes', async () => {
    const h = harness('event-a')
    h.read.mockResolvedValue({ status: 'REJECTED', errorCode: 'INVALID_EMPLOYEE' })
    await h.presentation.refresh()
    expect(attendanceSyncMessage(h.presentation.receipt.value)).toContain('No se pudo validar al empleado.')
    expect(attendanceSyncMessage(h.presentation.receipt.value)).not.toContain('INVALID_EMPLOYEE')
  })

  it('does not confirm a newly captured event using a late response for the previous event', async () => {
    const h = harness()
    const oldRead = deferred<AttendanceReceipt | null>()
    h.read.mockReturnValueOnce(oldRead.promise)
    h.event.value = 'event-a'
    h.event.value = 'event-b'
    await h.presentation.refresh()
    oldRead.resolve({ status: 'SYNCED', errorCode: null })
    await oldRead.promise
    expect(h.read).toHaveBeenLastCalledWith('event-b')
    expect(h.presentation.receipt.value?.status).toBe('PENDING')
  })

  it('does not overwrite a newer confirmation with an older pending read', async () => {
    const h = harness()
    const oldRead = deferred<AttendanceReceipt | null>()
    h.read.mockReturnValueOnce(oldRead.promise)
    h.event.value = 'event-a'
    h.read.mockResolvedValue({ status: 'SYNCED', errorCode: null })
    await h.presentation.refresh()
    oldRead.resolve({ status: 'PENDING', errorCode: null })
    await oldRead.promise
    expect(h.presentation.receipt.value?.status).toBe('SYNCED')
  })

  it('handles failed reads without exposing the error or inventing success', async () => {
    const h = harness('event-a')
    h.read.mockRejectedValue(new Error('INTERNAL_SQLITE_ERROR private stack'))
    await expect(h.presentation.refresh()).resolves.toBeUndefined()
    expect(h.presentation.receipt.value).toBeNull()
    expect(attendanceSyncMessage(h.presentation.receipt.value)).toContain('Aún no se ha confirmado')
    expect(attendanceSyncMessage(h.presentation.receipt.value)).not.toContain('INTERNAL_SQLITE_ERROR')
  })

  it('clears the previous receipt and unsubscribes on disposal', async () => {
    const h = harness('event-a')
    await h.presentation.refresh()
    h.event.value = null
    expect(h.presentation.receipt.value).toBeNull()
    h.scope.stop()
    h.read.mockClear()
    h.notify()
    expect(h.unsubscribe).toHaveBeenCalledOnce()
    expect(h.read).not.toHaveBeenCalled()
  })
})
