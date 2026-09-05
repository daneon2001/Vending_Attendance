# Offline storage

Database `vending_attendance_edge` uses SQLite `PRAGMA user_version`; Phase 5 schema version is 1.

| Table | Purpose |
|---|---|
| `device_state` | Public device UUID/status, credential version, clock drift; never the credential |
| `machine_state` | Machine identity, timezone and configuration version |
| `geofence_state` | Current desired circular geofence snapshot |
| `employees` | Minimal operational employee projection |
| `employee_assignments` | Effective-window and explicit permission projection |
| `manifest_state` | Server/applied version, hash and ACK state |
| `attendance_events` | Immutable original event payload/evidence |
| `sync_outbox` | Delivery status, retries and server receipt |
| `sync_state` | Last successful synchronization and other non-secret markers |

Configuration and employee full snapshots replace their local desired state inside transactions.
Attendance evidence and outbox intent are inserted in one transaction. Successful delivery changes
only outbox state; the event is never deleted. Interrupted `SYNCING` rows return to `PENDING` with
bounded exponential retry. Database migrations are additive and versioned; destructive reset is not
part of application startup.
