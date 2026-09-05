# Pilot incident runbook

## Severity

- **HIGH:** attendance evidence loss/corruption, credential exposure, invalid TLS, widespread authentication failure, database unavailable, restore failure, or many devices offline.
- **MEDIUM:** one device offline/degraded, manifest stale, outbox pressure, clock/storage warning, geofence review or failed rollout on a limited cohort.
- **LOW:** transient self-recovered warning with no attendance loss.

## First response

1. Record UTC start, reporter, affected machine/device UUIDs and fleet counts. Do not copy secrets, signatures, manifests or full attendance payloads.
2. Freeze provisioning and rollout changes if identity/release integrity may be involved.
3. Check HTTPS, application health/5xx/429, scheduler/queue, MySQL connections/locks, backup freshness and Fleet alerts.
4. Preserve device and server evidence. Do not clear SQLite, rotate credentials, delete audit records or run destructive migrations during triage.
5. Follow offline recovery, device replacement or release rollback as applicable.

## Common decisions

| Signal | Action |
|---|---|
| `DEVICE_OFFLINE` | Check network/DNS/TLS and last heartbeat; allow local attendance to queue. |
| `CLOCK_DRIFT` | Correct OS time policy; never rewrite captured attendance time. |
| `OUTBOX_PRESSURE` | Restore transport, observe drain, retain rejected rows. |
| `MANIFEST_STALE/ERROR` | Compare server/applied versions and last ACK; trigger foreground sync after transport is healthy. |
| `LOW_STORAGE` | Preserve app data; remediate unrelated storage only. |
| `APP_UPDATE_REQUIRED` | Verify release policy and checksum; follow release runbook. |
| `GEOFENCE_REVIEW` | Stop geofence-dependent acceptance until coordinates/version are administratively verified. |

## Recovery verification and closure

Confirm API availability, no new 5xx, scheduler current, queue healthy, MySQL stable, alerts resolved, outbox zero or declining, manifests synced, new attendance stored, historical evidence present and audit volume normal. Document root cause, UTC timeline, impact count, recovery, residual risk and follow-up owner. Do not declare recovery only because an alert disappeared.
