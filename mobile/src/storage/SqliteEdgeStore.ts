import { Capacitor } from '@capacitor/core'
import {
  CapacitorSQLite,
  SQLiteConnection,
  type SQLiteDBConnection,
} from '@capacitor-community/sqlite'
import type {
  AttendancePayload,
  AttendanceSyncResult,
  BootstrapResponse,
  ConfigurationManifestResponse,
  EmployeeManifestResponse,
  ManifestType,
} from '@/domain/types'
import { EdgeError } from '@/domain/errors'
import type {
  EdgeStore,
  AttendanceContext,
  EffectiveEmployee,
  LocalSyncSummary,
  PendingOutboxEvent,
} from './EdgeStore'
import { DATABASE_NAME, DATABASE_VERSION, MIGRATION_1 } from './schema'

type Row = Record<string, string | number | null>

export class SqliteEdgeStore implements EdgeStore {
  private db: SQLiteDBConnection | null = null

  async initialize(): Promise<void> {
    if (!Capacitor.isNativePlatform()) {
      throw new EdgeError('DATABASE_ERROR', 'SQLite edge requiere Android o iOS nativo.')
    }

    if (this.db && (await this.db.isDBOpen()).result) return

    try {
      const sqlite = new SQLiteConnection(CapacitorSQLite)
      const existing = await sqlite.isConnection(DATABASE_NAME, false)
      this.db = existing.result
        ? await sqlite.retrieveConnection(DATABASE_NAME, false)
        : await sqlite.createConnection(DATABASE_NAME, false, 'no-encryption', DATABASE_VERSION, false)
      if (!(await this.db.isDBOpen()).result) await this.db.open()
      const versionRows = await this.db.query('PRAGMA user_version;')
      const version = Number((versionRows.values?.[0] as Row | undefined)?.user_version ?? 0)
      if (version < 1) await this.db.execute(MIGRATION_1, true)
      if (version > DATABASE_VERSION) {
        throw new Error(`Database version ${version} is newer than supported ${DATABASE_VERSION}`)
      }
      await this.resetInterruptedOutbox()
    } catch (error) {
      throw new EdgeError('DATABASE_ERROR', 'No fue posible inicializar SQLite.', false, {
        cause: error instanceof Error ? error.message : 'unknown',
      })
    }
  }

  private connection(): SQLiteDBConnection {
    if (!this.db) throw new EdgeError('DATABASE_ERROR', 'SQLite no está inicializado.')
    return this.db
  }

  private async transaction<T>(operation: (db: SQLiteDBConnection) => Promise<T>): Promise<T> {
    const db = this.connection()
    await db.beginTransaction()
    try {
      const result = await operation(db)
      await db.commitTransaction()
      return result
    } catch (error) {
      await db.rollbackTransaction()
      throw error
    }
  }

  async saveProvisionedDevice(deviceUuid: string, status: string, credentialVersion: number): Promise<void> {
    const now = new Date().toISOString()
    await this.connection().run(
      `INSERT INTO device_state (id, device_uuid, status, credential_version, updated_at)
       VALUES (1, ?, ?, ?, ?)
       ON CONFLICT(id) DO UPDATE SET device_uuid=excluded.device_uuid, status=excluded.status,
         credential_version=excluded.credential_version, updated_at=excluded.updated_at`,
      [deviceUuid, status, credentialVersion, now],
    )
  }

  async applyBootstrap(response: BootstrapResponse): Promise<void> {
    const now = new Date().toISOString()
    await this.transaction(async (db) => {
      await db.run(
        `UPDATE device_state SET status=?, updated_at=? WHERE id=1`,
        [response.device.status, now],
        false,
      )
      await db.run(
        `INSERT INTO machine_state
          (id, machine_uuid, machine_code, status, timezone, config_version, updated_at)
         VALUES (1, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET machine_uuid=excluded.machine_uuid,
          machine_code=excluded.machine_code, status=excluded.status, timezone=excluded.timezone,
          config_version=excluded.config_version, updated_at=excluded.updated_at`,
        [response.machine.uuid, response.machine.machine_code, response.machine.status,
          response.machine.timezone, response.machine.config_version, now],
        false,
      )
      await db.run('DELETE FROM geofence_state WHERE id=1', [], false)
      if (response.geofence) {
        await db.run(
          `INSERT INTO geofence_state
            (id, geofence_uuid, version, type, latitude, longitude, radius_m,
             minimum_acceptable_accuracy_m, tolerance_m, updated_at)
           VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          [response.geofence.uuid, response.geofence.version, response.geofence.type,
            response.geofence.center_latitude, response.geofence.center_longitude,
            response.geofence.radius_m, response.geofence.minimum_acceptable_accuracy_m,
            response.geofence.tolerance_m, now],
          false,
        )
      }
      await this.setSyncValue(db, 'server_time', response.server_time)
    })
  }

  async applyConfigurationManifest(manifest: ConfigurationManifestResponse): Promise<void> {
    const now = new Date().toISOString()
    await this.transaction(async (db) => {
      await db.run(
        `INSERT INTO machine_state
          (id, machine_uuid, machine_code, status, timezone, config_version, updated_at)
         VALUES (1, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET machine_uuid=excluded.machine_uuid,
          machine_code=excluded.machine_code, status=excluded.status, timezone=excluded.timezone,
          config_version=excluded.config_version, updated_at=excluded.updated_at`,
        [manifest.machine.uuid, manifest.machine.machine_code, manifest.machine.status,
          manifest.machine.timezone, manifest.manifest_version, now],
        false,
      )
      await db.run('DELETE FROM geofence_state WHERE id=1', [], false)
      if (manifest.geofence) {
        await db.run(
          `INSERT INTO geofence_state
            (id, geofence_uuid, version, type, latitude, longitude, radius_m,
             minimum_acceptable_accuracy_m, tolerance_m, updated_at)
           VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          [manifest.geofence.uuid, manifest.geofence.version, manifest.geofence.type,
            manifest.geofence.latitude, manifest.geofence.longitude, manifest.geofence.radius_m,
            manifest.geofence.minimum_acceptable_accuracy_m, manifest.geofence.tolerance_m, now],
          false,
        )
      }
      await this.writePendingManifest(db, 'CONFIGURATION', manifest.manifest_version, manifest.manifest_hash, now)
    })
  }

  async applyEmployeeManifest(manifest: EmployeeManifestResponse): Promise<void> {
    if (!manifest.employees) return
    const now = new Date().toISOString()
    await this.transaction(async (db) => {
      await db.run('DELETE FROM employee_assignments', [], false)
      await db.run('DELETE FROM employees', [], false)
      for (const employee of manifest.employees ?? []) {
        await db.run(
          `INSERT INTO employees (employee_id, employee_number, name, manifest_version)
           VALUES (?, ?, ?, ?)
           ON CONFLICT(employee_id) DO UPDATE SET employee_number=excluded.employee_number,
            name=excluded.name, manifest_version=excluded.manifest_version`,
          [employee.employee_id, employee.employee_number, employee.name, manifest.manifest_version],
          false,
        )
        await db.run(
          `INSERT INTO employee_assignments
            (assignment_uuid, employee_id, assignment_type, valid_from, valid_until,
             attendance_allowed, enrollment_allowed, maintenance_allowed, manifest_version)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          [employee.assignment.uuid, employee.employee_id, employee.assignment.type,
            employee.assignment.valid_from, employee.assignment.valid_until,
            Number(employee.assignment.attendance_allowed), Number(employee.assignment.enrollment_allowed),
            Number(employee.assignment.maintenance_allowed), manifest.manifest_version],
          false,
        )
      }
      await this.writePendingManifest(db, 'EMPLOYEES', manifest.manifest_version, manifest.manifest_hash, now)
    })
  }

  private async writePendingManifest(
    db: SQLiteDBConnection,
    type: ManifestType,
    version: number,
    hash: string,
    now: string,
  ): Promise<void> {
    await db.run(
      `INSERT INTO manifest_state
        (manifest_type, server_version, applied_version, applied_hash, ack_status, updated_at)
       VALUES (?, ?, ?, ?, 'LOCAL_APPLIED_PENDING_ACK', ?)
      ON CONFLICT(manifest_type) DO UPDATE SET server_version=excluded.server_version,
        applied_version=excluded.applied_version, applied_hash=excluded.applied_hash,
        ack_status=excluded.ack_status, last_error=NULL, updated_at=excluded.updated_at`,
      [type, version, version, hash, now],
      false,
    )
  }

  async markManifestAcknowledged(type: ManifestType, version: number, hash: string): Promise<void> {
    await this.connection().run(
      `UPDATE manifest_state SET ack_status='ACKNOWLEDGED', applied_version=?, applied_hash=?,
       last_error=NULL, updated_at=? WHERE manifest_type=?`,
      [version, hash, new Date().toISOString(), type],
    )
    await this.setSyncValue(this.connection(), 'last_sync_at', new Date().toISOString())
  }

  async markManifestAckFailed(type: ManifestType, message: string): Promise<void> {
    await this.connection().run(
      `UPDATE manifest_state SET ack_status='ACK_FAILED', last_error=?, updated_at=?
       WHERE manifest_type=?`,
      [message.slice(0, 500), new Date().toISOString(), type],
    )
  }

  async getAppliedManifestVersion(type: ManifestType): Promise<number | null> {
    const rows = await this.connection().query(
      'SELECT applied_version FROM manifest_state WHERE manifest_type=? LIMIT 1',
      [type],
    )
    const value = (rows.values?.[0] as Row | undefined)?.applied_version
    return value === undefined || value === null ? null : Number(value)
  }

  async getEffectiveEmployees(at: Date): Promise<EffectiveEmployee[]> {
    const timestamp = at.toISOString()
    const rows = await this.connection().query(
      `SELECT e.employee_id, e.employee_number, e.name,
        a.assignment_uuid, a.assignment_type, a.valid_from, a.valid_until,
        a.attendance_allowed, a.enrollment_allowed, a.maintenance_allowed
       FROM employees e JOIN employee_assignments a ON a.employee_id=e.employee_id
       WHERE a.attendance_allowed=1 AND a.valid_from<=?
         AND (a.valid_until IS NULL OR a.valid_until>=?)
       ORDER BY e.name, e.employee_number`,
      [timestamp, timestamp],
    )

    return (rows.values ?? []).map((value) => {
      const row = value as Row
      return {
        employee_id: String(row.employee_id),
        employee_number: String(row.employee_number),
        name: String(row.name),
        assignment: {
          uuid: String(row.assignment_uuid),
          type: String(row.assignment_type),
          valid_from: String(row.valid_from),
          valid_until: row.valid_until === null ? null : String(row.valid_until),
          attendance_allowed: Boolean(row.attendance_allowed),
          enrollment_allowed: Boolean(row.enrollment_allowed),
          maintenance_allowed: Boolean(row.maintenance_allowed),
        },
        effective: true as const,
      }
    })
  }

  async getAttendanceContext(): Promise<AttendanceContext> {
    const machine = (await this.connection().query(
      'SELECT timezone, config_version FROM machine_state WHERE id=1',
    )).values?.[0] as Row | undefined
    const geofence = (await this.connection().query(
      'SELECT * FROM geofence_state WHERE id=1',
    )).values?.[0] as Row | undefined
    const employees = (await this.connection().query(
      "SELECT applied_version FROM manifest_state WHERE manifest_type='EMPLOYEES'",
    )).values?.[0] as Row | undefined
    if (!machine || !geofence || employees?.applied_version == null) {
      throw new EdgeError('INVALID_GEOFENCE', 'Falta configuración edge aplicada; sincroniza antes de checar.')
    }
    return {
      timezone: String(machine.timezone),
      configurationVersion: Number(machine.config_version),
      employeeManifestVersion: Number(employees.applied_version),
      geofence: {
        uuid: String(geofence.geofence_uuid),
        version: Number(geofence.version),
        type: 'CIRCLE',
        latitude: Number(geofence.latitude),
        longitude: Number(geofence.longitude),
        radius_m: Number(geofence.radius_m),
        minimum_acceptable_accuracy_m: geofence.minimum_acceptable_accuracy_m == null
          ? null : Number(geofence.minimum_acceptable_accuracy_m),
        tolerance_m: geofence.tolerance_m == null ? null : Number(geofence.tolerance_m),
      },
    }
  }

  async enqueueAttendance(payload: AttendancePayload): Promise<void> {
    const now = new Date().toISOString()
    await this.transaction(async (db) => {
      await db.run(
        `INSERT INTO attendance_events
          (event_uuid, employee_id, assignment_uuid, event_type, captured_at,
           payload_json, geofence_result, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        [payload.event_uuid, payload.employee_id, payload.assignment_uuid, payload.event_type,
          payload.captured_at, JSON.stringify(payload), payload.geofence.edge_result, now],
        false,
      )
      await db.run(
        `INSERT INTO sync_outbox (event_uuid, status, retry_count, updated_at)
         VALUES (?, 'PENDING', 0, ?)`,
        [payload.event_uuid, now],
        false,
      )
    })
  }

  async getPendingOutbox(limit: number): Promise<PendingOutboxEvent[]> {
    const now = new Date().toISOString()
    return this.transaction(async (db) => {
      const result = await db.query(
        `SELECT o.event_uuid, o.retry_count, e.payload_json
         FROM sync_outbox o JOIN attendance_events e ON e.event_uuid=o.event_uuid
         WHERE o.status='PENDING' AND (o.next_attempt_at IS NULL OR o.next_attempt_at<=?)
         ORDER BY e.created_at LIMIT ?`,
        [now, limit],
      )
      const rows = (result.values ?? []) as Row[]
      for (const row of rows) {
        await db.run(
          `UPDATE sync_outbox SET status='SYNCING', updated_at=? WHERE event_uuid=? AND status='PENDING'`,
          [now, row.event_uuid],
          false,
        )
      }
      return rows.map((row) => ({
        eventUuid: String(row.event_uuid),
        payload: JSON.parse(String(row.payload_json)) as AttendancePayload,
        retryCount: Number(row.retry_count),
      }))
    })
  }

  async applyOutboxResults(results: AttendanceSyncResult[]): Promise<void> {
    const now = new Date().toISOString()
    await this.transaction(async (db) => {
      for (const result of results) {
        if (result.status === 'STORED' || result.status === 'DUPLICATE') {
          await db.run(
            `UPDATE sync_outbox SET status='SYNCED', remote_id=?, last_error_code=NULL,
             updated_at=? WHERE event_uuid=?`,
            [result.remote_id ?? null, now, result.event_uuid],
            false,
          )
        } else {
          await db.run(
            `UPDATE sync_outbox SET status='REJECTED', last_error_code=?, updated_at=?
             WHERE event_uuid=?`,
            [result.error_code ?? 'REJECTED', now, result.event_uuid],
            false,
          )
        }
      }
    })
  }

  async releaseOutbox(eventUuids: string[], errorCode: string): Promise<void> {
    const now = new Date()
    await this.transaction(async (db) => {
      for (const eventUuid of eventUuids) {
        const retry = (await db.query(
          'SELECT retry_count FROM sync_outbox WHERE event_uuid=?',
          [eventUuid],
        )).values?.[0] as Row | undefined
        const retryCount = Number(retry?.retry_count ?? 0) + 1
        const delaySeconds = Math.min(300, 2 ** Math.min(retryCount, 8))
        await db.run(
          `UPDATE sync_outbox SET status='PENDING', retry_count=?, next_attempt_at=?,
           last_error_code=?, updated_at=? WHERE event_uuid=? AND status='SYNCING'`,
          [retryCount, new Date(now.getTime() + delaySeconds * 1000).toISOString(),
            errorCode.slice(0, 100), now.toISOString(), eventUuid],
          false,
        )
      }
    })
  }

  async resetInterruptedOutbox(): Promise<void> {
    if (!this.db) return
    await this.db.run(
      `UPDATE sync_outbox SET status='PENDING', retry_count=retry_count+1,
       next_attempt_at=?, updated_at=? WHERE status='SYNCING'`,
      [new Date(Date.now() + 30_000).toISOString(), new Date().toISOString()],
    )
  }

  async updateClockDrift(seconds: number): Promise<void> {
    await this.connection().run(
      'UPDATE device_state SET clock_drift_seconds=?, updated_at=? WHERE id=1',
      [seconds, new Date().toISOString()],
    )
  }

  async getSummary(): Promise<LocalSyncSummary> {
    const db = this.connection()
    const machine = (await db.query('SELECT machine_code, config_version FROM machine_state WHERE id=1')).values?.[0] as Row | undefined
    const device = (await db.query('SELECT status FROM device_state WHERE id=1')).values?.[0] as Row | undefined
    const employeeManifest = (await db.query("SELECT applied_version FROM manifest_state WHERE manifest_type='EMPLOYEES'")).values?.[0] as Row | undefined
    const pending = (await db.query("SELECT COUNT(*) AS total FROM sync_outbox WHERE status IN ('PENDING','SYNCING')")).values?.[0] as Row | undefined
    const lastSync = (await db.query("SELECT value FROM sync_state WHERE key='last_sync_at'")).values?.[0] as Row | undefined
    return {
      machineCode: machine?.machine_code ? String(machine.machine_code) : null,
      deviceStatus: device?.status ? String(device.status) : null,
      configurationVersion: machine?.config_version == null ? null : Number(machine.config_version),
      employeeManifestVersion: employeeManifest?.applied_version == null ? null : Number(employeeManifest.applied_version),
      pendingEvents: Number(pending?.total ?? 0),
      lastSyncAt: lastSync?.value ? String(lastSync.value) : null,
    }
  }

  private async setSyncValue(db: SQLiteDBConnection, key: string, value: string): Promise<void> {
    await db.run(
      `INSERT INTO sync_state (key, value, updated_at) VALUES (?, ?, ?)
       ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`,
      [key, value, new Date().toISOString()],
      false,
    )
  }
}
