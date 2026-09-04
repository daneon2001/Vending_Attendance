# ADR-VEND-007: Assignment-scoped employee manifest

- Status: Accepted
- Date: 2026-09-04

## Context

Fortia remains Employee system of record, but a vending installation needs only the people authorized for that machine at the current server time. Legacy branch scope does not represent vending authorization and would over-distribute personal data.

## Decision

Build each employee snapshot exclusively from ACTIVE, unrevoked, effective `EmployeeMachineAssignment` rows with at least one explicit permission and an active Employee projection. Include only operational identifier/name plus assignment identity, permissions, and validity. Exclude future/expired grants and every legacy branch/location scope field.

## Consequences

Authorization remains machine-specific and temporal. Removing an entry from a later full snapshot revokes it at the edge while central Employee, assignment, attendance, and audit history remain intact. Future offline clients have validity windows needed to expire temporary grants locally.
