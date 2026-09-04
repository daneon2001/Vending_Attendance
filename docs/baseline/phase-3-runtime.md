# Phase 3 runtime validation

Validation date: 2026-09-04

## Git baseline

- Phase 2 checkpoint: `94c41638e702a4dcc8408ebbaa9bd246d3e03587`
- Phase 2 tag: `vending-phase-2-pass`
- Phase 3 branch: `phase/3-edge-manifests`

## Test isolation

PHPUnit runs with `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Fortia uses the
local mock driver and loopback-only configuration. The development database is not
used by the test suite.

## Test baseline

- Total: 325
- Passed: 324
- Failed: 1
- Skipped: 0
- Assertions: 2,531
- Duration: 17.62 s

The only failure was the previously documented
`Tests\Feature\Console\OnPremDiagnosticsCommandTest`; its isolated fixture does not
contain the legacy `clocks` table. It is not a Phase 3 regression.

## Phase 3 focused validation

- Tests: 20 passed
- Assertions: 212
- Duration: 2.10 s
- Scope: employee manifests, manifest APIs, acknowledgements, Device Registry and
  Phase 2 bootstrap/heartbeat compatibility.

## Final full suite

- Total: 337
- Passed: 336
- Failed: 1
- Skipped: 0
- Assertions: 2,665
- Duration: 49.80 s
- New regressions: 0

The same inherited OnPrem diagnostic failure remains. The longer wall-clock duration
is recorded as an observation; no functional test regression was detected.

## Frontend

- Command: `npm run build`
- Result: PASS
- Modules transformed: 850
- Warning: the inherited Browserslist `caniuse-lite` dataset is approximately nine
  months old. Dependencies were not updated in this phase.

## Local database

- Engine: MySQL 8.4.3
- Host: `127.0.0.1`
- Port: `3307`
- Database: `vending_attendance_dev`
- Local user: `vending_local`
- Password: intentionally omitted

Applied in batch 5:

- `2026_09_04_000009_add_employee_manifest_version_to_vending_machines`
- `2026_09_04_000010_create_device_manifest_states_table`

Both migrations report `Ran` in `php artisan migrate:status`.

## Manifest routes

The following routes are registered under API middleware, device HMAC authentication
and dedicated rate limiting:

- `GET /api/v1/device/manifests/status`
- `GET /api/v1/device/manifests/configuration`
- `GET /api/v1/device/manifests/employees`
- `POST /api/v1/device/manifests/ack`
