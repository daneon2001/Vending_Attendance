export const DATABASE_NAME = 'vending_attendance_edge'
export const DATABASE_VERSION = 1

export const MIGRATION_1 = `
CREATE TABLE IF NOT EXISTS device_state (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  device_uuid TEXT NOT NULL,
  status TEXT NOT NULL,
  credential_version INTEGER NOT NULL,
  clock_drift_seconds INTEGER,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS machine_state (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  machine_uuid TEXT NOT NULL,
  machine_code TEXT NOT NULL,
  status TEXT NOT NULL,
  timezone TEXT NOT NULL,
  config_version INTEGER NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS geofence_state (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  geofence_uuid TEXT NOT NULL,
  version INTEGER NOT NULL,
  type TEXT NOT NULL,
  latitude REAL NOT NULL,
  longitude REAL NOT NULL,
  radius_m REAL NOT NULL,
  minimum_acceptable_accuracy_m REAL,
  tolerance_m REAL,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS employees (
  employee_id TEXT PRIMARY KEY,
  employee_number TEXT NOT NULL,
  name TEXT NOT NULL,
  manifest_version INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS employee_assignments (
  assignment_uuid TEXT PRIMARY KEY,
  employee_id TEXT NOT NULL,
  assignment_type TEXT NOT NULL,
  valid_from TEXT NOT NULL,
  valid_until TEXT,
  attendance_allowed INTEGER NOT NULL,
  enrollment_allowed INTEGER NOT NULL,
  maintenance_allowed INTEGER NOT NULL,
  manifest_version INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS employee_assignments_effective_idx
  ON employee_assignments (attendance_allowed, valid_from, valid_until);
CREATE TABLE IF NOT EXISTS manifest_state (
  manifest_type TEXT PRIMARY KEY,
  server_version INTEGER NOT NULL,
  applied_version INTEGER,
  applied_hash TEXT,
  ack_status TEXT NOT NULL,
  last_error TEXT,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS attendance_events (
  event_uuid TEXT PRIMARY KEY,
  employee_id TEXT NOT NULL,
  assignment_uuid TEXT NOT NULL,
  event_type TEXT NOT NULL,
  captured_at TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  geofence_result TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sync_outbox (
  event_uuid TEXT PRIMARY KEY,
  status TEXT NOT NULL,
  retry_count INTEGER NOT NULL DEFAULT 0,
  next_attempt_at TEXT,
  last_error_code TEXT,
  remote_id TEXT,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS sync_outbox_dispatch_idx
  ON sync_outbox (status, next_attempt_at, updated_at);
CREATE TABLE IF NOT EXISTS sync_state (
  key TEXT PRIMARY KEY,
  value TEXT,
  updated_at TEXT NOT NULL
);
PRAGMA user_version = 1;
`
