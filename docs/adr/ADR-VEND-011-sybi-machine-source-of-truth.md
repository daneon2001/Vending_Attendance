# ADR-VEND-011: SYBIML owns the vending-machine catalog

## Status

Accepted — 2026-09-04

## Context

The application needs a stable catalog for approximately 1,000 vending machines while preserving independently owned geofences, assignments, Devices, manifests, attendance, and audit evidence. Branch, Location, and Unit are legacy concepts and are not vending identity.

## Decision

Use SYBIML `id_sucursal` as external source identity. When a row passes the source-projection rules defined by ADR-VEND-013, promote it to `VendingMachine.sybi_id` and use its unambiguous `identificador_vending` as the unique operational `machine_code`. Laravel consumes the official read-only API through a server-side bearer credential and updates only source-owned identity, name, address, external location IDs, and coordinates.

New promoted machines enter as `DRAFT`. Missing rows are marked `SOURCE_MISSING`; they are never automatically deleted or retired. Duplicate source vending identifiers remain visible in the projection but are not promoted. Production manual creation is disabled while the catalog is configured as authoritative; local/testing creation remains available for controlled development.

## Consequences

SYBIML catalog corrections propagate without taking ownership of operational readiness. The application preserves vending history and must define a later approval workflow for definitive source deactivation. The bearer credential exists only on the Laravel server.
