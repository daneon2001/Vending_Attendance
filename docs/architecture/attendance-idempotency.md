# Attendance idempotency

## Identity

The edge generates an RFC 4122 UUID and sends it as `event_uuid`. The server never derives identity from a timestamp. Phase 4 chooses a globally unique constraint on `event_uuid`, rather than `(device_id, event_uuid)`, because the protocol declares the UUID globally identifying. Reuse by another Device is therefore a conflict, not a second event.

## Canonical payload hash

SHA-256 is calculated over normalized validated edge fields plus the authenticated Device UUID. Associative keys are recursively sorted, list order is preserved, timestamps are normalized to UTC microsecond form and JSON uses unescaped Unicode/slashes with preserved zero fractions. Server-derived machine ID, receive time, evaluation, warnings and metrics are excluded.

Semantically identical key ordering produces the same hash. Unknown fields are rejected instead of silently joining evidence. Machine and Device identifiers are not accepted from payload.

## Receipt outcomes

- First valid UUID/payload: `STORED`, HTTP 201 for single intake.
- Same Device, UUID and hash: `DUPLICATE`, original `remote_id`, no row mutation.
- Same UUID with different content or Device: `REJECTED/EVENT_UUID_CONFLICT`, HTTP 409 for single intake and a minimal security audit event.

HTTP reports request transport. Every batch member independently reports `STORED`, `DUPLICATE` or `REJECTED`; a rejected item does not roll back valid siblings.

## Concurrency

Each event is received in its own transaction. The service performs a locked identity check and the database unique constraint remains final authority. Duplicate-key races are resolved by re-reading the original event and comparing Device/hash. Batch reception intentionally loops independent event transactions rather than holding up to 100 events in one transaction.

The Phase 4 MySQL integration test uses the empty dedicated `vending_attendance_testing` database and two simultaneous Laravel processes. It verifies one stored/one duplicate for a single UUID, concurrent duplicate batches and the actual MySQL unique index. The database tables are wiped after the isolated test.
