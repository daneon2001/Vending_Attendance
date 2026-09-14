import { Capacitor } from '@capacitor/core'
import { CapacitorSQLite, SQLiteConnection } from '@capacitor-community/sqlite'
import type { SupportStore } from './SupportStore'
import { assertUuid, SupportError, SupportMutex, type EvidenceFile, type LocalEvidence, type LocalTicket, type SupportChanges, type SupportContext, type SupportOperation, type TicketInput, type VerificationInput } from './types'

export const SUPPORT_DATABASE_NAME = 'vending_support'
export const SUPPORT_DATABASE_VERSION = 1
type Row = Record<string, unknown>
export interface SupportDatabase {
  query(sql: string, values?: unknown[]): Promise<{ values?: Row[] }>
  run(sql: string, values?: unknown[], transaction?: boolean): Promise<unknown>
  execute(sql: string, transaction?: boolean): Promise<unknown>
  beginTransaction(): Promise<unknown>
  commitTransaction(): Promise<unknown>
  rollbackTransaction(): Promise<unknown>
}

export const SUPPORT_SCHEMA = `
CREATE TABLE IF NOT EXISTS support_context (device_uuid TEXT PRIMARY KEY, payload_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS support_tickets (
 local_uuid TEXT PRIMARY KEY, device_uuid TEXT NOT NULL, machine_uuid TEXT NOT NULL,
 payload_json TEXT NOT NULL, status TEXT NOT NULL, server_uuid TEXT, server_json TEXT, detail_json TEXT,
 error_code TEXT, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS support_ticket_device ON support_tickets(device_uuid, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS support_ticket_remote ON support_tickets(device_uuid,server_uuid);
CREATE TABLE IF NOT EXISTS support_evidence (
 local_uuid TEXT PRIMARY KEY, ticket_local_uuid TEXT NOT NULL REFERENCES support_tickets(local_uuid) ON DELETE RESTRICT,
 device_uuid TEXT NOT NULL, captured_at TEXT NOT NULL, source_path TEXT, path TEXT, mime TEXT,
 size_bytes INTEGER, upload_sha256 TEXT, state TEXT NOT NULL, server_uuid TEXT,
 purged_at TEXT, capture_slot INTEGER UNIQUE
);
CREATE INDEX IF NOT EXISTS support_evidence_ticket ON support_evidence(ticket_local_uuid, state);
CREATE INDEX IF NOT EXISTS support_evidence_purge ON support_evidence(device_uuid,state,purged_at);
CREATE TABLE IF NOT EXISTS support_operations (
 uuid TEXT PRIMARY KEY, device_uuid TEXT NOT NULL, kind TEXT NOT NULL,
 ticket_local_uuid TEXT REFERENCES support_tickets(local_uuid) ON DELETE RESTRICT,
 evidence_local_uuid TEXT REFERENCES support_evidence(local_uuid) ON DELETE RESTRICT,
 depends_on TEXT REFERENCES support_operations(uuid) ON DELETE RESTRICT,
 payload_json TEXT NOT NULL, status TEXT NOT NULL, retry_count INTEGER NOT NULL DEFAULT 0,
 next_attempt_at TEXT, error_code TEXT, result_json TEXT, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS support_dispatch ON support_operations(device_uuid,status,next_attempt_at,created_at);
CREATE INDEX IF NOT EXISTS support_operation_evidence ON support_operations(evidence_local_uuid,kind,status);
CREATE TABLE IF NOT EXISTS support_feed (
 device_uuid TEXT NOT NULL, event_uuid TEXT NOT NULL, sequence INTEGER NOT NULL,
 payload_json TEXT NOT NULL, read_at TEXT, PRIMARY KEY(device_uuid,event_uuid)
);
CREATE INDEX IF NOT EXISTS support_feed_device ON support_feed(device_uuid,sequence);
CREATE TABLE IF NOT EXISTS support_cursors (device_uuid TEXT PRIMARY KEY, sequence INTEGER NOT NULL);
PRAGMA user_version=1;
`

async function nativeDatabase(): Promise<SupportDatabase> {
  if (!Capacitor.isNativePlatform()) throw new SupportError('NATIVE_REQUIRED', 'El almacenamiento de soporte requiere Android o iOS.')
  const sqlite = new SQLiteConnection(CapacitorSQLite)
  const existing = await sqlite.isConnection(SUPPORT_DATABASE_NAME, false)
  const db = existing.result
    ? await sqlite.retrieveConnection(SUPPORT_DATABASE_NAME, false)
    : await sqlite.createConnection(SUPPORT_DATABASE_NAME, false, 'no-encryption', SUPPORT_DATABASE_VERSION, false)
  if (!(await db.isDBOpen()).result) await db.open()
  return db
}

function ticket(row: Row): LocalTicket {
  return {
    localUuid: String(row.local_uuid), deviceUuid: String(row.device_uuid), machineUuid: String(row.machine_uuid),
    payload: JSON.parse(String(row.payload_json)), status: row.status as LocalTicket['status'],
    serverUuid: row.server_uuid ? String(row.server_uuid) : null,
    server: row.server_json ? JSON.parse(String(row.server_json)) : null,
    detail: row.detail_json ? JSON.parse(String(row.detail_json)) : null,
    errorCode: row.error_code ? String(row.error_code) : null, createdAt: String(row.created_at),
  }
}
function evidence(row: Row): LocalEvidence {
  return {
    localUuid: String(row.local_uuid), ticketLocalUuid: String(row.ticket_local_uuid), deviceUuid: String(row.device_uuid),
    capturedAt: String(row.captured_at), sourcePath: row.source_path ? String(row.source_path) : null, path: row.path ? String(row.path) : null, mime: row.mime ? String(row.mime) : null,
    sizeBytes: row.size_bytes === null ? null : Number(row.size_bytes), uploadSha256: row.upload_sha256 ? String(row.upload_sha256) : null,
    state: row.state as LocalEvidence['state'], serverUuid: row.server_uuid ? String(row.server_uuid) : null,
    purgedAt: row.purged_at ? String(row.purged_at) : null,
    ...(row.delivery_status ? { deliveryStatus: row.delivery_status as LocalEvidence['deliveryStatus'] } : {}),
  }
}
function operation(row: Row): SupportOperation {
  return {
    uuid: String(row.uuid), deviceUuid: String(row.device_uuid), kind: row.kind as SupportOperation['kind'],
    ticketLocalUuid: row.ticket_local_uuid ? String(row.ticket_local_uuid) : null,
    evidenceLocalUuid: row.evidence_local_uuid ? String(row.evidence_local_uuid) : null,
    dependsOn: row.depends_on ? String(row.depends_on) : null, payload: JSON.parse(String(row.payload_json)),
    status: row.status as SupportOperation['status'], retryCount: Number(row.retry_count),
    nextAttemptAt: row.next_attempt_at ? String(row.next_attempt_at) : null,
    errorCode: row.error_code ? String(row.error_code) : null,
    result: row.result_json ? JSON.parse(String(row.result_json)) : null,
  }
}

function cleanInput(input: TicketInput, context: SupportContext): TicketInput {
  if (!context.categories.some(category => category.value === input.category) || !input.title.trim() || !input.description.trim()
    || input.title.length > 255 || input.description.length > 10_000 || !Number.isFinite(Date.parse(input.reported_at))) {
    throw new SupportError('INVALID_REPORT', 'Revisa categoría, título y descripción del reporte.')
  }
  const value: TicketInput = { category: input.category, title: input.title.trim(), description: input.description.trim(), reported_at: input.reported_at }
  const loc = input.location
  if (loc) {
    if (!Number.isFinite(loc.latitude) || Math.abs(loc.latitude) > 90 || !Number.isFinite(loc.longitude) || Math.abs(loc.longitude) > 180
      || !Number.isFinite(loc.accuracy_m) || loc.accuracy_m < 0 || !Number.isFinite(Date.parse(loc.captured_at))) {
      throw new SupportError('INVALID_LOCATION', 'La ubicación obtenida no es válida.')
    }
    value.location = { latitude: loc.latitude, longitude: loc.longitude, accuracy_m: loc.accuracy_m, captured_at: loc.captured_at }
  }
  return value
}

export class SqliteSupportStore implements SupportStore {
  private db: SupportDatabase | null = null
  private readonly mutex = new SupportMutex()
  constructor(private readonly openDatabase: () => Promise<SupportDatabase> = nativeDatabase) {}

  async initialize(): Promise<void> {
    await this.mutex.run(async () => {
      if (this.db) return
      const db = await this.openDatabase()
      await db.execute('PRAGMA foreign_keys=ON;', false)
      const version = Number((await db.query('PRAGMA user_version;')).values?.[0]?.user_version ?? 0)
      if (version > SUPPORT_DATABASE_VERSION) throw new SupportError('DATABASE_VERSION', 'La base de soporte requiere una versión más reciente de la aplicación.')
      if (version < 1) await db.execute(SUPPORT_SCHEMA, true)
      await db.run("UPDATE support_operations SET status='PENDING', retry_count=retry_count+1, next_attempt_at=?, error_code='PROCESS_INTERRUPTED' WHERE status='SENDING'", [new Date(Date.now() + 2_000).toISOString()])
      this.db = db
    })
  }

  private use<T>(work: (db: SupportDatabase) => Promise<T>, write = false): Promise<T> {
    return this.mutex.run(async () => {
      const db = this.db
      if (!db) throw new SupportError('DATABASE_UNAVAILABLE', 'No se pudo abrir el almacenamiento de soporte.')
      if (!write) return work(db)
      await db.beginTransaction()
      try { const result = await work(db); await db.commitTransaction(); return result }
      catch (error) { await db.rollbackTransaction(); throw error }
    })
  }
  /** Additive Field Support tables share this connection and transaction mutex, not attendance. */
  withDatabase<T>(work: (db: SupportDatabase) => Promise<T>, write = false): Promise<T> {
    return this.use(work, write)
  }
  private async row(db: SupportDatabase, sql: string, values: unknown[] = []): Promise<Row | undefined> {
    return (await db.query(sql, values)).values?.[0]
  }
  private async ownedTicket(db: SupportDatabase, localUuid: string, deviceUuid: string): Promise<LocalTicket> {
    const row = await this.row(db, 'SELECT * FROM support_tickets WHERE local_uuid=? AND device_uuid=?', [localUuid, deviceUuid])
    if (!row) throw new SupportError('REPORT_NOT_FOUND', 'No se encontró el reporte de este equipo.')
    return ticket(row)
  }
  private async contextRow(db: SupportDatabase, deviceUuid: string): Promise<SupportContext> {
    const row = await this.row(db, 'SELECT payload_json FROM support_context WHERE device_uuid=?', [deviceUuid])
    if (!row) throw new SupportError('CONTEXT_UNAVAILABLE', 'Conecta el equipo una vez para preparar soporte.')
    return JSON.parse(String(row.payload_json)) as SupportContext
  }
  private async insertOperation(db: SupportDatabase, uuid: string, deviceUuid: string, kind: SupportOperation['kind'], payload: Record<string, unknown>, ticketUuid: string | null = null, evidenceUuid: string | null = null, dependsOn: string | null = null): Promise<void> {
    await db.run(`INSERT INTO support_operations (uuid,device_uuid,kind,ticket_local_uuid,evidence_local_uuid,depends_on,payload_json,status,created_at)
      VALUES (?,?,?,?,?,?,?,'PENDING',?)`, [uuid, deviceUuid, kind, ticketUuid, evidenceUuid, dependsOn, JSON.stringify(payload), new Date().toISOString()], false)
  }

  saveContext(context: SupportContext): Promise<void> {
    assertUuid(context.device.uuid); assertUuid(context.machine.uuid)
    const policy = context.evidence_policy
    if (!policy || !Number.isSafeInteger(policy.max_size_bytes) || policy.max_size_bytes <= 0 || !Number.isSafeInteger(policy.max_count) || policy.max_count < 1
      || !Array.isArray(policy.allowed_mimes) || !Array.isArray(context.categories)) throw new SupportError('INVALID_CONTEXT', 'La configuración de soporte no es válida.')
    return this.use(async db => {
      const previous = await this.row(db, 'SELECT payload_json FROM support_context WHERE device_uuid=?', [context.device.uuid])
      if (previous && (JSON.parse(String(previous.payload_json)) as SupportContext).machine.uuid !== context.machine.uuid) {
        // Preserve old-machine intent/content but re-read the newly authorized machine's history.
        await db.run('UPDATE support_cursors SET sequence=0 WHERE device_uuid=?', [context.device.uuid], false)
      }
      await db.run('INSERT INTO support_context(device_uuid,payload_json) VALUES (?,?) ON CONFLICT(device_uuid) DO UPDATE SET payload_json=excluded.payload_json', [context.device.uuid, JSON.stringify(context)], false)
    }, true)
  }
  getContext(deviceUuid: string): Promise<SupportContext | null> {
    return this.use(async db => { const row = await this.row(db, 'SELECT payload_json FROM support_context WHERE device_uuid=?', [deviceUuid]); return row ? JSON.parse(String(row.payload_json)) as SupportContext : null })
  }
  createDraft(deviceUuid: string, input: TicketInput): Promise<LocalTicket> {
    return this.use(async db => {
      const context = await this.contextRow(db, deviceUuid)
      const uuid = crypto.randomUUID()
      await db.run("INSERT INTO support_tickets(local_uuid,device_uuid,machine_uuid,payload_json,status,created_at) VALUES (?,?,?,?,'DRAFT',?)", [uuid, deviceUuid, context.machine.uuid, JSON.stringify(cleanInput(input, context)), new Date().toISOString()], false)
      return this.ownedTicket(db, uuid, deviceUuid)
    }, true)
  }
  updateDraft(localUuid: string, deviceUuid: string, input: TicketInput): Promise<void> {
    return this.use(async db => {
      const local = await this.ownedTicket(db, localUuid, deviceUuid)
      if (local.status !== 'DRAFT') throw new SupportError('REPORT_IMMUTABLE', 'El reporte ya fue enviado.')
      const context = await this.contextRow(db, deviceUuid)
      if (local.machineUuid !== context.machine.uuid) throw new SupportError('MACHINE_CHANGED', 'La máquina asociada cambió.')
      const clean = cleanInput(input, context)
      await db.run('UPDATE support_tickets SET payload_json=? WHERE local_uuid=?', [JSON.stringify(clean), localUuid], false)
    }, true)
  }
  submit(localUuid: string, deviceUuid: string): Promise<void> {
    return this.use(async db => {
      const local = await this.ownedTicket(db, localUuid, deviceUuid)
      if (local.status !== 'DRAFT') return // A repeated submit preserves exactly the same operation.
      const items = (await db.query('SELECT * FROM support_evidence WHERE ticket_local_uuid=?', [localUuid])).values ?? []
      if (items.some(row => row.state === 'CAPTURING')) throw new SupportError('CAPTURE_PENDING', 'Termina o cancela la fotografía antes de enviar.')
      await this.insertOperation(db, localUuid, deviceUuid, 'CREATE_TICKET', { client_operation_uuid: localUuid, captured_machine_uuid: local.machineUuid, ...local.payload }, localUuid)
      for (const row of items.filter(item => item.state === 'READY')) {
        const item = evidence(row)
        await this.insertOperation(db, item.localUuid, deviceUuid, 'RESERVE_EVIDENCE', {
          client_operation_uuid: item.localUuid, mime: item.mime, size_bytes: item.sizeBytes,
          upload_sha256: item.uploadSha256, captured_at: item.capturedAt,
        }, localUuid, item.localUuid, localUuid)
        await this.insertOperation(db, crypto.randomUUID(), deviceUuid, 'UPLOAD_EVIDENCE', {}, localUuid, item.localUuid, item.localUuid)
      }
      await db.run("UPDATE support_tickets SET status='PENDING' WHERE local_uuid=?", [localUuid], false)
    }, true)
  }
  getTicket(localUuid: string, deviceUuid: string): Promise<LocalTicket | null> {
    return this.use(async db => { const row = await this.row(db, 'SELECT * FROM support_tickets WHERE local_uuid=? AND device_uuid=?', [localUuid, deviceUuid]); return row ? ticket(row) : null })
  }
  listTickets(deviceUuid: string): Promise<LocalTicket[]> {
    return this.use(async db => ((await db.query('SELECT * FROM support_tickets WHERE device_uuid=? ORDER BY created_at DESC LIMIT 100', [deviceUuid])).values ?? []).map(ticket))
  }
  queueComment(localUuid: string, deviceUuid: string, body: string): Promise<string> {
    return this.use(async db => {
      const local = await this.ownedTicket(db, localUuid, deviceUuid)
      if (local.status === 'DRAFT' || !body.trim() || body.length > 10_000) throw new SupportError('INVALID_COMMENT', 'Revisa el comentario y envía primero el reporte.')
      const uuid = crypto.randomUUID()
      await this.insertOperation(db, uuid, deviceUuid, 'COMMENT', { client_operation_uuid: uuid, body: body.trim() }, localUuid, null, local.serverUuid ? null : localUuid)
      return uuid
    }, true)
  }
  cacheTicketDetail(localUuid: string, deviceUuid: string, detail: { ticket: import('./types').ServerTicket; events: import('./types').SupportChange[]; evidence: import('./types').ServerEvidence[] }): Promise<void> {
    return this.use(async db => {
      const local = await this.ownedTicket(db, localUuid, deviceUuid)
      if (local.serverUuid !== detail.ticket.uuid) throw new SupportError('INVALID_RECEIPT', 'El detalle corresponde a otro ticket.')
      await db.run('UPDATE support_tickets SET server_json=?,detail_json=? WHERE local_uuid=? AND device_uuid=?',
        [JSON.stringify(detail.ticket), JSON.stringify({ events: detail.events, evidence: detail.evidence }), localUuid, deviceUuid], false)
    }, true)
  }
  comments(localUuid: string, deviceUuid: string): Promise<SupportOperation[]> {
    return this.use(async db => ((await db.query("SELECT * FROM support_operations WHERE ticket_local_uuid=? AND device_uuid=? AND kind='COMMENT' AND status!='ACKNOWLEDGED' ORDER BY created_at LIMIT 30", [localUuid, deviceUuid])).values ?? []).map(operation))
  }
  queueVerification(deviceUuid: string, input: VerificationInput): Promise<string> {
    return this.use(async db => {
      const context = await this.contextRow(db, deviceUuid)
      const capturedMachine = assertUuid(input.captured_machine_uuid ?? context.machine.uuid)
      const uuid = crypto.randomUUID()
      const allowedChecks = ['GPS_PERMISSION', 'GPS_AVAILABILITY', 'CAMERA_PERMISSION', 'CAMERA_AVAILABILITY', 'NETWORK', 'API_REACHABILITY', 'LOCAL_CONFIGURATION', 'LOCAL_EMPLOYEES', 'LOCAL_OUTBOX']
      if (!Number.isFinite(Date.parse(input.started_at)) || !Number.isFinite(Date.parse(input.completed_at)) || Date.parse(input.completed_at) < Date.parse(input.started_at)
        || input.checks.length > allowedChecks.length || new Set(input.checks.map(check => check.code)).size !== input.checks.length) throw new SupportError('INVALID_VERIFICATION', 'La verificación no es válida.')
      const checks = input.checks.map(check => {
        if (!allowedChecks.includes(check.code) || !['PASS', 'WARNING', 'FAIL', 'NOT_AVAILABLE'].includes(check.result)) throw new SupportError('INVALID_VERIFICATION', 'La verificación no es válida.')
        const details: Record<string, string | number | boolean> = {}
        for (const [key, value] of Object.entries(check.details ?? {})) {
          if (key === 'permission' && ['granted', 'denied', 'prompt', 'unknown'].includes(String(value))) details[key] = String(value)
          else if (key === 'available' && typeof value === 'boolean') details[key] = value
          else if (key === 'state' && ['ONLINE', 'OFFLINE', 'UNKNOWN'].includes(String(value))) details[key] = String(value)
          else if (['version', 'pending_count'].includes(key) && typeof value === 'number' && Number.isSafeInteger(value) && value >= (key === 'version' ? 1 : 0)) details[key] = value
          else if (key === 'error_code' && ['GPS_PERMISSION_DENIED', 'GPS_UNAVAILABLE', 'GPS_TIMEOUT', 'GPS_DISABLED', 'NETWORK_TIMEOUT', 'AUTHENTICATION_FAILED', 'NOT_PROVISIONED', 'CAMERA_UNAVAILABLE', 'CAMERA_PERMISSION_DENIED'].includes(String(value))) details[key] = String(value)
          else throw new SupportError('INVALID_VERIFICATION', 'La verificación contiene datos no permitidos.')
        }
        if (check.observed_at && !Number.isFinite(Date.parse(check.observed_at))) throw new SupportError('INVALID_VERIFICATION', 'La fecha de verificación no es válida.')
        return { code: check.code, result: check.result, ...(check.observed_at ? { observed_at: check.observed_at } : {}), details }
      })
      if (input.app_version && !/^[A-Za-z0-9.+_-]{1,60}$/.test(input.app_version)) throw new SupportError('INVALID_VERIFICATION', 'La versión de aplicación no es válida.')
      if (input.app_build_number !== undefined && (!Number.isSafeInteger(input.app_build_number) || input.app_build_number < 1)) throw new SupportError('INVALID_VERIFICATION', 'La compilación de aplicación no es válida.')
      await this.insertOperation(db, uuid, deviceUuid, 'VERIFICATION', { client_operation_uuid: uuid, captured_machine_uuid: capturedMachine, started_at: input.started_at, completed_at: input.completed_at,
        ...(input.app_version ? { app_version: input.app_version } : {}), ...(input.app_build_number ? { app_build_number: input.app_build_number } : {}), checks })
      return uuid
    }, true)
  }
  reserveCapture(localUuid: string, deviceUuid: string): Promise<LocalEvidence> {
    return this.use(async db => {
      const local = await this.ownedTicket(db, localUuid, deviceUuid)
      const context = await this.contextRow(db, deviceUuid)
      if (local.machineUuid !== context.machine.uuid) throw new SupportError('MACHINE_CHANGED', 'La máquina asociada cambió.')
      if (local.status !== 'DRAFT') throw new SupportError('REPORT_IMMUTABLE', 'Agrega fotografías antes de enviar el reporte.')
      if (await this.row(db, "SELECT local_uuid FROM support_evidence WHERE state='CAPTURING'")) throw new SupportError('CAPTURE_PENDING', 'Hay una fotografía pendiente de recuperar o cancelar.')
      const count = Number((await this.row(db, "SELECT COUNT(*) AS total FROM support_evidence WHERE ticket_local_uuid=? AND state!='CANCELLED'", [localUuid]))?.total ?? 0)
      if (count >= (await this.contextRow(db, deviceUuid)).evidence_policy.max_count) throw new SupportError('EVIDENCE_LIMIT', 'El reporte ya tiene el máximo de fotografías.')
      const uuid = crypto.randomUUID()
      await db.run("INSERT INTO support_evidence(local_uuid,ticket_local_uuid,device_uuid,captured_at,state,capture_slot) VALUES (?,?,?,?,'CAPTURING',1)", [uuid, localUuid, deviceUuid, new Date().toISOString()], false)
      return evidence((await this.row(db, 'SELECT * FROM support_evidence WHERE local_uuid=?', [uuid]))!)
    }, true)
  }
  lastVerification(deviceUuid: string): Promise<SupportOperation | null> {
    return this.use(async db => { const row = await this.row(db, "SELECT * FROM support_operations WHERE device_uuid=? AND kind='VERIFICATION' ORDER BY created_at DESC,rowid DESC LIMIT 1", [deviceUuid]); return row ? operation(row) : null })
  }
  pendingCapture(): Promise<LocalEvidence | null> {
    return this.use(async db => { const row = await this.row(db, "SELECT * FROM support_evidence WHERE state='CAPTURING' LIMIT 1"); return row ? evidence(row) : null })
  }
  completeCapture(evidenceUuid: string, deviceUuid: string, file: EvidenceFile): Promise<void> {
    return this.use(async db => {
      const row = await this.row(db, 'SELECT * FROM support_evidence WHERE local_uuid=? AND device_uuid=?', [evidenceUuid, deviceUuid])
      if (!row) throw new SupportError('EVIDENCE_NOT_FOUND', 'No se encontró la fotografía.')
      if (row.state !== 'CAPTURING') {
        if (row.state === 'READY' && row.upload_sha256 === file.uploadSha256) return
        throw new SupportError('EVIDENCE_IMMUTABLE', 'La fotografía ya fue confirmada.')
      }
      const policy = (await this.contextRow(db, deviceUuid)).evidence_policy
      if (!policy.allowed_mimes.includes(file.mime) || file.sizeBytes < 1 || file.sizeBytes > policy.max_size_bytes || !/^[a-f0-9]{64}$/.test(file.uploadSha256)
        || file.path !== `support-evidence/${assertUuid(evidenceUuid)}.jpg`) throw new SupportError('INVALID_EVIDENCE', 'La fotografía no cumple los límites de soporte.')
      await db.run("UPDATE support_evidence SET path=?,mime=?,size_bytes=?,upload_sha256=?,state='READY',capture_slot=NULL WHERE local_uuid=?", [file.path, file.mime, file.sizeBytes, file.uploadSha256, evidenceUuid], false)
    }, true)
  }
  saveCaptureSource(evidenceUuid: string, deviceUuid: string, sourcePath: string): Promise<void> {
    if (!/^(file|content):\/\//.test(sourcePath) || sourcePath.length > 4096) throw new SupportError('INVALID_CAMERA_FILE', 'No se pudo conservar la fotografía.')
    return this.use(async db => { await db.run("UPDATE support_evidence SET source_path=COALESCE(source_path,?) WHERE local_uuid=? AND device_uuid=? AND state='CAPTURING'", [sourcePath, evidenceUuid, deviceUuid], false) }, true)
  }
  cancelCapture(evidenceUuid: string, deviceUuid: string): Promise<void> {
    return this.use(async db => { await db.run("UPDATE support_evidence SET state='CANCELLED',capture_slot=NULL WHERE local_uuid=? AND device_uuid=? AND state='CAPTURING'", [evidenceUuid, deviceUuid], false) }, true)
  }
  evidenceForTicket(localUuid: string, deviceUuid: string): Promise<LocalEvidence[]> {
    return this.use(async db => ((await db.query(`SELECT e.*, (SELECT o.status FROM support_operations o WHERE o.evidence_local_uuid=e.local_uuid
      ORDER BY CASE o.status WHEN 'REJECTED' THEN 0 WHEN 'BLOCKED' THEN 1 WHEN 'SENDING' THEN 2 WHEN 'PENDING' THEN 3 ELSE 4 END LIMIT 1) AS delivery_status
      FROM support_evidence e WHERE e.ticket_local_uuid=? AND e.device_uuid=? AND e.state!='CANCELLED' ORDER BY e.captured_at`, [localUuid, deviceUuid])).values ?? []).map(evidence))
  }
  getEvidence(evidenceUuid: string, deviceUuid: string): Promise<LocalEvidence | null> {
    return this.use(async db => { const row = await this.row(db, 'SELECT * FROM support_evidence WHERE local_uuid=? AND device_uuid=?', [evidenceUuid, deviceUuid]); return row ? evidence(row) : null })
  }
  claim(deviceUuid: string): Promise<SupportOperation | null> {
    return this.use(async db => {
      await db.run("UPDATE support_operations SET status='BLOCKED',error_code='DEPENDENCY_REJECTED' WHERE device_uuid=? AND status='PENDING' AND depends_on IN (SELECT uuid FROM support_operations WHERE status IN ('REJECTED','BLOCKED'))", [deviceUuid], false)
      const row = await this.row(db, `SELECT o.* FROM support_operations o WHERE o.device_uuid=? AND o.status='PENDING'
        AND (o.next_attempt_at IS NULL OR o.next_attempt_at<=?)
        AND (o.depends_on IS NULL OR EXISTS (SELECT 1 FROM support_operations p WHERE p.uuid=o.depends_on AND p.status='ACKNOWLEDGED'))
        ORDER BY o.created_at,o.rowid LIMIT 1`, [deviceUuid, new Date().toISOString()])
      if (!row) return null
      await db.run("UPDATE support_operations SET status='SENDING' WHERE uuid=?", [row.uuid], false)
      return operation({ ...row, status: 'SENDING' })
    }, true)
  }
  acknowledge(item: SupportOperation, result: Record<string, unknown>): Promise<void> {
    return this.use(async db => {
      const row = await this.row(db, 'SELECT * FROM support_operations WHERE uuid=? AND device_uuid=?', [item.uuid, item.deviceUuid])
      if (!row || row.status === 'ACKNOWLEDGED') return
      if (row.status !== 'SENDING') throw new SupportError('OPERATION_STATE', 'La operación ya no está en envío.')
      if (item.kind === 'CREATE_TICKET') {
        const remote = result.ticket as Row | undefined
        if (!remote || typeof remote.uuid !== 'string' || typeof remote.folio !== 'string') throw new SupportError('INVALID_RECEIPT', 'Falta la confirmación del ticket.', true)
        assertUuid(remote.uuid)
        await db.run("UPDATE support_tickets SET status='ACKNOWLEDGED',server_uuid=?,server_json=?,error_code=NULL WHERE local_uuid=? AND device_uuid=?", [remote.uuid, JSON.stringify(remote), item.ticketLocalUuid, item.deviceUuid], false)
      }
      if (item.kind === 'RESERVE_EVIDENCE' || item.kind === 'UPLOAD_EVIDENCE') {
        const remote = result.evidence as Row | undefined
        if (!remote || typeof remote.uuid !== 'string' || !['PENDING', 'CONFIRMED'].includes(String(remote.status))) throw new SupportError('INVALID_RECEIPT', 'Falta la confirmación de la fotografía.', true)
        assertUuid(remote.uuid)
        const local = await this.row(db, 'SELECT * FROM support_evidence WHERE local_uuid=? AND device_uuid=?', [item.evidenceLocalUuid, item.deviceUuid])
        if (!local || (local.server_uuid && local.server_uuid !== remote.uuid)) throw new SupportError('INVALID_RECEIPT', 'La confirmación no corresponde a la fotografía.', true)
        if (item.kind === 'UPLOAD_EVIDENCE' && remote.status !== 'CONFIRMED') throw new SupportError('UNCONFIRMED_EVIDENCE', 'La fotografía todavía no ha sido confirmada.', true)
        await db.run('UPDATE support_evidence SET server_uuid=?,state=? WHERE local_uuid=?', [remote.uuid, remote.status === 'CONFIRMED' ? 'CONFIRMED' : 'READY', item.evidenceLocalUuid], false)
      }
      await db.run("UPDATE support_operations SET status='ACKNOWLEDGED',error_code=NULL,result_json=? WHERE uuid=?", [JSON.stringify(result), item.uuid], false)
    }, true)
  }
  fail(item: SupportOperation, code: string, retryable: boolean, retryAfterSeconds: number | null = null, blocked = false): Promise<void> {
    return this.use(async db => {
      const status = blocked ? 'BLOCKED' : retryable ? 'PENDING' : 'REJECTED'
      const safeCode = /^[A-Z0-9_]{1,80}$/.test(code) ? code : 'SUPPORT_ERROR'
      const delay = Math.max(retryAfterSeconds ?? 0, Math.min(300, 2 ** Math.min(item.retryCount + 1, 8)))
      await db.run('UPDATE support_operations SET status=?,retry_count=retry_count+1,next_attempt_at=?,error_code=? WHERE uuid=? AND device_uuid=? AND status=\'SENDING\'', [status, new Date(Date.now() + delay * 1000).toISOString(), safeCode, item.uuid, item.deviceUuid], false)
      if (item.kind === 'CREATE_TICKET') await db.run('UPDATE support_tickets SET status=?,error_code=? WHERE local_uuid=? AND device_uuid=?', [status, safeCode, item.ticketLocalUuid, item.deviceUuid], false)
    }, true)
  }
  operation(uuid: string, deviceUuid: string): Promise<SupportOperation | null> {
    return this.use(async db => { const row = await this.row(db, 'SELECT * FROM support_operations WHERE uuid=? AND device_uuid=?', [uuid, deviceUuid]); return row ? operation(row) : null })
  }
  confirmedFiles(deviceUuid: string): Promise<LocalEvidence[]> {
    return this.use(async db => ((await db.query("SELECT * FROM support_evidence e WHERE e.device_uuid=? AND e.state='CONFIRMED' AND e.purged_at IS NULL AND e.path IS NOT NULL AND EXISTS (SELECT 1 FROM support_operations o WHERE o.evidence_local_uuid=e.local_uuid AND o.kind='UPLOAD_EVIDENCE' AND o.status='ACKNOWLEDGED') LIMIT 25", [deviceUuid])).values ?? []).map(evidence))
  }
  markPurged(evidenceUuid: string, deviceUuid: string): Promise<void> {
    return this.use(async db => { await db.run("UPDATE support_evidence SET purged_at=? WHERE local_uuid=? AND device_uuid=? AND state='CONFIRMED'", [new Date().toISOString(), evidenceUuid, deviceUuid], false) }, true)
  }
  cameraSources(deviceUuid: string): Promise<LocalEvidence[]> {
    return this.use(async db => ((await db.query("SELECT * FROM support_evidence WHERE device_uuid=? AND state IN ('READY','CONFIRMED') AND source_path IS NOT NULL LIMIT 25", [deviceUuid])).values ?? []).map(evidence))
  }
  markSourceCleaned(evidenceUuid: string, deviceUuid: string): Promise<void> {
    return this.use(async db => { await db.run("UPDATE support_evidence SET source_path=NULL WHERE local_uuid=? AND device_uuid=? AND state IN ('READY','CONFIRMED') AND path IS NOT NULL", [evidenceUuid, deviceUuid], false) }, true)
  }
  getCursor(deviceUuid: string): Promise<number> {
    return this.use(async db => Number((await this.row(db, 'SELECT sequence FROM support_cursors WHERE device_uuid=?', [deviceUuid]))?.sequence ?? 0))
  }
  applyChanges(deviceUuid: string, page: SupportChanges): Promise<void> {
    return this.use(async db => {
      const previous = Number((await this.row(db, 'SELECT sequence FROM support_cursors WHERE device_uuid=?', [deviceUuid]))?.sequence ?? 0)
      if (!Array.isArray(page.events) || !Number.isSafeInteger(page.next_sequence) || page.next_sequence < 0) throw new SupportError('INVALID_FEED', 'No se recibió una actualización válida.', true)
      if (page.next_sequence <= previous) return
      let sequence = previous
      for (const event of page.events) {
        assertUuid(event.uuid); assertUuid(event.ticket_uuid)
        if (!Number.isSafeInteger(event.sequence) || event.sequence <= sequence || event.sequence > page.next_sequence) throw new SupportError('INVALID_FEED', 'El orden de actualizaciones no es válido.', true)
        sequence = event.sequence
        if (event.ticket) {
          const snapshot = event.ticket
          if (snapshot.uuid !== event.ticket_uuid) throw new SupportError('INVALID_FEED', 'La actualización pertenece a otro ticket.', true)
          const authorizedContext = await this.contextRow(db, deviceUuid)
          if (snapshot.machine && snapshot.machine.uuid !== authorizedContext.machine.uuid) throw new SupportError('INVALID_FEED', 'La actualización pertenece a otra máquina.', true)
          const existing = await this.row(db, 'SELECT local_uuid FROM support_tickets WHERE server_uuid=? AND device_uuid=?', [snapshot.uuid, deviceUuid])
          if (existing) await db.run('UPDATE support_tickets SET server_json=? WHERE local_uuid=?', [JSON.stringify(snapshot), existing.local_uuid], false)
          else {
            const context = await this.contextRow(db, deviceUuid)
            const payload = { category: snapshot.category, title: snapshot.title, description: snapshot.description, reported_at: snapshot.reported_at }
            await db.run("INSERT INTO support_tickets(local_uuid,device_uuid,machine_uuid,payload_json,status,server_uuid,server_json,created_at) VALUES (?,?,?,?,'ACKNOWLEDGED',?,?,?)",
              [crypto.randomUUID(), deviceUuid, context.machine.uuid, JSON.stringify(payload), snapshot.uuid, JSON.stringify(snapshot), snapshot.reported_at], false)
          }
        }
        await db.run('INSERT OR IGNORE INTO support_feed(device_uuid,event_uuid,sequence,payload_json) VALUES (?,?,?,?)', [deviceUuid, event.uuid, event.sequence, JSON.stringify(event)], false)
      }
      await db.run('INSERT INTO support_cursors(device_uuid,sequence) VALUES (?,?) ON CONFLICT(device_uuid) DO UPDATE SET sequence=excluded.sequence', [deviceUuid, page.next_sequence], false)
    }, true)
  }
  notifications(deviceUuid: string): Promise<{ event: SupportChanges['events'][number]; read: boolean }[]> {
    return this.use(async db => {
      const saved = await this.row(db, 'SELECT payload_json FROM support_context WHERE device_uuid=?', [deviceUuid])
      if (!saved) return []
      const context = JSON.parse(String(saved.payload_json)) as SupportContext
      const allowed = new Set(((await db.query('SELECT server_uuid FROM support_tickets WHERE device_uuid=? AND machine_uuid=? AND server_uuid IS NOT NULL', [deviceUuid, context.machine.uuid])).values ?? []).map(row => String(row.server_uuid)))
      return ((await db.query('SELECT payload_json,read_at FROM support_feed WHERE device_uuid=? ORDER BY sequence DESC LIMIT 100', [deviceUuid])).values ?? [])
        .map(row => ({ event: JSON.parse(String(row.payload_json)) as SupportChanges['events'][number], read: row.read_at !== null }))
        .filter(item => allowed.has(item.event.ticket_uuid))
    })
  }
  markRead(deviceUuid: string, eventUuid: string): Promise<void> {
    return this.use(async db => { await db.run('UPDATE support_feed SET read_at=COALESCE(read_at,?) WHERE device_uuid=? AND event_uuid=?', [new Date().toISOString(), deviceUuid, eventUuid], false) }, true)
  }
}
