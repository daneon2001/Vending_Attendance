# Controlled pilot runbook

## Purpose and owners

Use this runbook for the non-biometric 5–20 device pilot. Name one release owner, one backend operator, one support lead and an escalation contact before activation.

## Entry gates

1. Deploy only an immutable Git checkpoint approved for the pilot.
2. Configure the checklist in `docs/architecture/pilot-environment.md`; keep real values outside Git.
3. Run `composer install --no-dev --classmap-authoritative` and `npm ci && npm run build` in the deployment pipeline.
4. Run `php artisan migrate --force`, then `php artisan migrate:status`.
5. Run `php artisan optimize` and `php artisan vending:pilot-preflight`.
6. Verify HTTPS from a device without ADB or LAN-only routing.
7. Verify one supervised scheduler and, when queue jobs are enabled, one supervised `php artisan queue:work --sleep=3 --tries=3 --timeout=90`.
8. Verify the signed APK SHA-256 against release metadata before distribution.
9. Complete a backup and restore drill using the backup runbook.
10. Confirm Biometrics is unsupported/disabled and SYBI/Fortia behavior is unchanged.

## Scheduler verification

Run `php artisan schedule:list`. Expected jobs are `device-nonces:prune` every minute and `audit:cleanup --optimize` daily; SYBI appears only when explicitly enabled. Inspect supervisor/Task Scheduler logs for two consecutive minute invocations. Then run `php artisan device-nonces:prune` twice in staging: both executions must succeed and the second must be harmless. Never rely on a support engineer launching the command manually each day.

## Activation

1. Create or verify 5 pilot vending machines, coordinates and active geofences.
2. Assign only pilot employees and verify manifest preview/count.
3. Assign each device to channel `PILOT` and an explicit pilot group.
4. Provision one device at a time using the provisioning runbook.
5. Wait for configuration and employee manifests to show `SYNCED` before accepting attendance.
6. Start at 5 devices for one business day. Expand to 10, then at most 20 only if there are no unresolved HIGH alerts, attendance loss, credential incidents or rollback conditions.

## Daily checks

- Fleet dashboard: offline/degraded, manifest pending/stale, outbox, clock, storage and app version.
- Device Registry: last heartbeat and timestamp, applied/server versions, last error and last attendance.
- API monitoring: 5xx, 429 and p95 latency.
- Scheduler, queue failure count, MySQL capacity and backup freshness.
- Record only operational counts; do not copy employee manifests or credentials into tickets.

## Exit/stop conditions

Stop expansion for repeated 5xx, duplicate UUID conflicts, persistent outbox growth, missing attendance evidence, failed restore, invalid TLS, unsigned/cleartext build, unexplained credential rejection or a HIGH alert not resolved within the agreed support window. Follow the incident or release runbook. Existing offline devices may continue collecting evidence; do not clear app data.
