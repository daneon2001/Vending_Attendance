# ADR-VEND-018: Biometric matching strategy

- Status: Proposed
- Date: 2026-09-05

## Decision

Prefer employee selection followed by offline EDGE 1:1 verification for the first biometric MVP. Keep
1:N as an optional provider capability and server matching as a non-offline alternative. This proposal is
conditional on a licensed SDK proving template compatibility, hardware support, quality and acceptable
FAR/FRR on target devices.

## Consequences

1:1 limits CPU and template exposure and works with the existing machine-scoped Employee Manifest. 1:N
cannot be enabled without 10/50/100/500-template benchmarks and a privacy justification. Phase 5 keeps
`BIOMETRIC NOT_USED`; this ADR does not change attendance behavior.
