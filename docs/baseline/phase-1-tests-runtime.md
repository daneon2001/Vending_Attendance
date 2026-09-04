# Phase 1 test runtime

Date: 2026-09-04

## Before functional changes

- Command: `php artisan test --no-ansi`
- Total: 282
- Passed: 281
- Failed: 1
- Skipped: 0
- Assertions: 2,296
- Duration: 58.38 s

## Final

- Command: `php artisan test --no-ansi`
- Total: 306
- Passed: 305
- Failed: 1
- Skipped: 0
- Assertions: 2,408
- Duration: 16.97 s

All 24 tests introduced for the vending domain pass (112 assertions in the directed run).

## Inherited failure

`Tests\Feature\Console\OnPremDiagnosticsCommandTest::test_onprem_diagnostics_command_passes_and_generates_json_report`

The failure exists in the pre-change baseline. Its isolated fixture creates OnPrem-related tables but omits `clocks`, while the current inherited `OnPremHeartbeatController` queries `clocks`. The diagnostic succeeds against the fully migrated local MySQL database. Phase 1 does not change this legacy fixture or OnPrem business behavior.
