# Device verification and support automation

## Scope and source of truth

`SupportVerificationService::capture` records one idempotent inspection in the
canonical support domain. Device requests derive Device and Machine from the
authenticated actor; human requests need `support.verify` and a validated Device.
An arbitrary client machine field, another Device ID, unknown check or diagnostic
payload is rejected. SYBI 7 is reserved and cannot receive verification writes.

Device identity, HMAC, heartbeat, manifests, attendance and Fleet health algorithms
are unchanged. A verification does not trigger attendance, reprovisioning or a
manifest download. No claim of remote hardware attestation is made.

## Capture contract

Required fields: `client_operation_uuid`, `started_at`, `completed_at`, `checks`.
Optional: `app_version`, `app_build_number`; `device_id` selects the inspected
Device for an authorized human. Device context remains authoritative for APK calls.

Checks have `code`, `result`, optional `observed_at`, and optional allowlisted
`details`. Results are `PASS`, `WARNING`, `FAIL`, `NOT_AVAILABLE`. The UI translates
these as Correcto, Advertencia, Requiere atención and No disponible.

Capture timestamps describe the inspection, not upload time. Sessions can arrive
after offline storage. Observations must fall inside the session; capture duration
is limited to one hour and completion cannot exceed the server clock by five minutes.
Operation receipts preserve the same logical result across retries. Reusing an
operation UUID with different canonical content returns 409.

| Source | Checks | Meaning |
| --- | --- | --- |
| SERVER_SNAPSHOT | API_RECEIPT | This authenticated capture reached the backend at the recorded server observation time. |
| SERVER_SNAPSHOT | DEVICE_ACTIVE, MACHINE_ASSOCIATION | Current backend identity/lifecycle and authorized association. |
| SERVER_SNAPSHOT | HEARTBEAT | Last received heartbeat age; never received is NOT_AVAILABLE. |
| SERVER_SNAPSHOT | APP_VERSION | Existing release-policy evaluation; absent policy/version is NOT_AVAILABLE. |
| SERVER_SNAPSHOT | CONFIGURATION_MANIFEST, EMPLOYEE_MANIFEST | Persisted versions and ACK state through the lightweight summary service. |
| SERVER_SNAPSHOT | OUTBOX, CLOCK_DRIFT, STORAGE | Existing reported fields, only when fresh and non-null. |
| CLIENT_REPORTED | GPS_PERMISSION, GPS_AVAILABILITY, CAMERA_PERMISSION, CAMERA_AVAILABILITY | Android observations; the backend stores their origin rather than claiming hardware proof. |
| CLIENT_REPORTED | NETWORK, API_REACHABILITY | Client connectivity/API observations at capture time, distinct from server receipt. |
| CLIENT_REPORTED | LOCAL_CONFIGURATION, LOCAL_EMPLOYEES, LOCAL_OUTBOX | Local SQLite/manifest/outbox inspection, not backend acknowledgement. |

Client details allow only permission/state enums, availability booleans, version,
pending count, and a bounded error-code enum. No coordinates, EXIF, system dumps,
arbitrary messages, private paths, identifiers or credentials are accepted there.
Absent or stale data never becomes PASS. Storage remains NOT_AVAILABLE when the
existing heartbeat field is null; the installed Capacitor Device plugin does not
expose free disk space. API receipt does not prove earlier connectivity while offline.

Verification rows preserve actor, Device, Machine, app/build, capture interval,
summary, check sources and observation timestamps. Safe audit metadata contains
identity references and summary only. A summary prioritizes FAIL, then WARNING,
then NOT_AVAILABLE; PASS means every recorded check passed.

## Bounded automation

`SupportAutomationService::scan` reads at most the configured batch (default 100,
maximum 250) and checks a cooperative duration budget (default 15 seconds) between
Devices. It maintains a persistent Device-ID cursor, uses keyset selection and
eager-loaded Machine/manifest state, and resolves release policies once per page.
The budget does not forcibly interrupt a running database transaction.

No work is attached to heartbeat, and no scheduler entry is installed by this
component. Automation is disabled unless `support.automation.enabled` is true and
an active, temporally effective policy explicitly enables applicable rules.
There is no installed production policy. A DEMO policy must list Machine IDs.
Only operational Machines are considered, and SYBI 7 is always excluded.

Policy payload `automation` contains `enabled` and `rules`. Every rule specifies
`key`, `enabled`, `source`, `persistence_seconds`, `cooldown_seconds`, `category`,
`severity`, `priority`, and `machine_ids`. The complete shape is validated; severity
and priority are independent. Rules are DEVICE_OFFLINE_PERSISTENT, MANIFEST_ERROR,
OUTBOX_HIGH, STORAGE_LOW, CLOCK_DRIFT_HIGH, UNSUPPORTED_VERSION, and
VERIFICATION_CRITICAL. A VERIFICATION source must use VERIFICATION_CRITICAL with
an explicit allowlisted `check_codes` array.

Fleet health evaluation and thresholds are reused without modification. The
dashboard's bounded alert list is never an automation input. No full employee
manifest is built by the worker.

## Correlation, persistence and recovery

The correlation key is SHA-256 of Machine UUID, Device UUID and rule key. A unique
constraint and transactional row lock serialize lifecycle decisions. Policy changes
do not manufacture a second correlation for an already open ticket. New tickets use
the same `SupportTicketService` as web/mobile/external callers and record policy
version and rule in an append-only timeline event.

An active signal starts persistence timing; UNKNOWN interrupts continuity. While a
non-terminal correlated ticket exists, it is reused. CLOSED and CANCELLED stay
immutable; a new ticket requires observed recovery after the previous creation,
subsequent activation, persistence, and expiration of the configured cooldown.

Missing fresh telemetry is UNKNOWN, including when Fleet returns early for an
offline/suspended/retired Device. It cannot generate a false recovery of outbox,
storage or clock conditions. For verification rules, any configured check FAIL
activates; every configured check must be present and PASS to recover. Missing,
WARNING or NOT_AVAILABLE checks yield UNKNOWN. Older sessions cannot overwrite
newer correlation observations.

Actual recovery appends `support.ticket.recovery` once and leaves the ticket open.
Equivalent observations create neither timeline entries nor audit polling noise.
Automatic close is not implemented. Notification/SLA consumers can subscribe to
the canonical timeline later; this component delivers no external messages.

## Validation evidence and limits

Directed command: `php artisan test tests/Feature/Support/SupportAutomationTest.php tests/Feature/Support/SupportVerificationTest.php`.
Initial result: 14 tests, 68 assertions passed on isolated SQLite. Covers a 100-signal
storm, continuity, UNKNOWN, recovery, terminal history, cursor/scope, policy validity,
idempotency/conflict, permission/Device denial and safe diagnostic validation.

The storm test is sequential and deterministic; it does not prove simultaneous
MySQL lock behavior or production capacity for 1,000 Devices. Physical Android
checks, MySQL concurrency and final EXPLAIN review remain separate gates. No live
scan or operational verification was executed to produce these unit/feature results.
