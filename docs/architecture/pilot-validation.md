# Pilot validation record

## Baseline

- Git baseline: `vending-phase-8-pass` at `f3419af`.
- Branch: `phase/9-pilot-readiness`.
- Laravel baseline: 413 passed, 1 inherited `OnPremDiagnosticsCommandTest` failure, 22.49 s.
- Mobile baseline: 31 passed, 0 failed. Final Phase 9 mobile suite: 34 passed, 0 failed.
- Laravel final: 420 passed, 1 inherited `OnPremDiagnosticsCommandTest` failure, 36.03 s. The seven additional passing tests are Phase 9 coverage; no new regression was observed.
- Original `ASISTENCIAS_FORTIA`: clean and untouched at discovery.

## Network resilience matrix

| Scenario | Expected client behavior | Evidence |
|---|---|---|
| Wi-Fi disconnected | Attendance persists locally; state OFFLINE; no destructive retry | Phase 5 physical E2E + atomic outbox tests |
| Wi-Fi restored | Full sync starts; outbox drains; manifests remain coherent | Phase 5 physical E2E + connectivity unit test |
| network switch / DHCP change | ONLINE event starts one coalesced full sync | connectivity listener and single-flight sync test |
| DNS unavailable | Request is retryable NETWORK error; local evidence remains | fetch failure classification and outbox tests |
| server unavailable | Timeout/failure schedules full-sync retry | recovery test |
| slow/stalled connection | Request aborts at configured timeout; app remains responsive | timeout test |
| intermittent packet loss | Failed batch returns entries to retryable outbox with bounded backoff | storage/outbox implementation + recovery tests |

Retries are frequency-bounded: complete-sync retries use jittered exponential delays capped at five minutes; outbox row backoff is capped at five minutes. Rows are never discarded on transport error. Business `REJECTED` remains retained and is not retried indefinitely.

## Attendance pilot matrix

| Case | Expected result |
|---|---|
| ONLINE + INSIDE | Local event/outbox created, server stores `INSIDE`, then local `SYNCED` |
| ONLINE + OUTSIDE | Evidence retained; server independently returns `OUTSIDE`; policy does not rewrite evidence |
| ONLINE + UNCERTAIN | Evidence retained as `UNCERTAIN`; never coerced to `OUTSIDE` |
| OFFLINE + INSIDE | Local event/outbox retained, later stored after reconnect |
| OFFLINE + OUTSIDE | Local evidence retained; later server recalculation remains independent |
| OFFLINE + UNCERTAIN | Local evidence retained; later result may remain `UNCERTAIN` |
| assignment active | `AUTHORIZED` when effective at captured time and attendance allowed |
| assignment revoked | `DENIED` or historical evaluation from retained assignment evidence; never current-state invention |
| assignment expired | `DENIED` when captured outside validity; historical in-range event remains verifiable |
| Device ACTIVE | HMAC request accepted subject to signature/nonce/rate limit |
| Device SUSPENDED | Operational endpoint rejected; local outbox preserved |
| manifest stale | Event accepted as offline evidence and classified stale; not silently dropped |

Existing Phase 4 API tests cover authorization, offline time, geofence `INSIDE/OUTSIDE/UNCERTAIN`, historical geofence versions, idempotency, replay and stale manifests. Phase 9 acceptance tests cover replacement, reassignment and geofence version convergence.

## Recovery evidence

- Process restart without clearing data preserved Device identity, SQLite data and outbox in Phase 5 physical E2E.
- Interrupted `SYNCING` outbox rows are reset to retryable state during SQLite initialization.
- Phase 9 prevents SQLite initialization errors from redirecting to provisioning.
- Full sync now retries after a server outage even when no foreground/network transition occurs.
- A physical Android is available for the soak procedure. A controlled reboot reached `sys.boot_completed=1`; the app relaunched without reprovisioning, and its SQLite database files and encrypted preferences remained present.

A short physical-device recovery smoke was also executed on device `DNY_NX9`: force-stop removed the process, relaunch created a new process, and reboot recovery retained the same application storage. The Android crash buffer contained zero fatal entries and zero references to the vending package. Observed post-reboot process memory was approximately 149 MiB PSS; battery was 97%, device temperature was 34.0 C, and free storage was ample. This is recovery evidence only; its short duration is not represented as the signed 8–24 hour soak.

## Android soak plan

Recommended duration is 8–24 hours. Use the signed PILOT APK and approved HTTPS endpoint; debug/LAN evidence cannot close this gate. Record start/end UTC, device/model/OS, app version/build/hash, API origin fingerprint, initial/final battery and storage, Android crash count, process PSS at intervals, heartbeat gaps, network transitions, manifest versions, outbox high-water mark and final count.

During the run: capture attendance online; disable/restore Wi-Fi; switch approved networks; background/foreground; force-stop/reopen; reboot/unlock/reopen; apply one assignment and geofence change; observe at least one heartbeat interval and one outbox drain. Do not enable background GPS or collect biometric material. Use `adb shell dumpsys meminfo com.medicalife.vendingattendance`, `adb shell dumpsys batterystats` and `adb logcat -b crash` only for operational metrics; sanitize output before attaching evidence.

Status for Phase 9: **prepared with prior process-restart/network evidence; full signed 8–24 h execution remains a launch gate in the selected pilot environment**.

The current Android sources and synchronized web assets compiled successfully with JBR 21 (`assembleDebug`, 252 tasks). This debug artifact is ignored and is not a pilot release. The release preflight intentionally failed without `pilot|production` mode, an HTTPS API origin and externally supplied signing configuration; no unsigned release was published.

## Reconnect storm results

Executed 2026-09-05 against dedicated local MySQL `vending_attendance_testing`. Every simulated device performed heartbeat, manifest status and one pending attendance batch. The harness is a sequential burst through one PHP worker; DB connections therefore remained at one.

| Devices | Requests | Window | RPS | p50 | p95 | p99 | Errors | Queries/request | Lock waits |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 100 | 300 | 8.226 s | 36.47 | 25.94 ms | 42.67 ms | 62.41 ms | 0 | 14.35 | 0 |
| 250 | 750 | 22.044 s | 34.02 | 26.84 ms | 51.82 ms | 73.86 ms | 0 | 14.21 | 0 |
| 500 | 1,500 | 45.452 s | 33.00 | 28.00 ms | 53.08 ms | 68.81 ms | 0 | 14.18 | 0 |
| 1,000 | 3,000 | 86.541 s | 34.67 | 26.88 ms | 49.36 ms | 67.19 ms | 0 | 14.18 | 0 |

Peak process memory was 68–72 MiB. CPU time was 3.563/9.594/20.234/38.094 seconds for the four cohorts. No measured saturation, error or lock-wait inflection occurred; the observed bound is the single application worker at roughly 33–36 requests/s. This is **PILOT_SAFE** for 5–20 devices. It remains **SCALE_RISK** for a simultaneous 1,000-device production reconnect until a concurrent multi-worker test is run in the selected infrastructure.

## Query optimization gate

`DB::listen` showed one avoidable nonce query in every HMAC request: an `exists` check immediately followed by a unique constrained insert. The insert is already the concurrency authority and maps duplicate-key to `NONCE_REPLAY`, so the pre-check was removed. Replay/security tests remained green.

| Endpoint | Queries before | Queries after | p95 before | p95 after |
|---|---:|---:|---:|---:|
| heartbeat | 14 | 13 | 53.55 ms | 67.61 ms |
| manifest status | 17 | 16 | 52.34 ms | 60.38 ms |
| bootstrap | 12 | 11 | 42.08 ms | 54.61 ms |
| attendance batch | 17 | 16 | 54.06 ms | 69.44 ms |

The improvement is exactly 4,000 fewer queries over 4,000 requests (60,002 to 56,002; 6.7%). The after run had slower wall-clock latency (26.03 versus 34.19 RPS), demonstrating local-environment variance; no latency gain is claimed. Remaining queries implement device lookup, nonce persistence, latest-seen update and endpoint/domain reads/writes. No aggressive cache or HMAC semantic change was introduced.

## Backup/restore evidence

A checksum-protected local backup was restored on 2026-09-05 into the newly created `vending_attendance_restore_test_20260905183858` MySQL database. SHA-256 was verified before extraction. The drill validated tables/counts for machines, devices, assignments, attendance, manifest state and audit. The development source was not a restore target. See the backup runbook.

## Support UX acceptance

Device Registry exposes machine, Device UUID/serial, lifecycle/derived health, exact last heartbeat timestamp, platform/app/build/update status, server/applied config and employee manifests, pending events, last attendance and sync delays, duplicate/rejected metrics, drift, geofence readiness and last typed error. Fleet dashboard aggregates online/degraded/offline, manifest/outbox state, versions and derived alerts. No manual SQL is required for the support questions in scope.

## Manual dependencies that remain

- Hosting/DNS/TLS and centralized monitoring selection.
- OS supervisor/Task Scheduler installation and evidence.
- Android release keystore and signed artifact supplied outside Git.
- Approved HTTPS artifact storage.
- Full signed 8–24 hour soak and rollout observation window.
- Browser-assisted protected-page walkthrough in the selected pilot environment; this execution session had no connected browser, while HTTP/UI contracts remain covered by feature tests and both web builds passed.

These are explicit launch gates, not hidden development assumptions.
