# ADR-VEND-004: Independent device identity

- Status: Accepted
- Date: 2026-09-04

## Context

A vending machine is a durable operational asset, while the tablet/terminal and its application installation can be replaced. Machine codes and hardware serials are inventory identifiers and must not be fleet credentials. The inherited OnPrem `Device` already carries relationships that must remain compatible.

## Decision

Evolve the existing `Device` entity instead of creating a parallel table. Give each vending installation a UUID, lifecycle, individual encrypted symmetric credential, applied configuration versions, and latest heartbeat state. A machine may retain multiple historical devices, but a unique active-machine key permits at most one ACTIVE primary device. New vending authentication is selected explicitly by `device.hmac:vending`; legacy OnPrem authentication remains separate in the same middleware implementation.

## Consequences

Hardware replacement does not alter vending-machine identity or business history. Revoked and retired devices cannot authenticate. The schema preserves `unit_id`, `clock_id`, and legacy fields. Symmetric HMAC is an interim choice compatible with the existing protocol; asymmetric or hardware-backed identity remains open for future hardening.
