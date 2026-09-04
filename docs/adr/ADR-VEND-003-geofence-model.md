# ADR-VEND-003: Versioned circular machine geofence

- Status: Accepted
- Date: 2026-09-04

## Context

Attendance needs a reproducible spatial decision and the configuration used at capture time must remain auditable. Overwriting one radius/centre on a machine would erase that history.

## Decision

Persist `MachineGeofence` versions. Phase 1 supports a WGS84 centre plus radius in metres (`CIRCLE`). Activation supersedes the prior version transactionally, and a unique database lock permits only one ACTIVE geofence per machine.

## Consequences

Past configuration remains available. Validation returns `INSIDE`, `OUTSIDE`, or `UNCERTAIN` with measurements and reason. Polygon support is deferred but isolated behind the geofence service and shape field.
