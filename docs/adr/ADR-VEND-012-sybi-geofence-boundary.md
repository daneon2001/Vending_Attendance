# ADR-VEND-012: SYBIML coordinate changes do not rewrite geofence history

## Status

Accepted — 2026-09-04

## Context

SYBIML owns the current master position of a vending machine. This application owns versioned geofences that may already have been published to Devices and used as attendance evidence.

## Decision

Update the master machine coordinates when SYBIML reports a change, clear coordinate verification, increment the machine configuration version, and create a sanitized audit event. Never move or overwrite an active `MachineGeofence`.

If an active geofence exists, set `geofence_review_required` and `sybi_sync_status=REVIEW_REQUIRED`. A new geofence version requires an explicit local administrative decision.

## Consequences

Published geofence history remains immutable and historical attendance stays interpretable. Until review is resolved, the machine exposes a visible configuration warning. The application needs a future workflow for acknowledging or resolving that review flag.
