# Device verification runbook

## Inspection

Open Soporte, then Verificar este equipo. Capture the available checks without
reprovisioning, clearing app data or sending attendance. A denied GPS/camera
permission or unavailable feature remains visible and does not prevent recording
the support inspection. Use only the approved demo Device for physical tests.

Review the source and observation date of each result. SERVER_SNAPSHOT is current
backend state at receipt; CLIENT_REPORTED describes Android's earlier inspection.
Offline upload can make these times differ. NOT_AVAILABLE must not be interpreted
as Correcto. A network connection alone does not prove authenticated API access.

The server retains the verification UUID, actor, app version, summary and bounded
checks. Reconnect retries use the same operation UUID; a conflicting payload returns
409 and must be investigated without generating a new UUID to conceal the conflict.

## Automated-ticket policy

Automation ships disabled. No production SLA or automatic ticket thresholds are
established by this runbook. Enabling automation requires an explicitly authorized,
versioned policy with scope, temporal validity, persistence and cooldown. DEMO
policies require explicit Machine IDs. Do not edit existing policy history.

An authorized operator can evaluate one page after configuration is reviewed:

```text
php artisan support:scan-fleet --machine=<internal-machine-id> --batch=100
```

`--machine` restricts the scan and does not change Device/Machine association.
`--batch` is clamped to the configured maximum. The command reports counts only.
It returns SUPPORT_AUTOMATION_DISABLED without generating tickets if either the
feature flag or effective rule is disabled. Invalid policy structure fails closed.
No scheduler entry is installed by the verification/automation component.

Do not target SYBI 7. It remains reserved for REAL SYBI INSIDE plus Face ID and is
excluded from both automatic-ticket generation and verification capture.

## Recovery and escalation

Repeated matching signals reuse the correlated non-terminal ticket. A recovery
entry means the previously active condition was observed as clear. An offline or
unobservable check does not constitute recovery. Recovery does not close tickets;
support personnel resolve and close through the canonical workflow.

After closure, repeated persistent signals cannot immediately recreate the ticket.
A new lifecycle requires a recovery observation and reactivation after persistence
and cooldown. Investigate the existing ticket, last check sources, policy version,
and correlation state when an expected automatic ticket is absent. Do not alter
heartbeat/health rules or manufacture observations to force creation.

## Verification commands and boundaries

Run directed tests only against the repository's isolated test database:

```text
php artisan test tests/Feature/Support/SupportAutomationTest.php tests/Feature/Support/SupportVerificationTest.php
```

The initial backend result is 14 tests and 68 assertions passed. It does not certify
physical camera/GPS behavior, process-restart persistence, concurrent MySQL workers
or fleet capacity. Those results must be recorded in their respective gates.

This component does not send push/webhook/email notifications, install a scheduler,
modify attendance, reset demo data, or alter Device provisioning and geofences.
