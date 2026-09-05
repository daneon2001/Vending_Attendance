# Vending fleet operations

## Scope

Phase 8 keeps the existing Laravel, MySQL, Inertia and device HMAC architecture. It adds a fleet-oriented read model without introducing microservices, brokers, WebSockets or a heartbeat history table. The target is approximately 1,000 vending installations, not an unbounded IoT platform.

The edge scope remains `Device -> VendingMachine`. Legacy `unit`, `branch` and `location` fields do not participate in fleet identity, authorization or health.

## Discovery baseline

Before Phase 8 the platform already had individual device identity, lifecycle, HMAC with nonce replay rejection, bootstrap, manifests with explicit ACK, latest heartbeat state, immutable attendance events and compact attendance aggregates. Missing capabilities were a global device registry, fleet dashboard, periodic edge heartbeat, derived health, release policy, rollout metadata, safe diagnostics, alert taxonomy and a reproducible 1,000-device load test.

Two hot-path risks were found and corrected:

- expired nonce cleanup ran inside every HMAC request; it now runs once per minute in bounded batches;
- persistent clock drift emitted an audit event on every heartbeat; it now emits on transition into the warning state.

Fleet pages use persisted server/applied versions. They never generate full employee manifests or hashes per row. This avoids the prior N+1 risk in the machine device registry.

## Device health

Health is derived at read time and is not persisted. Lifecycle is authoritative first:

| Device lifecycle | Fleet state |
| --- | --- |
| `PENDING` | `PENDING` (pre-operational) |
| `SUSPENDED` | `SUSPENDED` |
| `REVOKED` | `REVOKED` |
| `RETIRED` | `RETIRED` |
| `ACTIVE` | evaluated below |

An active device is:

- `OFFLINE` when no heartbeat has ever arrived, or heartbeat age is at least `VENDING_DEVICE_OFFLINE_AFTER_SECONDS`;
- `DEGRADED` when it is not offline and at least one visible reason exists: delayed heartbeat, clock drift, low storage, outbox pressure, manifest `PENDING/STALE/ERROR`, required/unsupported app version, or a recent categorized error;
- `ONLINE` otherwise.

Default thresholds are explicit in `.env.example`: degraded after 180 seconds, offline after 600 seconds, drift after 300 seconds, outbox pressure at 100 events, low storage below 256 MB and recent-error window of 30 minutes. The UI returns and displays these thresholds; there are no hidden timers.

## Latest-state diagnostics

Heartbeat stores only the current operational snapshot:

- app version and build;
- platform version and release channel;
- network state;
- battery when present;
- free storage;
- pending outbox event count;
- device time and drift;
- latest sanitized error category/code/time.

Allowed categories are `NETWORK`, `AUTH`, `CLOCK`, `MANIFEST`, `SQLITE`, `GPS`, `GEOFENCE`, `ATTENDANCE`, and `UPDATE`. Stack traces, secrets, database contents and biometric material are not accepted. Battery remains nullable because fixed terminals may not expose one.

## Dashboard and registry

`/vending` is the operational landing page and reports machine/device health, geofence readiness, assignment scope, manifest lag, attendance volume, edge backlog, rejections, app version distribution, the latest SYBI run and a bounded derived-alert list.

`/vending/devices` is the global registry. It searches machine code, device UUID or serial and filters lifecycle, machine, platform, app version, channel, sync state, heartbeat freshness, drift, backlog and geofence readiness. The payload is an explicit allow-list and never serializes device credentials, metadata or provisioning tokens.

Alerts are derived from current state and are not duplicated into an ever-growing alerts table. Initial severities are visible rules, not automated paging policy. Email/SMS integrations are deliberately out of scope.

The administrator flow is:

`Operation dashboard -> Machines -> Devices -> Assignments -> Attendance -> Sync -> Releases -> Alerts`

Existing machine, assignment and attendance screens remain in place; Phase 8 adds fleet navigation instead of replacing legacy dashboards.

## Retention and scheduling

- Heartbeats: latest state only; no raw history.
- Attendance: immutable business evidence; no automatic cleanup.
- Device attendance metrics: one aggregate row per device.
- Nonces: short-lived anti-replay records, pruned every minute with at most one configured batch per run. Running Laravel Scheduler is therefore an operational requirement.
- Audit: existing severity-based cleanup remains daily; successful heartbeats and manifest polls are not audited.
- SYBI runs: existing configurable interval retained. SYBI contract and promotion semantics are unchanged.

Queues are not used for heartbeat, bootstrap or manifests. Possible future queue candidates are a large rollout reconciliation or SYBI work proven to exceed request/scheduler budgets.

## Initial device API rate limits

All operational limits are keyed by authenticated device identity; they do not share a single global 1,000-device bucket.

| Endpoint | Default per minute | Rationale |
| --- | ---: | --- |
| Provision | 5/IP | high-entropy, expiring, single-use token; deliberately restrictive |
| Bootstrap | 30/device | startup/recovery bursts, not polling |
| Heartbeat | 120/device | large safety margin over one request/minute |
| Manifest status | 60/device | permits recovery polling while discouraging tight loops |
| Manifest download | 30/device | snapshots only when version changes |
| Manifest ACK | 60/device | retries are idempotent |
| Attendance single | 120/device | interactive fallback |
| Attendance batch | 30/device, max 100 events | offline outbox recovery |

At a 60-second heartbeat interval, 1,000 online devices produce about 16.7 heartbeat requests/second on average. The edge client applies ±20% periodic jitter to reduce synchronized bursts. Fleet startup/reconnect bursts and actual MySQL results are documented separately; rate-limit allowance is not itself a capacity claim.

## Biometrics

Biometric Manifest remains `supported=false`. No hardware SDK, matching, template, sample or production biometric path is introduced by fleet hardening.
