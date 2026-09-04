# Current system map

## Verified flow

```text
Fortia database / Fortia mock
  -> FortiaEmployeeService / FortiaMockSyncService
  -> employees (Employee)
  -> companies + locations (CatalogAlignmentService)
  -> employee.base_location_id + employee_allowed_locations
  -> clocks / devices
  -> EnrolmentController
  -> employee_fingerprints / employee_face_templates
  -> EmployeeTemplatesController incremental template feed
  -> on-prem checker (external client, not present in this repository)
  -> OnPremAttendanceController
  -> attendances_raw + attendance_logs
  -> attendance_dailies / attendance_changes
  -> audit_logs / enrolment_audits
```

## Fortia to employee projection

- `App\Services\Fortia\FortiaEmployeeService` reads the configured `fortia` connection and `fortia_employees` table, or delegates to `App\Services\FortiaMock\FortiaMockSyncService` in mock mode.
- Identity/upsert key: `employees.fortia_employee_id`, sourced from `employee_id` or `fortia_employee_id`.
- Incremental cursor: `employee_sync_states.last_synced_at`; remote rows use `updated_at >= last_synced_at`.
- State and errors: `last_cursor`, `last_success_at`, `last_sync_status`, `last_error`, and `last_counts`.
- `App\Services\Fortia\CatalogAlignmentService` projects employee catalog values into `companies` and `locations` using Fortia/external keys.

## Employee and branch/unit scope

- `App\Models\Employee` has a primary `base_location_id`, `check_scope`, `can_check_all_branches`, and an N:M relation to `locations` through `employee_allowed_locations`.
- Scope values are `HOME_ONLY`, `ANY_BRANCH`, and `SELECTED_BRANCHES`.
- `App\Services\OnPremise\ClockUnitResolver` resolves enrolment against `clock.location_id`, request `unit_id`, or `employee.base_location_id`.
- `App\Services\Biometrics\EmployeeScopeSyncService` emits `employee_scope_deletions` and scoped biometric tombstones when branch access is lost.

## Enrolment and biometrics

- `App\Http\Controllers\Api\EnrolmentController` receives completed enrolment, resolves employee/clock/unit, validates active status, and writes `employee_fingerprints` plus `enrolment_audits`.
- Fingerprint payloads use Base64 and an allowed `template_format`; configured formats include `DPFP_PROPRIETARY` and `zkteco-v1`.
- Face data has two representations: FACE rows in `employee_fingerprints`, and `employee_face_templates.embedding_encrypted` with model/version/hash metadata.
- `App\Http\Controllers\Api\FaceIdTemplateSyncController` accepts a Windows-admin-originated face projection (`winadmin-faceid`). The Windows client source is not in this repository.
- `App\Http\Controllers\Api\EmployeeTemplatesController` returns incremental data, SHA-256 hashes, a timestamp version and tombstones, optionally scoped by location.

## Attendance and device sync

- `App\Http\Middleware\VerifyDeviceHmac` authenticates on-prem devices with per-device shared secrets, timestamp tolerance and nonce replay protection in `device_nonces`.
- `App\Http\Controllers\Api\OnPrem\OnPremAttendanceController` accepts batches up to the configured maximum (default 500).
- Each event carries `local_event_id` (UUID). `attendances_raw` enforces uniqueness on `(device_serial, local_event_id)`.
- Per-event acknowledgements are `STORED`, `DUPLICATE`, or `REJECTED`, with `remote_id` when stored/already known.
- Accepted events are projected into `attendance_logs`; current location/unit IDs are embedded in both raw and central records.
- `App\Http\Controllers\Api\OnPrem\OnPremHeartbeatController` updates `devices` and `clocks` and returns server time plus the next heartbeat interval.

## Audit and operations

- `App\Services\Audit\AuditLogger` and observers populate `audit_logs`; biometric and attendance actions have dedicated audit paths.
- `audit:cleanup` is the only scheduled command and runs daily with overlap protection.
- No application Job class or dispatch call was found. Queue/cache/session defaults are database-backed; Redis connections are configurable but no verified Redis runtime usage was found.
- `App\Services\Fortia\FortiaAuthService` and `FortiaAttendanceService` are placeholders; outbound attendance delivery to Fortia is not implemented.
