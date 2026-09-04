# Phase 0 inherited baseline audit

## Stack

- Runtime detected: PHP 8.4.15, Node 20.20.2, npm 11.12.1.
- Locked backend: Laravel 11.47.0, Inertia Laravel 2.0.14, Sanctum 4.2.1, Breeze 2.3.8, Maatwebsite Excel 3.1.56, Ziggy 2.6.0.
- Locked frontend: Vue 3.5.25, Inertia Vue 3 2.2.21, Vite 6.4.1, Axios 1.13.2, Tailwind CSS 3.4.18, Chart.js 4.5.1, Tom Select 2.6.1.
- JavaScript is used (`resources/js/app.js`, Vue SFCs); no TypeScript application source, Pinia or Vuex was found. Playwright configuration is TypeScript.
- Database connections declared: SQLite, MySQL/MariaDB, PostgreSQL, SQL Server, Fortia MySQL and Fortia mock MySQL. Actual local connection is unknown because `.env` is absent.
- Cache, queue and session default to database. Redis stores/connections are available in framework configuration, but no active deployment selection was verifiable.
- Auth: Laravel session/Breeze for web, Sanctum bearer tokens for APIs, custom role/permission tables, static device token on legacy endpoints, and per-device HMAC on on-prem endpoints.

## Reuse classification

| Area | Class | Evidence / boundary |
|---|---|---|
| 1. Laravel base | REUSE | Laravel 11 application structure and service boundaries are usable. |
| 2. Vue 3 | REUSE | Vue 3.5 UI foundation can remain for the web administration surface. |
| 3. Inertia | REUSE | Appropriate for the existing web back office; not the mobile runtime. |
| 4. Authentication | ADAPT | Reuse web/Sanctum concepts; replace static shared device-token paths with lifecycle-managed device identity. |
| 5. Users/roles | REUSE | Custom roles, permissions and strict middleware are separable from branch domain. |
| 6. Employees | ADAPT | Preserve Fortia projection and identity; detach operational eligibility from fixed branch fields. |
| 7. Fortia Sync | ADAPT | Incremental projection, status logging and upsert are useful; source contract and cursor robustness need formalization. |
| 8. Branches/locations | REMOVE_FROM_NEW_PROJECT | Keep only as legacy compatibility/reference; SYBI vending machines become the operational asset. |
| 9. Employee-branch relation | REDESIGN | Replace `base_location_id`/branch scope with temporal EmployeeMachineAssignment. |
| 10. Biometrics | REDESIGN | Server registry/audit ideas survive, but vendor formats and mobile SDK compatibility are unproven. |
| 11. Enrolment | ADAPT | Reuse validation/audit/idempotency concepts; redesign capture and machine assignment for mobile. |
| 12. Biometric templates | ADAPT | Reuse hashes, metadata, versions and tombstones; storage encryption/interoperability require redesign. |
| 13. Attendance/checks | ADAPT | Reuse UUID, raw event and central projection; replace unit/clock coupling with machine/device identities. |
| 14. Audit | REUSE | Audit logger, forensic fields, observers and cleanup controls are strong baseline components. |
| 15. SQLite/local storage | UNKNOWN | Only Laravel's optional server SQLite connection exists; no on-device SQLite schema/client is present. |
| 16. Offline sync | REDESIGN | Server ACK/idempotency exists; no client outbox/inbox or durable retry implementation is present. |
| 17. Heartbeat | ADAPT | Reuse health/status/interval contract; add config/manifest versions and machine identity. |
| 18. Device management | ADAPT | Device registry/HMAC/nonce are reusable concepts; add provisioning, rotation, revocation and OTA lifecycle. |
| 19. Dashboard | ADAPT | UI/service patterns reusable; current metrics are company/location/recruitment centric. |
| 20. Reports | ADAPT | Export infrastructure reusable; report semantics are branch/employee and must change. |
| 21. APIs | ADAPT | Preserve versioned contracts and middleware patterns; remove legacy aliases and branch-centric fields over time. |
| 22. Jobs/queues | REDESIGN | Queue tables/config exist, but no Job classes or dispatch usage were found. |
| 23. Scheduler | ADAPT | Scheduler foundation exists; only audit cleanup is scheduled. |
| 24. Runtime configuration | ADAPT | Environment-based config is usable; defaults include placeholders and device secrets need managed provisioning. |
| 25. Logging | REUSE | Stack/single/daily/stderr/syslog/Slack channels plus structured domain logging are available. |

## Fortia audit

- Entry points: `fortia:sync-employees`, `fortia:sync-operational-catalogs`, `fortia:import-clocks`, mock commands, diagnostic/audit commands, and `POST /api/employees/sync-fortia`.
- Core files: `FortiaEmployeeService`, `FortiaMockSyncService`, `CatalogAlignmentService`, `ClockCatalogImportService`, `FortiaAuthService`, and `FortiaAttendanceService`.
- Source tables/state: configured remote `fortia_employees`; local `employees`, `employee_sync_states`, `employee_status_changes`, `companies`, `locations`, and `employee_allowed_locations`.
- Employee key/number: numeric `employee_id` or `fortia_employee_id`, stored as unique `employees.fortia_employee_id`; API paths also accept `employee_number` as a resolution alias.
- Create/update: create when the Fortia key is absent locally; otherwise fill and save only dirty fields. Status changes are recorded when the table exists.
- Deactivation: remote `status` is projected; no hard delete was found. Employment dates are imported by a later migration/import path.
- Incremental frequency: no scheduler entry for Fortia sync was found; invocation frequency is external/manual and therefore UNKNOWN.
- Success/failure: state row is marked success with counts/cursor or failed with error; structured logs are emitted.
- Idempotency: upsert by Fortia employee ID makes repeated records stable, but the `updated_at >= cursor` strategy and per-row operations are not a transactional, immutable feed contract.
- Outbound Fortia API auth and attendance sending are placeholders (`TODO`), so they are not reusable implementations.
- Reusable path: Fortia source access, mapping, status projection, sync-state telemetry and employee upsert can feed a future Employee Projection. Branch-scope mapping must not directly create EmployeeMachineAssignment until ownership/rules are defined.

## Biometric audit

- Enrolment endpoint: `POST /api/FortiaPrimeApi.Opensync/api/v2/enrolments/complete` to `EnrolmentController`.
- Fingerprints are stored in `employee_fingerprints.template_b64`, with vendor ID, vendor/source, format, type, device serial, state and timestamps. No fingerprint image column was found.
- Allowed fingerprint formats: `DPFP_PROPRIETARY` and `zkteco-v1`; legacy output defaults to DigitalPersona. Algorithm implementation/SDK binaries are absent.
- Face rows may use Base64 in `employee_fingerprints`; a separate face registry stores `embedding_encrypted`, template hash, quality, model/version and source metadata in `employee_face_templates`. Raw face image storage was not found.
- Sync uses timestamp versions, SHA-256 payload hashes and tombstones (`employee_template_deletions`, `employee_scope_deletions`). Deletion endpoints record tombstones before deleting active records.
- Identification/verification happens in an external checker; this repository distributes templates and accepts enrolment/check results, but contains no matching algorithm.
- No VB.NET, C#, Python, Java/Kotlin, Swift or Objective-C source was found. Comments identify a Python on-prem app and `winadmin-faceid`, but their implementations are external.
- Classification: REDESIGN for the future mobile capture/matching component; ADAPT for registry, audit, hash, version and tombstone server contracts.
- HIGH risk: no repository evidence proves DigitalPersona/ZKTeco template compatibility, licensing, hardware access or biometric matching on Android or iOS.

## Offline and sync audit

- Present: server-side HMAC auth, timestamp tolerance, nonce replay defense, batch acceptance, event UUID, unique idempotency key, per-event ACK, template delta by `since`, timestamp version, tombstones and heartbeat.
- Sync success for attendance means a 200 batch response whose item is `STORED` or `DUPLICATE`; rejected items carry a reason. This is an application ACK from Laravel.
- Duplicate handling: lookup plus database unique constraint on `(device_serial, local_event_id)`; central `attendance_logs` also checks `local_id` and device.
- Network loss: no client code is present, so durable queueing/retry behavior cannot be verified.
- Versioning: biometric feed has a timestamp-derived version; device configuration, employee manifest and machine manifest versions do not exist.
- Heartbeat reports pending count and device/app health, and server returns next interval. It does not acknowledge a configuration version.
- Missing: local employee/template caches, check-events SQLite schema, outbox/inbox, retry policy, manifest protocol and explicit client checkpoint persistence.

## Main branch couplings

- Models/tables: `Employee.base_location_id`, `Employee.allowedLocations()`, `locations`, `employee_allowed_locations`, `Clock.location_id`, `Device.unit_id`, `AttendanceRaw.unit_id`, `AttendanceRecord.location_id`, `employee_scope_deletions.scope_location_id`, and `employee_template_deletions.scope_location_id`.
- Services/controllers: `FortiaEmployeeService`, `EmployeeScopeSyncService`, `ClockUnitResolver`, `AllowedBiometricCandidates`, `EmployeeTemplatesController`, `EnrolmentController`, `OnPremAttendanceController`, `OnPremHeartbeatController`, `ClockController`, dashboard/report services and employee catalog services.
- UI: employee catalog, clocks, units, attendance, cards, dashboards and related filters expose unit/location concepts.

## Repository hygiene observations

- The inherited repository tracks `respaldo_asistencias_dev.sql` (~249 MB), multiple `.tmp_*.php` scripts, and `public/phpinfo.php`. They were preserved, not executed or removed.
- No private-key, PEM certificate or AWS access-key signature was detected by the limited pattern scan. This is not a full secret-history audit.
- `.gitignore` was extended in the new repository for `.env.*` (while keeping `.env.example`), generic logs, local database files, private-key/container formats and mobile build artifacts.

## Runtime validation

- Laravel executable: YES
- Frontend build: PASS
- Local DB: PASS
- Migrations: PASS
- Tests: FAIL (281 passed, 1 inherited fixture failure)
- External integrations isolated: YES for Fortia/SYBI and production systems; login retains an expected public Bunny Fonts browser dependency.

Phase 0Q used PHP 8.4.15, Composer 2.9.2, Laravel 11.47.0, Node 20.20.2, npm 11.12.1, and an isolated MySQL 8.4.3 instance on loopback port 3307. All 76 migrations ran on `vending_attendance_dev`. The remaining test failure is `Tests\Feature\Console\OnPremDiagnosticsCommandTest`: its fixture omits the `clocks` table now queried by `OnPremHeartbeatController`; the same diagnostic command passes all 12 checks against the migrated MySQL baseline.
