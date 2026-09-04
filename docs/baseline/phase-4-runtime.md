# Phase 4 runtime validation

Validation date: 2026-09-04

## Git baseline

- Branch: `phase/4-attendance-edge`
- Baseline tag: `vending-phase-3-pass`
- Baseline commit: `b96a60fea4b2db6481e3afededcafb4fc5b059ea`
- Initial working tree: clean

## Baseline test suite

- Total: 337
- Passed: 336
- Failed: 1
- Skipped: 0
- Assertions: 2,665
- Duration: 20.23 s

The sole failure is the documented inherited `OnPremDiagnosticsCommandTest` fixture failure.

## Phase 4 validation

Focused Phase 4 plus bootstrap/OnPrem compatibility:

- Passed: 38
- Failed: 0
- Assertions: 271
- Duration: 2.86 s

Final default suite:

- Total: 355
- Passed: 354
- Failed: 1
- Skipped: 0
- Assertions: 2,844
- Duration: 21.50 s
- New regressions: 0

## Real MySQL concurrency

The isolated test used only `vending_attendance_testing` on loopback. It required an empty database before migration, launched two simultaneous Laravel worker processes and wiped only that database's tables afterward.

- Result: PASS
- Tests: 1
- Assertions: 12
- Duration: 17.50 s
- Single duplicate UUID: one `STORED`, one `DUPLICATE`
- Concurrent duplicate batches: 10 `STORED`, 10 `DUPLICATE`
- Rows/unique UUIDs: 11/11
- MySQL unique index: verified

## Controlled benchmark

Environment: PHP 8.4 PHPUnit process, SQLite in-memory, domain service only. HTTP, HMAC, network, production MySQL and true fleet concurrency are not represented, so these values are comparative local measurements rather than a production SLO.

Dataset: 100 Devices, 100 events per Device.

| Mode | Events | Throughput | p50 | p95 | Errors |
| --- | ---: | ---: | ---: | ---: | ---: |
| Single new events | 10,000 | 172.38 events/s | 3.975 ms/event | 8.810 ms/event | 0 |
| Batch new events | 10,000 | 150.83 events/s | 551.652 ms/batch | 783.784 ms/batch | 0 |
| Duplicate retries | 10,000 | 621.04 events/s | 1.410 ms/event | 2.735 ms/event | 0 |

The batch used 100 requests containing 100 events each. Total benchmark duration was 142.23 seconds.

## Database validation

The documented Phase 3 baseline remains present on MySQL 8.4.3 at loopback port 3307 with the two Phase 3 migrations applied. Phase 4 migrations were applied there through an in-memory Laravel connection guarded by the Phase 3 migration records:

- `2026_09_04_000011_create_vending_attendance_events_table`
- `2026_09_04_000012_create_device_attendance_metrics_table`

The ignored local `.env` currently points to a different MySQL 8.0.44 instance on port 3306 where Phase 1–4 migrations are pending. Phase 4 did not modify that user-owned file or migrate the incomplete instance. Local interactive runtime must explicitly select the approved port-3307 baseline before using the new endpoints.

## Dependency and artifact safety

- Composer/npm lockfiles: unchanged
- Ionic/Capacitor: not installed
- Biometric matching/templates: not implemented
- Fortia outbound attendance: not implemented
- Production or shared database access: none
