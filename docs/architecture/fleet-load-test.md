# Fleet load test

## Reproducible method

The opt-in test `tests/Performance/VendingFleetLoadBenchmarkTest.php` exercises the Laravel HTTP kernel, real HMAC middleware and MySQL with 1,000 synthetic machines/devices. It runs four requests per device:

1. heartbeat;
2. manifest status;
3. bootstrap;
4. one-event attendance batch.

It records request throughput, p50/p95/p99 latency per scenario, errors, Laravel query count, PHP CPU time, peak PHP memory, MySQL connected threads and selected `EXPLAIN` keys. Fixture credentials are generated in memory and are never printed. The test uses a transaction and requires both:

- `RUN_VENDING_FLEET_BENCHMARK=1`;
- a MySQL database whose name contains the token `testing`.

It refuses SQLite and non-testing database names. The benchmark is outside the default unit/feature suites to prevent accidental destructive or slow execution.

Example invocation in PowerShell, with local credentials supplied by the existing environment and never echoed:

```powershell
$env:RUN_VENDING_FLEET_BENCHMARK='1'
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='vending_attendance_phase8_testing'
php artisan test tests/Performance/VendingFleetLoadBenchmarkTest.php
```

## Local result (2026-09-05)

Environment: dedicated `vending_attendance_phase8_testing` database on local MySQL `127.0.0.1:3306`, one PHP test process and one observed MySQL connection. All test data ran in the test transaction; no development or production data was used.

| Scenario | Requests | req/s | p50 | p95 | p99 | Queries/request |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Heartbeat | 1,000 | 28.62 | 34.09 ms | 54.28 ms | 71.14 ms | 14 |
| Manifest status | 1,000 | 32.64 | 29.08 ms | 50.05 ms | 65.67 ms | 17 |
| Bootstrap | 1,000 | 43.37 | 20.99 ms | 37.91 ms | 51.45 ms | 12 |
| Attendance batch (one event) | 1,000 | 32.09 | 28.40 ms | 52.92 ms | 75.06 ms | 17 |

Aggregate: 4,000 requests in 119.801 seconds, 33.39 req/s, zero errors, 60,002 Laravel-observed queries, 52.844 PHP CPU seconds, 72 MB peak PHP memory and one MySQL connected thread.

`EXPLAIN` selected:

- health scan: `devices_status_heartbeat_idx`;
- app distribution: `devices_platform_app_version_idx`;
- attendance by device/time: `vend_att_device_captured_idx`.

At the configured 60-second heartbeat cadence, 1,000 devices average 16.7 heartbeat requests/second. The measured single-process heartbeat throughput was 28.62 req/s (about 1.7 times that average) with zero errors. This is sufficient evidence for the requested local baseline, but the headroom is not large enough to declare a production SLA. The client applies ±20% jitter to periodic scheduling so steady-state devices do not align on the same second; a production-like reconnect storm still requires a staging test.

Query amplification remains visible: 12–17 queries per authenticated request, including device authentication, nonce persistence, last-seen update and endpoint work. It is acceptable for the measured baseline, but it is the first optimization target if staging load, database CPU or connection concurrency fails its SLO. The lightweight admin registry uses a fixed query count and does not invoke full manifests per row.

## Interpretation limits

This is a controlled single-process developer-workstation measurement. It can reveal query amplification, index misuse, validation errors and gross capacity constraints. It cannot establish production SLA, WAN behavior, horizontal-scaling limits or failover capacity. A production-like staging test remains required before operational rollout.
