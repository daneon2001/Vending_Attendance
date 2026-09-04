# ADR-VEND-005: Ephemeral single-use device provisioning

- Status: Accepted
- Date: 2026-09-04

## Context

An installer must register one physical device without receiving an administrator session or a secret shared by the entire fleet. Provisioning must be safe against database disclosure, token reuse, and concurrent attempts.

## Decision

Bind a 256-bit random provisioning token to one vending machine. Persist only its SHA-256 hash, set an expiration, allow administrative revocation, and consume it once in the same transaction that creates and activates the device. Return the new per-device credential only in the successful provisioning response. Use row locks and database uniqueness for concurrency control.

## Consequences

Possession of a short-lived token authorizes exactly one installation. Lost plaintext tokens cannot be recovered and must be revoked/reissued. Installers must capture the returned device credential securely because the server will not reveal it again. Brute-force exposure is reduced by token entropy, generic failures, expiration, and a restrictive IP rate limit.
