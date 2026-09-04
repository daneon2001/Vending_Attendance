# ADR-VEND-010: Preserve edge geofence evidence and recalculate on server

## Status

Accepted — 2026-09-04

## Decision

The event retains the edge result, location/accuracy and geofence version. Laravel recalculates independently against that historical MachineGeofence version whenever evidence is sufficient, preserving `UNCERTAIN` and adding `NOT_EVALUATED` for insufficient evidence.

## Consequences

Edge/server behavior can be measured before enforcing a rejection policy. Historical geofence versions must remain available. Missing, invalid or low-quality GPS is explicit and never replaced or automatically classified as outside.
