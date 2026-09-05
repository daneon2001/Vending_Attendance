# ADR-VEND-017: Biometric template protection

- Status: Proposed
- Date: 2026-09-05

## Decision

Future biometric metadata and material are separate. Material must use authenticated envelope encryption
at the server, TLS in transit and device-bound encrypted storage at edge. Raw captures and plaintext
templates are forbidden in logs, Vue persistent state, localStorage, sessionStorage and SQLite.

## Consequences

The future persistence model needs key references, nonce, authentication tag, rotation and revocation.
Android Keystore or Apple Keychain/Secure Enclave protects edge keys. KMS/HSM choice, per-record versus
SQLCipher storage and per-Device delivery encryption remain deferred until SDK data-flow constraints are
known; no production template table is created by this ADR.
