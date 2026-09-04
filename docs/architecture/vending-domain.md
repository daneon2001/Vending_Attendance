# Vending domain

## Scope and ownership

The vending domain is introduced beside the inherited BiometricoML domain. It does not replace `branches`, `locations`, or `units` and does not use any of them as machine identity.

- **Fortia** remains the employee System of Record. `Employee` is its local projection.
- **SYBIML** is the vending-machine catalog System of Record. Its official read-only API is projected by unique `id_sucursal`; `identificador_vending` is not assumed globally unique at the source boundary.
- **This system** owns assignments, geofences, device association, operational authorization, auditing, and future synchronization state.

## Entities and relationships

```text
Employee 1 ── * EmployeeMachineAssignment * ── 1 VendingMachine
                                                     │
                                                     ├── * MachineGeofence
                                                     └── * Device
```

### VendingMachine

An independent aggregate identified externally by an immutable UUID. `machine_code` is unique; `sybi_id` is unique when present. Textual address is deliberately separate from latitude/longitude. `coordinates_verified` can only represent a real, non-zero coordinate pair.

Operational states are `DRAFT`, `ACTIVE`, `INACTIVE`, `MAINTENANCE`, and `RETIRED`. Relevant operational changes increment `config_version`. Publishing a geofence also increments it.

Catalog origin is explicit (`LOCAL`, `DEMO`, or `SYBI`). A promoted SYBIML machine retains external city/state IDs without fabricating locality names. New promoted rows are `DRAFT`. Source absence marks `SOURCE_MISSING`; it does not delete or retire the machine.

### SybiVendingSourceRecord

The source projection records what SYBIML published independently of operational eligibility. It is uniquely keyed by `sybi_id`, permits repeated `identificador_vending` values across source rows, retains incomplete or zero coordinates, stores typed validation state and codes, and links to a promoted machine only when unambiguous. It stores normalized governed fields and a content hash, never full payloads or credentials.

### EmployeeMachineAssignment

An explicit, historical N:M entity. It supports simultaneous and bounded assignments without making `Employee` belong to a machine. Types are `PRIMARY`, `TEMPORARY`, `SUBSTITUTE`, `SUPERVISOR`, `TECHNICIAN`, and `ROUTE`.

Permissions (`attendance_allowed`, `enrollment_allowed`, `maintenance_allowed`) are independent of type. An effective assignment must be `ACTIVE`, not revoked, started, and not expired. Normal removal is revocation, never physical deletion.

### MachineGeofence

An immutable-version concept. Phase 1 persists shape `CIRCLE`; `POLYGON` is reserved in the enum but deliberately rejected by the current validator. A unique nullable active lock at the database level prevents two active versions for one machine, including concurrent activation attempts.

Activation is transactional: the previous `ACTIVE` version becomes `SUPERSEDED`, its validity closes, and the candidate becomes `ACTIVE`. History is retained.

### Device

The inherited `devices.unit_id` remains for legacy BiometricoML behavior. The optional `devices.vending_machine_id` is a parallel vending association; no migration or inference between identities occurs in this phase.

## Services

- `MachineAuthorizationService` answers attendance, enrollment, and maintenance authorization exclusively from effective assignments and an active machine.
- `MachineGeofenceService` allocates versions and publishes them transactionally.
- `GeofenceValidationService` returns structured spatial results, not a boolean.
- `VendingMachineImportService` accepts generic rows and reports created, updated, unchanged, and rejected counts. It upserts by `sybi_id`, or by `machine_code` when SYBI ID is absent, and protects manually verified coordinates unless an explicit overwrite policy is passed.
- `SybiVendingApiClient` is the isolated, read-only server-side adapter for the official catalog contract.
- `SybiVendingSyncService` ingests the durable source projection, detects typed validation issues, records source/operational metrics, protects empty responses, excludes DEMO data, and never modifies local operational relationships.
- `SybiVendingPromotionService` is the only path from a `READY` source row to `VendingMachine`; it preserves operational `machine_code` uniqueness and the existing coordinate/geofence review policy.

If SYBIML changes the machine coordinates, the machine `config_version` advances and the existing active geofence remains unchanged. `geofence_review_required` makes this boundary visible until an administrator deliberately publishes a replacement geofence version.

## Audit events

Existing append-only `audit_logs` infrastructure records actor, time, entity, and safe before/after fields for:

- `vending_machine.created`, `.updated`, `.activated`, `.deactivated`, `.retired`
- `assignment.created`, `.updated`, `.revoked`
- `geofence.created`, `.activated`, `.superseded`

Free-form metadata is excluded from before/after snapshots to avoid propagating secrets or biometric data.

## Future manifests

`VendingMachine.config_version`, versioned geofences, UUIDs, and explicit assignments provide stable inputs for later Machine, Employee, Biometric, and Configuration manifests. No offline synchronization protocol is implemented in Phase 1.
