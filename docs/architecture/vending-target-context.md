# Vending target context

This document records a future target only. Phase 0 does not implement any item below.

## Systems of record

- Fortia: system of record for employees.
- SYBI: system of record for the vending-machine catalog.

## Vending Attendance Core

Planned domains:

- Employee Projection
- Vending Machine
- EmployeeMachineAssignment
- Geofence
- Biometric Registry
- Attendance
- Device
- Sync
- Audit

The fixed employee-to-branch relation must not become the new domain model. The target is a temporal many-to-many relation:

```text
Employee
  N:M through EmployeeMachineAssignment
VendingMachine
```

Assignment attributes planned: `valid_from`, `valid_until`, `assignment_type`, `permissions`, and `status`.

## Mobile target

Ionic Vue + Capacitor, targeting Android and iOS. SDK availability and biometric compatibility must be verified independently for each platform; the current repository does not prove iOS support.

## Operating model

- offline-first local operation with SQLite;
- device identity;
- per-machine manifest;
- idempotent synchronization;
- future Machine Manifest, Configuration Version, Employee Manifest and Biometric Manifest;
- future Outbox/Inbox with Event UUID and explicit acknowledgement.

No Ionic, Capacitor, mobile platform, vending schema or geofence dependency was introduced in Phase 0.
