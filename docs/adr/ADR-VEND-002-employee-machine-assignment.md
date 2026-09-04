# ADR-VEND-002: Temporal employee-machine assignment

- Status: Accepted
- Date: 2026-09-04

## Context

Employees can cover several machines and machines can have primary staff, substitutes, supervisors, and technicians simultaneously. Assignments may be temporary and their permissions differ independently of role names.

## Decision

Model `Employee` ↔ `VendingMachine` as N:M through the explicit historical entity `EmployeeMachineAssignment`, with validity window, lifecycle status, revocation trace, and three independent permission flags.

## Consequences

Authorization queries effective assignments and never vending logic from `base_location_id`, `can_check_all_branches`, or `check_scope`. Historical rows are retained. Route support can later build on the existing `ROUTE` type without changing cardinality.
