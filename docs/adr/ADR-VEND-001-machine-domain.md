# ADR-VEND-001: VendingMachine as an independent entity

- Status: Accepted
- Date: 2026-09-04

## Context

The inherited system contains branch, location, and unit concepts. A physical vending machine has a different lifecycle, external SYBI identity, coordinate verification, device association, and configuration version.

## Decision

Create `VendingMachine` as a first-class aggregate with UUID, unique machine code, optional unique SYBI ID, operational state, independent address and coordinates, and `config_version`.

## Consequences

Legacy location behavior remains intact. Explicit future mapping may associate a machine with organizational catalogs, but none is its identity. Catalog import and future manifests gain a stable machine boundary.
