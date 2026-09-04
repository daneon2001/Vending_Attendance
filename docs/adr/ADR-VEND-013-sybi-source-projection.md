# ADR-VEND-013: Separate SYBIML source projection from operational machines

## Status

Accepted — 2026-09-04

## Context

The real SYBIML response contains structurally identifiable rows that are not yet operationally usable: coordinates may be `0,0`, and multiple `id_sucursal` values may publish the same `identificador_vending`. Rejecting those rows loses evidence and obscures future corrections. Promoting them directly would violate the identity and location guarantees required by Devices, manifests, geofences, and attendance.

## Decision

Persist normalized provider state in `sybi_vending_source_records`, uniquely identified by `id_sucursal` (`sybi_id`). Do not impose uniqueness on source `identificador_vending`. Store typed validation status, multiple validation codes, first/last presence, a deterministic payload hash, and an optional promotion link. Never store bearer credentials, headers, or the full source payload.

Promote through `SybiVendingPromotionService` only when a row is `READY`: it has a valid identifier, valid non-zero coordinates, no structural error, and no identifier collision. `VendingMachine.machine_code` remains unique because it is operational identity. A `0,0` row remains visible as `INCOMPLETE_LOCATION`; all rows sharing a duplicate identifier become `IDENTIFIER_CONFLICT`. No row is arbitrarily selected.

Source absence marks the projection `SOURCE_MISSING` and never deletes the source row or its operational machine. A later correction is re-evaluated and may promote or safely update the existing machine. The active geofence is never rewritten automatically.

## Consequences

Operations can see provider quality independently from machine readiness, and source corrections are naturally idempotent. The system carries one additional table and explicit promotion logic. Existing operational consumers keep the stable `VendingMachine` contract and its unique `machine_code`; no branch, location, unit, Device, assignment, manifest, attendance, or DEMO identity is reinterpreted.
