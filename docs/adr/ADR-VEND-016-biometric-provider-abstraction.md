# ADR-VEND-016: Biometric provider abstraction

- Status: Accepted
- Date: 2026-09-05

## Decision

All mobile biometric behavior crosses a vendor-neutral `BiometricProvider` boundary with explicit
capabilities and typed outcomes. Identification is optional. Vendor SDK code remains behind a native
Capacitor bridge; Vue never implements matching or receives plaintext biometric material. Until a real
provider is selected, `UnsupportedBiometricProvider` returns only `NOT_SUPPORTED`.

## Consequences

UI and sync depend on measured capabilities instead of fingerprint/face assumptions. Android and iOS may
select different providers without changing attendance domain logic. Provider adapters require real SDK,
hardware and licensing evidence; no fake provider may return `MATCH`.
