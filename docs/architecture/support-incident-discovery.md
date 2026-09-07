# Support incident discovery — Phase 13

## Baseline / gate

Discovery completed before implementation. Branch `phase/13-support-incidents`, HEAD
`da5e6a2d2edc35d7b0fe9fc7d430f62543e55d2a`, tag `vending-phase-12-ux-pass`, clean
working tree. Local `vending:demo-preflight`: PASS (ONLINE, configuration 2/2,
employees 5/5, pending 0, HIGH 0). This is a start observation, not final certification.

## EXISTING

Laravel 11, custom Role/Permission pivots (not Spatie), Vue/Inertia, Ionic Vue,
Capacitor 7. Device and VendingMachine have operational identity; this deployment
does not implement the SaaS company scope described in the sibling repository.
Inspected Models, Enums, Services, Console, controllers/requests/middleware,
bootstrap/config/migrations/routes, web/mobile source/tests and architecture docs.
Semantic searches covered support, incidents, tickets, maintenance, attachments,
evidence, uploads, notifications, verification, SLA, comments, timeline and outbox.
No existing canonical support, photo attachment, SLA or delivery domain was found.
Policies, Events, Listeners and Notifications directories were absent.

## REUSABLE

- Device identity, `VerifyDeviceHmac`, nonce/timestamp protection: unchanged.
- `DeviceFleetHealthService::evaluate`, `DeviceManifestStatusService::summary`,
  `MobileVersionPolicyService::policyMap`: real bounded diagnostic inputs.
- Existing `GeofenceValidationService` and mobile `LocationService`: no new geometry.
- `AuditLogger`: safe operation metadata only; never binary, GPS or credentials.
- Laravel Filesystem, GD/fileinfo/exif; JPEG/PNG/WebP supported locally.
- Sanctum PersonalAccessToken storage/hash, but not its human authentication guard.
- SQLite plugin, Secure Storage, connectivity and lower-level HMAC functions.
- Existing UI components/tokens/navigation permission convention.

## EXTEND

Dedicated support routes, permission module, private disk, secondary navigation,
mobile service composition, and tests. Add only official Camera and Filesystem
plugins (explicitly authorized), compatible with installed Capacitor 7.

## CREATE

One SupportTicket domain, append-only timeline, operation receipts, private evidence,
verification sessions, correlation state, versioned support policy, service identity,
ordered feed cursor and standard database notifications. See ADR-VEND-019 and
support-ticket-domain.md for the schema and interfaces.

## DO_NOT_REUSE

- Attendance outbox joins attendance_events and is not generic. Do not alter it.
- `getAttendanceContext()` requires manifests/geofence: support must work without them.
- Attendance evidence classes are classification, not photographic storage.
- Fleet dashboard loads all devices and truncates alerts to 100: not an automation queue.
- CSV/XLSX imports are transient and are not private attachment storage.
- ExternalEmployeeTokenMiddleware has no scoped service identity: not suitable.
- Audit cleanup/best-effort logging cannot be the canonical ticket event stream.

## CONFLICTS

A service Model with HasApiTokens would be accepted by the default Sanctum guard
on unrelated legacy APIs. The new principal must NOT use HasApiTokens or become
Auth::user. Its dedicated middleware validates token type, scope and machine allowlist.
Filesystem and DB cannot commit atomically; evidence uses reservations and immutable
confirmed objects. Support gets its own SQLite connection to avoid changing protected
attendance transaction handling. Local data is a projection, not a second server domain.

## SECURITY_CONCERNS

Object authorization is common across adapters; device machine comes from authenticated
Device, integration machine from its allowlist, operator tickets from reporter ownership.
Files stay private, with generated keys, MIME/byte/pixel bounds, sanitization, hashes,
authorized streams and no public URLs. Replays use stable operation UUID and canonical
fingerprint. No token issuance, passwords or secrets in Git/output. Historical FKs restrict
deletion. No broad role sync or pilot-user seeding. Threat tests accompany evidence gate.

## PERFORMANCE_CONCERNS

Bounded indexed lists/feeds; no binary in index. Fleet worker is separate from heartbeat,
cursor-bounded and uses eager-loaded health inputs. Missing signal under OFFLINE means
UNKNOWN, not recovery. A locked sequence serializes event publication order so a consumer
cannot miss a late-committing smaller auto-increment ID. Measure contention; not a claim
of certified throughput for 1,000 devices. MySQL concurrency and EXPLAIN remain gates.

## MIGRATION_IMPACT

Additive support tables and support-only permissions. No existing operational schema,
Device/attendance/manifests/SYBI/Fortia changes. Review table/index design before applying.
Existing data is not reset. Support automation/SLA default disabled pending explicit
versioned DEMO policy. See support-ticket-domain.md.

## OPEN_DECISIONS

Product SLA, evidence retention and production category ownership are not supplied.
Remote push/webhook delivery and S3 configuration are deferred, not certified. Local
in-app feed and external incremental polling are required now. Free disk cannot be
measured by installed Device plugin: report NOT_AVAILABLE. REAL_SYBI_INSIDE_TEST is
DEFERRED_SECOND_DEVICE; SYBI 7 stays reserved. Physical support tests use only the demo pair.
