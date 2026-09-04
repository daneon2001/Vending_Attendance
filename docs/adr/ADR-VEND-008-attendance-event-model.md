# ADR-VEND-008: Immutable edge attendance event model

## Status

Accepted — 2026-09-04

## Decision

Vending edge attendance is stored in the independent `vending_attendance_events` table as immutable evidence identified by an edge-generated UUID. It is not forced into `attendance_logs` or `attendances_raw` and is not forwarded to Fortia in Phase 4.

## Consequences

Legacy attendance behavior remains unchanged. Additional snapshots consume storage but keep historical interpretation stable. Authorization denial does not erase an attempted event. Legal/operational retention and later projection into a normalized attendance ledger remain separate decisions.
