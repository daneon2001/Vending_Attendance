# Phase 2 runtime validation

Date: 2026-09-04

## Baseline before Phase 2 changes

- Command: `php artisan test --no-ansi`
- Total: 306
- Passed: 305
- Failed: 1
- Skipped: 0
- Assertions: 2,408
- Duration: 17.11 seconds
- Pre-existing failure: `Tests\\Feature\\Console\\OnPremDiagnosticsCommandTest`; its isolated fixture lacks the legacy `clocks` table required by the diagnostic command.

## Final validation

- Command: `php artisan test --no-ansi`
- Total: 325
- Passed: 324
- Failed: 1
- Skipped: 0
- Assertions: 2,531
- Duration: 18.36 seconds
- Regression assessment: no new failure; the only failure is the same inherited OnPrem diagnostic fixture.
- Phase 2 device API/security suite: 17 passed, 98 assertions.
- Directed Phase 2/API/admin suite: 26 passed, 176 assertions.
- Legacy OnPrem HMAC/attendance/heartbeat compatibility subset: 18 passed, 78 assertions.
- Frontend: `npm run build` passed; only the inherited stale Browserslist data warning was emitted.

## Database validation

- Engine: MySQL 8.4.3
- Host: `127.0.0.1`
- Port: `3307`
- Database: `vending_attendance_dev`
- Local user: `vending_local`
- Migration `2026_09_04_000006_extend_devices_for_vending_identity`: ran in batch 3.
- Migration `2026_09_04_000007_create_device_provisioning_tokens_table`: ran in batch 3.
- Migration `2026_09_04_000008_require_device_uuid`: ran in batch 4 and requires the backfilled device UUID at database level.

No database password, device credential, provisioning token, signature, or application secret is recorded in this document.
