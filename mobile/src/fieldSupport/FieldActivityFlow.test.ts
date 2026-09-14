import { describe, expect, it, vi } from 'vitest'
import { FieldActivityFlow, activityStatus, zoneLabel, emptyActivitiesMessage, activityNotificationLabel, type Activity, type Operation, type PendingOperation } from './FieldActivityFlow'
import { FieldMobileError } from '@/fieldIdentity/FieldMobileTransport'

function fixture() {
  let pending: PendingOperation | null = null
  let server: Activity = { uuid: 'fixture-activity', title: 'Prueba', status: 'ASSIGNED', type_label: 'Mantenimiento', employee: 'Fixture', machine: 'TEST',
    geofence: { uuid: 'fixture-zone', version: 1, type: 'CIRCLE', latitude: 19.4326, longitude: -99.1332, radius_m: 50, tolerance_m: 10, minimum_acceptable_accuracy_m: 30 } }
  let failExecute = false
  let serverResult = 'INSIDE'
  const operations: Operation[] = []
  const api = { origin: 'https://field.test', post: vi.fn(async (action: string, body: Record<string, unknown>) => {
    if (action === 'activities/capability') return { available: true, device_uuid: 'fixture-device' }
    if (action === 'activities/challenge') return { challenge_uuid: 'fixture-proof', message: 'FIELD_MOBILE_V1\n{}' }
    const op = body.operation as Operation
    if (op.action === 'list') return { data: [structuredClone(server)], page: 1, has_more: false }
    if (op.action === 'detail') return { activity: structuredClone(server) }
    operations.push(structuredClone(op))
    if (failExecute) throw new FieldMobileError(0)
    server = { ...server, status: op.action === 'start' ? 'IN_PROGRESS' : 'COMPLETED' }
    return { activity: structuredClone(server), confirmed: true, geofence_result: serverResult }
  }) }
  const keys = { createKey: vi.fn(), sign: vi.fn(async () => ({ signature: 'fixture-signature' })) }
  const gps = { capture: vi.fn(async () => ({ latitude: 19.4326, longitude: -99.1332, accuracy_m: 5, captured_at: new Date().toISOString() })) }
  const store = { pending: async () => pending, savePending: async (value: PendingOperation | null) => { pending = value } }
  const make = () => new FieldActivityFlow(api as never, keys, gps, store)
  return { flow: make(), make, api, keys, gps, operations, store,
    fail: (value: boolean) => { failExecute = value }, result: (value: string) => { serverResult = value } }
}

describe('Field Support online', () => {
  it('offline empty cache explains device availability without implying unassignment or data loss', async () => {
    const f = fixture()
    const offline = { prepare: async () => ({ deviceUuid: 'fixture-device', offline: true }), cached: async () => [] }
    const flow = new FieldActivityFlow(f.api as never, f.keys, f.gps, f.store, undefined, offline as never)
    await flow.open()
    expect(flow.rows).toEqual([]); expect(flow.error).toBe(''); expect(flow.notifications).toBeNull()
    expect(f.api.post).not.toHaveBeenCalled()
    expect(emptyActivitiesMessage(true)).toBe('No hay actividades disponibles en este dispositivo. Conéctate a la red para actualizar tus actividades.')
    expect(emptyActivitiesMessage(false)).toBe('No tienes actividades asignadas.')
  })
  it('activity notices reuse signed transport and confirmed counts without offline operations', async () => {
    const f = fixture()
    const notice = { id: 'notice', activity_uuid: 'fixture-activity', folio: 'ACT-000001', kind: 'support_activity.assigned', created_at: '2026-09-10T18:00:00Z', read_at: null }
    const original = f.api.post.getMockImplementation()!
    f.api.post.mockImplementation(async (action, body) => {
      if (action === 'activities/execute' && (body.operation as Operation).action === 'list') return { data: [], page: 1, has_more: false, notifications: { notifications: [notice], unread_count: 1 } } as never
      if (action === 'activities/execute' && (body.operation as Operation).action === 'notification_read') return { notifications: [{ ...notice, read_at: notice.created_at }], unread_count: 0 } as never
      return original(action, body)
    })
    await f.flow.open(); expect(f.flow.notifications?.unread_count).toBe(1)
    await f.flow.markNotificationRead(notice); expect(f.flow.notifications?.unread_count).toBe(0)
    expect(f.keys.sign).toHaveBeenCalledTimes(2); expect(await f.store.pending()).toBeNull()
    expect(f.gps.capture).not.toHaveBeenCalled(); expect(f.operations).toEqual([])
    expect(activityNotificationLabel(notice.kind)).toBe('Actividad asignada')
    expect(activityNotificationLabel('support_activity.completed')).toBe('Actividad completada')
    expect(activityNotificationLabel('support_activity.cancelled')).toBe('Actividad cancelada')
    f.api.post.mockRejectedValue(new FieldMobileError(403))
    await f.flow.markNotificationRead(notice); expect(f.flow.notifications).toBeNull()
    expect(f.flow.error).not.toBe('')
  })
  it.each([0, 401])('missing offline context distinguishes connectivity from unauthorized session (%s)', async status => {
    const f = fixture()
    const offline = { prepare: async () => { throw new FieldMobileError(status) } }
    const flow = new FieldActivityFlow(f.api as never, f.keys, f.gps, f.store, undefined, offline as never)
    await flow.open()
    expect(flow.rows).toEqual([]); expect(flow.activity).toBeNull(); expect(flow.notifications).toBeNull()
    expect(f.api.post).not.toHaveBeenCalled(); expect(f.keys.sign).not.toHaveBeenCalled()
    expect(flow.error).toBe(status === 0 ? emptyActivitiesMessage(true) : new FieldMobileError(401).message)
  })
  it('lists and opens only server-confirmed activities using existing key', async () => {
    const f = fixture(); await f.flow.open(); expect(f.flow.rows).toHaveLength(1)
    await f.flow.open('fixture-activity'); expect(f.flow.activity?.status).toBe('ASSIGNED')
    expect(f.keys.createKey).not.toHaveBeenCalled(); expect(f.keys.sign).toHaveBeenCalledTimes(2)
  })
  it('captures fresh GPS, evaluates INSIDE and waits for server start confirmation', async () => {
    const f = fixture(); await f.flow.open('fixture-activity'); await f.flow.start()
    expect(f.gps.capture).toHaveBeenCalledTimes(1)
    expect(f.flow.edgeResult).toBe('INSIDE'); expect(f.flow.serverResult).toBe('INSIDE')
    expect(f.flow.activity?.status).toBe('IN_PROGRESS'); expect(f.flow.pending).toBeNull()
  })
  it.each([['OUTSIDE', 20, 5], ['UNCERTAIN', 19.4326, 100]])('blocks %s before START', async (result, latitude, accuracy) => {
    const f = fixture(); f.gps.capture.mockResolvedValue({ latitude: Number(latitude), longitude: -99.1332, accuracy_m: Number(accuracy), captured_at: new Date().toISOString() })
    await f.flow.open('fixture-activity'); await f.flow.start()
    expect(f.flow.edgeResult).toBe(result); expect(f.operations).toHaveLength(0); expect(f.flow.activity?.status).toBe('ASSIGNED')
  })
  it('GPS failure does not send START or disclose native exception', async () => {
    const f = fixture(); f.gps.capture.mockRejectedValue(new Error('PRIVATE NATIVE DETAILS'))
    await f.flow.open('fixture-activity'); await f.flow.start()
    expect(f.operations).toHaveLength(0); expect(f.flow.error).not.toContain('PRIVATE')
  })
  it('restart recovers server IN_PROGRESS; COMPLETE is START_ONLY_V1', async () => {
    const f = fixture(); await f.flow.open('fixture-activity'); await f.flow.start()
    const next = f.make(); await next.open('fixture-activity'); expect(next.activity?.status).toBe('IN_PROGRESS')
    await next.complete(); expect(next.activity?.status).toBe('COMPLETED')
    expect(f.gps.capture).toHaveBeenCalledTimes(1); expect(f.operations[1]?.location).toBeUndefined()
  })
  it('network failure is not success; manual retry after restart keeps operation id and GPS', async () => {
    const f = fixture(); await f.flow.open('fixture-activity'); f.fail(true); await f.flow.start()
    expect(f.flow.activity?.status).toBe('ASSIGNED'); expect(f.flow.pending).not.toBeNull()
    const next = f.make(); await next.open('fixture-activity'); f.fail(false); await next.retry()
    expect(f.operations[0]).toEqual(f.operations[1]); expect(f.gps.capture).toHaveBeenCalledTimes(1)
    expect(next.activity?.status).toBe('IN_PROGRESS'); expect(next.pending).toBeNull()
  })
  it('mismatched server result stops further execution', async () => {
    const f = fixture(); await f.flow.open('fixture-activity'); f.result('OUTSIDE'); await f.flow.start()
    expect(f.flow.mismatch).toBe(true); await f.flow.complete(); expect(f.operations).toHaveLength(1)
  })
  it('denied capability never displays a locally fabricated list', async () => {
    const f = fixture(); f.api.post.mockRejectedValue(new FieldMobileError(403)); await f.flow.open()
    expect(f.flow.rows).toEqual([]); expect(f.flow.error).toContain('autorizar')
  })
  it('uses Spanish presentation without changing enums', () => {
    expect(activityStatus('ASSIGNED')).toBe('Asignada'); expect(activityStatus('IN_PROGRESS')).toBe('En progreso')
    expect(activityStatus('COMPLETED')).toBe('Completada'); expect(zoneLabel('INSIDE')).toBe('Dentro de la zona permitida')
    expect(zoneLabel('OUTSIDE')).toBe('Fuera de la zona permitida'); expect(zoneLabel('UNCERTAIN')).toContain('confirmar')
  })
})
