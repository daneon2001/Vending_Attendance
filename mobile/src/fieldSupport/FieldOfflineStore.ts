import { hashBytes } from '@/support/SupportApiClient'
import { SqliteSupportStore } from '@/support/SqliteSupportStore'
import { assertUuid, SupportError, type LocalEvidence, type SupportOperation } from '@/support/types'
import type { Activity, Operation } from './FieldActivityFlow'

export interface FieldScope { key: string; deviceUuid: string; origin: string; sessionHash: string }
export interface FieldCapture { scope: string; activityUuid: string; evidence: LocalEvidence; confirmed: boolean; preview: boolean }
export const FIELD_SCHEMA = `
CREATE TABLE IF NOT EXISTS field_support_context (scope TEXT PRIMARY KEY, device_uuid TEXT NOT NULL, origin TEXT NOT NULL, session_hash TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS field_support_activities (scope TEXT NOT NULL, uuid TEXT NOT NULL, data_json TEXT NOT NULL, PRIMARY KEY(scope,uuid));
CREATE TABLE IF NOT EXISTS field_support_captures (uuid TEXT PRIMARY KEY, scope TEXT NOT NULL, activity_uuid TEXT NOT NULL, data_json TEXT NOT NULL, state TEXT NOT NULL,
 FOREIGN KEY(scope,activity_uuid) REFERENCES field_support_activities(scope,uuid) ON DELETE RESTRICT);
CREATE INDEX IF NOT EXISTS field_support_captures_scope ON field_support_captures(scope,activity_uuid,state);
`

export const fieldDigest = (value: string) => hashBytes(new TextEncoder().encode(value))

/** Reuses support_operations claim, retry, crash recovery and the existing SQLite mutex. */
export class FieldOfflineStore {
  private ready: Promise<void> | null = null
  constructor(readonly shared: SqliteSupportStore) {}
  initialize(): Promise<void> {
    return this.ready ??= (async () => {
      await this.shared.initialize()
      await this.shared.withDatabase(async db => { await db.execute(FIELD_SCHEMA, false) }, true)
    })().catch(error => { this.ready = null; throw error })
  }
  async bind(origin: string, deviceUuid: string, sessionHash: string): Promise<FieldScope> {
    assertUuid(deviceUuid)
    const key = 'field:' + await fieldDigest(origin + '|' + deviceUuid)
    const scope = { key, deviceUuid, origin, sessionHash }
    await this.initialize()
    await this.shared.withDatabase(async db => {
      await db.run('INSERT INTO field_support_context(scope,device_uuid,origin,session_hash) VALUES (?,?,?,?) ON CONFLICT(scope) DO UPDATE SET session_hash=excluded.session_hash', [key, deviceUuid, origin, sessionHash], false)
    }, true)
    return scope
  }
  async cachedScope(origin: string, sessionHash: string): Promise<FieldScope | null> {
    await this.initialize()
    return this.shared.withDatabase(async db => {
      const row = (await db.query('SELECT * FROM field_support_context WHERE origin=? AND session_hash=?', [origin, sessionHash])).values?.[0]
      return row ? { key: String(row.scope), deviceUuid: String(row.device_uuid), origin, sessionHash } : null
    })
  }
  async cache(scope: FieldScope, rows: Activity[]): Promise<void> {
    await this.shared.withDatabase(async db => {
      for (const row of rows) {
        assertUuid(row.uuid)
        const old = (await db.query('SELECT data_json FROM field_support_activities WHERE scope=? AND uuid=?', [scope.key, row.uuid])).values?.[0]
        const merged = { ...(old ? JSON.parse(String(old.data_json)) : {}), ...row }
        await db.run('INSERT INTO field_support_activities(scope,uuid,data_json) VALUES (?,?,?) ON CONFLICT(scope,uuid) DO UPDATE SET data_json=excluded.data_json', [scope.key, row.uuid, JSON.stringify(merged)], false)
      }
    }, true)
  }
  async activities(scope: FieldScope, uuid?: string): Promise<Activity[]> {
    return this.shared.withDatabase(async db => ((await db.query('SELECT data_json FROM field_support_activities WHERE scope=?' + (uuid ? ' AND uuid=?' : '') + ' ORDER BY uuid LIMIT 100', uuid ? [scope.key, uuid] : [scope.key])).values ?? []).map(row => JSON.parse(String(row.data_json))))
  }
  async queued(scope: FieldScope, uuid?: string): Promise<SupportOperation[]> {
    const rows = await this.shared.withDatabase(async db => (await db.query("SELECT uuid FROM support_operations WHERE device_uuid=? AND status!='ACKNOWLEDGED' ORDER BY created_at, rowid LIMIT 200", [scope.key])).values ?? [])
    const result: SupportOperation[] = []
    for (const row of rows) {
      const op = await this.shared.operation(String(row.uuid), scope.key)
      if (op && (!uuid || (op.payload.operation as Operation).activity_uuid === uuid)) result.push(op)
    }
    return result
  }
  async pendingCount(scope: FieldScope): Promise<number> {
    return this.shared.withDatabase(async db => Number((await db.query("SELECT COUNT(*) AS total FROM support_operations WHERE device_uuid=? AND status!='ACKNOWLEDGED'", [scope.key])).values?.[0]?.total ?? 0))
  }
  async queue(scope: FieldScope, operation: Operation): Promise<void> {
    assertUuid(operation.operation_uuid!); assertUuid(operation.activity_uuid!)
    const kind = { note: 'SUPPORT_NOTE_CREATE', evidence: 'SUPPORT_EVIDENCE_UPLOAD', complete: 'SUPPORT_ACTIVITY_COMPLETE' }[operation.action as 'note' | 'evidence' | 'complete']
    if (!kind) throw new SupportError('INVALID_OPERATION', 'La operación no puede guardarse sin conexión.')
    await this.shared.withDatabase(async db => {
      const cached = (await db.query('SELECT data_json FROM field_support_activities WHERE scope=? AND uuid=?', [scope.key, operation.activity_uuid])).values?.[0]
      const activity = cached ? JSON.parse(String(cached.data_json)) as Activity : null
      if (!activity || activity.status !== 'IN_PROGRESS') throw new SupportError('ACTIVITY_NOT_STARTED', 'Descarga primero la actividad en progreso.')
      const prior = (await db.query("SELECT uuid,payload_json FROM support_operations WHERE device_uuid=? AND status!='ACKNOWLEDGED' ORDER BY rowid DESC", [scope.key])).values ?? []
      const siblings = prior.filter(row => JSON.parse(String(row.payload_json)).operation.activity_uuid === operation.activity_uuid)
      if (siblings.some(row => JSON.parse(String(row.payload_json)).operation.action === 'complete')) throw new SupportError('COMPLETION_PENDING', 'La finalización ya está pendiente de confirmar.')
      if (operation.action === 'note') {
        const policy = activity.contribution_policy
        if (!policy?.available || !operation.body?.trim() || operation.body.length > policy.max_note_length || /<[^>]*>/.test(operation.body)
          || (activity.notes?.length ?? 0) + siblings.filter(row => JSON.parse(String(row.payload_json)).operation.action === 'note').length >= policy.max_notes) throw new SupportError('INVALID_NOTE', 'Revisa la nota y el límite de texto.')
      }
      await db.run("INSERT INTO support_operations(uuid,device_uuid,kind,payload_json,status,depends_on,created_at) VALUES (?,?,?,?,'PENDING',?,?)", [operation.operation_uuid, scope.key, kind, JSON.stringify({ operation }), siblings[0]?.uuid ?? null, new Date().toISOString()], false)
      if (operation.action === 'evidence') {
        const captured = (await db.query('SELECT data_json FROM field_support_captures WHERE uuid=? AND scope=? AND activity_uuid=?', [operation.operation_uuid, scope.key, operation.activity_uuid])).values?.[0]
        if (!captured) throw new SupportError('INVALID_IMAGE', 'Falta la fotografía privada de esta actividad.')
        const capture = JSON.parse(String(captured.data_json)) as FieldCapture
        capture.preview = false
        await db.run('UPDATE field_support_captures SET data_json=? WHERE uuid=? AND scope=?', [JSON.stringify(capture), operation.operation_uuid, scope.key], false)
      }
    }, true)
  }
  async saveCapture(value: FieldCapture): Promise<void> {
    await this.shared.withDatabase(async db => {
      await db.run('INSERT INTO field_support_captures(uuid,scope,activity_uuid,data_json,state) VALUES (?,?,?,?,?) ON CONFLICT(uuid) DO UPDATE SET data_json=excluded.data_json,state=excluded.state WHERE field_support_captures.scope=excluded.scope',
        [value.evidence.localUuid, value.scope, value.activityUuid, JSON.stringify(value), value.evidence.state], false)
    }, true)
  }
  async captures(scope: FieldScope, uuid?: string): Promise<FieldCapture[]> {
    return this.shared.withDatabase(async db => ((await db.query("SELECT data_json FROM field_support_captures WHERE scope=? AND state!='CANCELLED'" + (uuid ? ' AND activity_uuid=?' : '') + ' ORDER BY rowid', uuid ? [scope.key, uuid] : [scope.key])).values ?? []).map(row => JSON.parse(String(row.data_json))))
  }
  async acknowledge(scope: FieldScope, item: SupportOperation, result: Record<string, unknown>): Promise<void> {
    const op = item.payload.operation as Operation
    const activity = result.activity as Activity | undefined
    const receipt = result.receipt as Record<string, unknown> | undefined
    if (!activity || result.confirmed !== true || activity.uuid !== op.activity_uuid
      || (op.action === 'complete' ? activity.status !== 'COMPLETED' || result.confirmed_status !== 'COMPLETED'
        : result.operation_uuid !== item.uuid || receipt?.uuid !== item.uuid || receipt.kind !== op.action)
      || (op.action === 'evidence' && (receipt?.status !== 'CONFIRMED' || receipt.upload_sha256 !== op.evidence?.upload_sha256 || !/^[a-f0-9]{64}$/.test(String(receipt.sha256))))) {
      throw new SupportError('INVALID_RECEIPT', 'Falta una confirmación válida del servidor.', true)
    }
    await this.shared.withDatabase(async db => {
      const owned = (await db.query("SELECT uuid FROM support_operations WHERE uuid=? AND device_uuid=? AND status='SENDING'", [item.uuid, scope.key])).values?.[0]
      if (!owned) throw new SupportError('INVALID_RECEIPT', 'La solicitud no corresponde a esta sesión.')
      await db.run('UPDATE field_support_activities SET data_json=? WHERE scope=? AND uuid=?', [JSON.stringify(activity), scope.key, activity.uuid], false)
      if (op.action === 'evidence') {
        const row = (await db.query('SELECT data_json FROM field_support_captures WHERE uuid=? AND scope=?', [item.uuid, scope.key])).values?.[0]
        if (!row) throw new SupportError('INVALID_RECEIPT', 'La fotografía no corresponde a esta sesión.')
        const capture = JSON.parse(String(row.data_json)) as FieldCapture
        capture.confirmed = true; capture.preview = false; capture.evidence.state = 'CONFIRMED'; capture.evidence.serverUuid = item.uuid
        await db.run("UPDATE field_support_captures SET data_json=?,state='CONFIRMED' WHERE uuid=? AND scope=?", [JSON.stringify(capture), item.uuid, scope.key], false)
      }
      await db.run("UPDATE support_operations SET status='ACKNOWLEDGED',result_json=?,error_code=NULL WHERE uuid=? AND device_uuid=?", [JSON.stringify({ confirmed: true, receipt, operation_uuid: item.uuid }), item.uuid, scope.key], false)
    }, true)
  }
}
