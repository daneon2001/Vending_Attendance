# Vending attendance events

## Legacy audit and persistence decision

Phase 4 uses `PARALLEL_NEW_MODEL` and does not change a legacy attendance table:

| Legacy storage | Finding | Decision |
| --- | --- | --- |
| `attendance_logs` | Mutable central/Fortia record coupled to company, location and clock semantics | Keep unchanged |
| `attendances_raw` | OnPrem raw intake coupled to device serial, unit, clock and collaborator | Keep unchanged |
| `attendances` | No corresponding current table/model | No adaptation |
| `asistencias` | Early legacy table later dropped by migration | No adaptation |
| `check_events` | Not present in the codebase or migrations | No adaptation |

Neither existing table can preserve vending Device identity, global event UUID, assignment evidence, historical geofence version, edge/server geofence comparison, independent offline time or manifest versions without changing legacy meaning. `VendingAttendanceEvent` therefore owns the new `vending_attendance_events` table.

## Evidence model

The row stores edge identity and original evidence separately from server evaluation:

- globally unique `event_uuid`, derived Device and VendingMachine IDs;
- Employee and nullable resolved assignment/geofence foreign keys;
- original device capture time and independent server receive time;
- location values, edge geofence result and server result;
- authorization result/reason and manifest-version evidence;
- payload SHA-256, sync delay and server-controlled warning metadata;
- biometric placeholder `NOT_USED`, with no template, image or score.

The Eloquent model rejects updates and deletes. Business or legal retention is intentionally unresolved; no cleanup task is registered. Database administration remains capable of controlled retention only after a later legal/operational decision.

## Historical snapshots

An event remains interpretable after current entities change. It snapshots the employee number, assignment UUID/type/validity/attendance permission, machine code and the referenced historical geofence center/radius/tolerance/version. Foreign keys to Device, machine and Employee use restrictive deletion. Assignment and geofence references may become null only if those records are deliberately removed, while their snapshots remain.

Employee and assignment status are not fully versioned today. When a delayed event predates a relevant update and exact event-time state cannot be reconstructed, authorization is `UNVERIFIABLE/HISTORICAL_STATE_UNAVAILABLE`; the receiver does not invent prior state or reject solely from current inactivity.

## Authorization

Structurally valid evidence is stored even when operational authorization is `DENIED` or `UNVERIFIABLE`. This preserves attempted attendance evidence without treating it as approved attendance or sending it to Fortia.

Evaluation uses the supplied assignment UUID and capture time:

- employee/machine mismatch, future/expired/revoked assignment and disabled attendance are typed denials;
- an absent assignment is unverifiable;
- revocation after capture does not retroactively invalidate the earlier capture;
- stale manifest versions are evidence and do not automatically reject offline events.

## Indexes

The global UUID unique index is the idempotency authority. Composite indexes support Device, machine and Employee timelines. `received_at_server` supports intake windows. Server geofence result/receive time and mismatch/receive time support operational investigations. No broad metadata, snapshot or payload-hash indexes are created.

## Observability

`device_attendance_metrics` maintains one cumulative row per Device with stored, duplicate, rejected and mismatch counts plus delay totals/max and last receipt. Atomic SQL increments avoid a second lock transaction. Exact recent stored/mismatch/delay metrics come from indexed attendance-event queries; rejected and duplicate totals remain aggregate counters rather than high-volume receipt logs.
