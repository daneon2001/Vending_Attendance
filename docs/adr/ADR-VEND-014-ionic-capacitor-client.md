# ADR-VEND-014: Ionic Capacitor edge client

- Status: Accepted
- Date: 2026-09-04

## Decision

Build the vending installation client as an independent Ionic Vue/TypeScript project under `mobile/`,
wrapped by Capacitor with Android and iOS native projects. The existing Vue/Inertia application remains
the human administration surface and is not reused as the device runtime.

## Consequences

One TypeScript domain and UI can target both platforms while native plugins provide secure storage,
SQLite, network status and foreground GPS. Native platform builds and permission reviews remain
explicit release gates. The client consumes only Laravel API v1 and never contacts SYBI or Fortia.
