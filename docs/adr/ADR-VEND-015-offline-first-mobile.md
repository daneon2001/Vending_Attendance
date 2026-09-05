# ADR-VEND-015: Offline-first mobile attendance

- Status: Accepted
- Date: 2026-09-04

## Decision

Attendance capture commits immutable evidence and an outbox delivery intent to local SQLite before any
network attempt. Full, versioned desired-state manifests provide machine configuration, geofence and
authorized employees. A manifest is acknowledged only after its SQLite transaction commits.

## Consequences

Connectivity loss does not block attendance capture. UUID event identity and Laravel idempotency make
retries safe; rejected evidence remains inspectable. Operational authorization must be evaluated from
the current local assignment snapshot and its validity window. Background synchronization, biometric
templates and conflict UX are deferred to later ADRs.
