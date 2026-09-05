# Attendance outbox

The user selects only an employee present in the local effective assignment projection. Capture reads
high-accuracy foreground GPS, evaluates the local geofence, generates UUIDv4, and stores the complete
attendance payload plus an outbox row atomically. `captured_at` is original device evidence and is not
rewritten using server time.

Outbox states are `PENDING`, `SYNCING`, `SYNCED`, and `REJECTED`. Foreground sync sends at most 50
events to `POST /api/v1/device/attendance/events/batch`. `STORED` and `DUPLICATE` both reach `SYNCED`;
`REJECTED` is retained with its stable error code for operational review. Transport failures return
events to `PENDING` with bounded exponential retry. The original event remains immutable in every
case. Device and machine identities never appear as client-authoritative payload fields.
