# SYBIML vending catalog API

## Purpose and boundary

SYBIML is the System of Record for vending catalog identity and location. Laravel is the only caller of the read-only endpoint; browsers and Devices never receive the bearer credential and never call SYBIML directly.

```text
Admin browser -> Laravel -> GET configured SYBIML endpoint
```

The integration sends no write request to SYBIML and does not synchronize employees, geofences, assignments, Devices, manifests, attendance, or local operational status.

## Configuration

Server-side environment settings are:

- `SYBI_VENDING_API_URL`
- `SYBI_VENDING_API_TOKEN`
- `SYBI_VENDING_API_CONNECT_TIMEOUT` (default 5 seconds)
- `SYBI_VENDING_API_TIMEOUT` (default 30 seconds)
- `SYBI_VENDING_SYNC_ENABLED` (default `false`)
- `SYBI_VENDING_SYNC_INTERVAL_MINUTES` (default 60)
- `SYBI_VENDING_CATALOG_AUTHORITATIVE` (default `true`)

The token must not be committed, logged, placed in audit metadata, sent to Inertia, or printed by the command.

## Source projection and operational promotion

The provider contract and operational domain are intentionally distinct:

- `SybiVendingSourceRecord` is the durable projection of what SYBIML published, including incomplete or conflicting rows.
- `VendingMachine` is the operational identity used by Devices, assignments, geofences, manifests, and attendance.

`id_sucursal` is unique in the source projection. `identificador_vending` is not assumed unique in SYBIML. It remains unique as `VendingMachine.machine_code` because only a geographically usable, structurally valid, unambiguous source row may be promoted.

| SYBIML | Source projection | Operational promotion |
|---|---|---|
| `id_sucursal` | `sybi_id`, required and unique | `sybi_id` |
| `identificador_vending` | nullable, indexed, non-unique | `machine_code`, required and unique |
| `nombre_sucursal` | `name` | `name` |
| `ubicacion.calle` | `address_line` | `address_line` |
| `ubicacion.colonia` | `neighborhood` | `neighborhood` |
| `ubicacion.codigo_postal` | `postal_code` | `postal_code` |
| `ubicacion.id_ciudad` | `sybi_city_id` | `sybi_city_id` |
| `ubicacion.id_estado` | `sybi_state_id` | `sybi_state_id` |
| `ubicacion.direccion_completa` | `sybi_full_address` | `sybi_full_address` |
| `latitud`, `longitud` | nullable source evidence | promoted only when valid and not `0,0` |

The source projection stores only normalized governed fields and a SHA-256 hash of that canonical representation. It never stores the bearer token, headers, or full provider payload.

## Validation

Validation states are `READY`, `INCOMPLETE_LOCATION`, `IDENTIFIER_CONFLICT`, `INVALID`, and `SOURCE_MISSING`. Multiple codes may coexist:

- `ZERO_COORDINATES`
- `MISSING_COORDINATES`
- `INVALID_COORDINATES`
- `MISSING_VENDING_IDENTIFIER`
- `INVALID_VENDING_IDENTIFIER`
- `DUPLICATE_VENDING_IDENTIFIER`
- structural source diagnostics

A `0,0` pair is retained exactly as source evidence but is not promoted, does not create or move a geofence, and never sets `coordinates_verified`. If the same `id_sucursal` is later corrected, it is re-evaluated. Every row sharing a duplicate `identificador_vending` is flagged, so none is merged or chosen arbitrarily.

## Synchronization algorithm

1. Validate server configuration and fetch the documented list.
2. Validate the top-level response contract.
3. Normalize every structurally identifiable row into a source candidate.
4. Detect duplicate source and vending identifiers.
5. Classify and persist, or dry-run, source state by `id_sucursal`.
6. Promote only `READY` rows through `SybiVendingPromotionService`.
7. Refuse operational machine-code collisions.
8. Mark absent source rows `SOURCE_MISSING` after a non-empty response; delete nothing.
9. Persist sanitized source and operational counters for write runs.

An identical second run is idempotent. `last_seen_at` may advance without classifying governed source content as updated.

## Coordinates and geofences

SYBIML never creates or activates a geofence. When a promoted machine receives changed valid source coordinates, coordinate verification is cleared and `config_version` increments. An active `MachineGeofence` remains immutable and the machine becomes `REVIEW_REQUIRED` until an administrator deliberately publishes a new version.

## Missing and empty-source safety

Absence is not deletion. A missing source row and any linked machine remain available as historical/operational records. An unexpectedly empty response is guarded when a local source projection already exists, emitting `SOURCE_EMPTY_UNEXPECTED`.

`VM-DEMO-*` is reserved for local data. Incoming rows in that range are not projected, and existing demo machines are excluded from reconciliation.

## Runs and command

Write runs store timestamps, status, duration, HTTP status, warnings, and sanitized counters. Source counters distinguish candidates, created, updated, unchanged, and structurally invalid input. Operational counters distinguish ready, created, updated, unchanged, incomplete, identifier conflicts, invalid, and missing. Compatibility counters remain for prior consumers.

```shell
php artisan sybi:sync-vending --dry-run
php artisan sybi:sync-vending --dry-run --show-rejections
php artisan sybi:sync-vending
```

`--dry-run` performs the real GET and all classification without writing source records, machines, run rows, or audit rows. `--show-rejections` prints only bounded identifiers, source index, field, stable code, and fixed safe reason. It never prints payloads, headers, response bodies, or credentials.

Scheduled writes remain disabled by default and require explicit configuration.

## Administration

The Vending Machines area has separate **Operativas** and **Catálogo SYBIML** views. The source view displays source identity, normalized coordinates, presence, validation state/codes, first/last seen timestamps, and any promotion link. Server credentials are never Inertia properties.

## Error contract and limitations

Transport failures return stable safe codes such as `AUTH_ERROR`, `VALIDATION_ERROR`, `SOURCE_ERROR`, `SOURCE_NOT_CONFIGURED`, `NETWORK_ERROR`, `INVALID_RESPONSE`, `SCHEMA_ERROR`, and `SYNC_ERROR`. Provider bodies, SQL, stack traces, and credentials are excluded.

The source currently has no authoritative deletion or operational-status event. Polling cadence, retry policy, and provider SLA remain open. No automatic retry or geofence promotion is implemented.
