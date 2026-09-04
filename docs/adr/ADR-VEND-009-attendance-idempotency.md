# ADR-VEND-009: Attendance idempotency by global UUID and payload hash

## Status

Accepted — 2026-09-04

## Decision

`event_uuid` is globally unique. The authenticated Device UUID and normalized edge payload are hashed using SHA-256. Identical retries return the original ID; any UUID reuse with a different Device or hash is a conflict.

## Consequences

Lost responses are safe to retry and database uniqueness protects concurrent requests. A malicious or accidental UUID collision is visible rather than silently accepted. Contract evolution must preserve canonicalization compatibility or introduce an explicit hash version.
