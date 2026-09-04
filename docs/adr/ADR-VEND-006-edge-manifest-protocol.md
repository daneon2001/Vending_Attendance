# ADR-VEND-006: Versioned desired-state edge protocol

- Status: Accepted
- Date: 2026-09-04

## Context

Approximately 1,000 intermittently connected installations must determine whether local configuration and authorization caches match central state. Treating download as application would hide persistence failures, while sending the entire Fortia catalogue would violate isolation and data minimization.

## Decision

Expose independent configuration and employee FULL SNAPSHOT manifests with monotonic versions and canonical SHA-256 hashes. Derive the machine from per-device HMAC identity. Require an explicit, idempotent ACK before advancing applied state, and persist per-type acknowledgement/error state in `device_manifest_states`. Keep BIOMETRICS explicitly unsupported.

## Consequences

Devices can poll lightweight status, download only changed desired state, validate persistence, and safely retry ACK. Complete snapshots make removals authoritative. The protocol can later add deltas and biometric manifests without changing Device identity or conflating human authentication.
