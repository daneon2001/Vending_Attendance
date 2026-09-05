# Vending API v1

Base prefix: `/api/v1`. Human administration/domain endpoints use `auth:sanctum` plus the strict `vending_machines.view` permission. Device operational endpoints use the distinct `device.hmac:vending` boundary. Provisioning is the only pre-device-credential route and requires a short-lived, single-use machine token.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/vending-machines/{uuid}` | Machine configuration and active geofence |
| GET | `/vending-machines/{uuid}/geofence` | Current effective ACTIVE geofence |
| GET | `/vending-machines/{uuid}/assignments` | Assignment history for a machine |
| GET | `/employees/{employee}/vending-machines` | Effective assignments and machines for an employee |
| POST | `/geofence/validate` | Spatial validation only |
| POST | `/device/provision` | Consume one provisioning token and issue one Device credential |
| GET | `/device/bootstrap` | Device-bound initial machine/geofence snapshot |
| POST | `/device/heartbeat` | Update bounded latest-state telemetry and receive the next heartbeat interval |
| GET | `/device/manifests/status` | Compare server and explicitly applied versions |
| GET | `/device/manifests/configuration` | Download current machine configuration snapshot |
| GET | `/device/manifests/employees` | Download effective assignment-scoped employee snapshot |
| POST | `/device/manifests/ack` | Confirm or reject local manifest application |

Machine route binding uses UUID. Employee binding retains inherited numeric/Fortia behavior.

## Geofence validation

Request:

```json
{
  "machine_uuid": "uuid",
  "latitude": 19.4327,
  "longitude": -99.1332,
  "accuracy": 6,
  "captured_at": "2026-09-04T12:00:00-06:00"
}
```

Response fields include `machine_id`, `geofence_version`, `distance_m`, `effective_distance_m`, `radius_m`, `accuracy_m`, `tolerance_m`, `minimum_acceptable_accuracy_m`, `result`, `reason`, and `captured_at`.

The endpoint never creates attendance. A missing effective ACTIVE geofence returns HTTP 422 with code `ACTIVE_GEOFENCE_NOT_FOUND`; validation errors use Laravel's standard HTTP 422 response.

## Device manifest authentication

All manifest examples require `X-Device-Id`, `X-Timestamp`, `X-Nonce`, and `X-Signature`. The HMAC canonical target includes the query string. Examples omit header values because credentials and signatures must never be copied into documentation.

Rate limits are configurable and default to 60 status requests/minute, 30 combined manifest downloads/minute, and 60 ACKs/minute per Device UUID.

## GET `/device/manifests/status`

Request body: none.

Response:

```json
{
  "configuration": {
    "server_version": 12,
    "server_hash": "sha256-hex",
    "applied_version": 12,
    "applied_hash": "sha256-hex",
    "changed": false,
    "state": "SYNCED"
  },
  "employees": {
    "server_version": 27,
    "server_hash": "sha256-hex",
    "applied_version": 25,
    "applied_hash": "sha256-hex",
    "changed": true,
    "state": "PENDING"
  },
  "biometrics": {
    "server_version": null,
    "applied_version": null,
    "changed": false,
    "supported": false
  },
  "sync_state": "PENDING",
  "server_time": "2026-09-04T18:30:00Z"
}
```

## GET `/device/manifests/configuration`

Request body/query: none. Machine identity is derived from the authenticated Device.

Response:

```json
{
  "manifest_type": "MACHINE_CONFIGURATION",
  "manifest_version": 12,
  "device": {"uuid": "device-uuid"},
  "machine": {
    "uuid": "machine-uuid",
    "machine_code": "VM-001",
    "status": "ACTIVE",
    "timezone": "America/Mexico_City"
  },
  "geofence": {
    "uuid": "geofence-uuid",
    "version": 4,
    "type": "CIRCLE",
    "latitude": 19.4326,
    "longitude": -99.1332,
    "radius_m": 50,
    "minimum_acceptable_accuracy_m": 30,
    "tolerance_m": 10
  },
  "manifest_hash": "sha256-hex",
  "generated_at": "2026-09-04T18:30:00Z",
  "server_time": "2026-09-04T18:30:00Z"
}
```

## GET `/device/manifests/employees`

Optional signed query: `known_version=27`. When it matches, HTTP 200 returns type, version, hash, `changed: false`, and server time without the employee array. Omitting it or sending a different version returns a full desired-state snapshot:

```json
{
  "manifest_type": "EMPLOYEES",
  "manifest_version": 27,
  "machine_uuid": "machine-uuid",
  "employees": [
    {
      "employee_id": "123",
      "employee_number": "14388",
      "name": "Operational Name",
      "assignment": {
        "uuid": "assignment-uuid",
        "type": "PRIMARY",
        "valid_from": "2026-09-01T12:00:00Z",
        "valid_until": null,
        "attendance_allowed": true,
        "enrollment_allowed": false,
        "maintenance_allowed": false
      }
    }
  ],
  "manifest_hash": "sha256-hex",
  "generated_at": "2026-09-04T18:30:00Z",
  "server_time": "2026-09-04T18:30:00Z",
  "changed": true
}
```

The array is a complete replacement. Missing entries must be removed from the edge authorization cache after successful application.

## POST `/device/manifests/ack`

Request:

```json
{
  "manifest_type": "EMPLOYEES",
  "manifest_version": 27,
  "manifest_hash": "sha256-hex",
  "applied_at": "2026-09-04T18:31:00Z",
  "status": "APPLIED"
}
```

`FAILED` may include a sanitized `error_code` and `error_message`. A successful response contains `duplicate` and `stale` flags plus current applied/server versions. A current-version hash mismatch returns HTTP 422 `MANIFEST_HASH_MISMATCH`. An old ACK returns HTTP 200 with `stale: true` and never decrements applied state.

GET responses never update applied versions. Only a valid `APPLIED` ACK does so.

## POST `/device/attendance/events`

Device-authenticated single-event intake. The request target is `/api/v1/device/attendance/events`; HMAC covers the exact JSON body.

```json
{
  "event_uuid": "4ae50b3b-12d1-4f50-a5c5-bb91fbf8bf18",
  "employee_id": "14388",
  "event_type": "CHECK_IN",
  "captured_at": "2026-09-04T18:31:00-06:00",
  "employee_manifest_version": 31,
  "configuration_version": 14,
  "assignment_uuid": "955f4906-13e4-44f9-9e57-e8132287e412",
  "device_timezone": "America/Mexico_City",
  "location": {
    "latitude": 19.4326,
    "longitude": -99.1332,
    "accuracy_m": 6
  },
  "geofence": {
    "version": 4,
    "edge_result": "INSIDE"
  }
}
```

Device and machine identity are derived from HMAC. `device_uuid`, `machine_uuid`, `vending_machine_id`, biometrics and arbitrary metadata are not accepted.

First receipt returns HTTP 201:

```json
{
  "event_uuid": "4ae50b3b-12d1-4f50-a5c5-bb91fbf8bf18",
  "status": "STORED",
  "remote_id": "8123",
  "authorization_result": "AUTHORIZED",
  "authorization_reason": "ASSIGNMENT_VALID",
  "server_geofence_result": "INSIDE",
  "warnings": [],
  "received_at": "2026-09-05T00:31:08Z",
  "server_time": "2026-09-05T00:31:08Z"
}
```

An identical retry returns HTTP 200 `DUPLICATE` with the original `remote_id`. UUID reuse with different evidence returns HTTP 409 `REJECTED/EVENT_UUID_CONFLICT`. Structural/time/employee failures return HTTP 422 with a stable `error_code`. Authorization `DENIED` or `UNVERIFIABLE` remains stored evidence rather than a transport rejection.

## POST `/device/attendance/events/batch`

Accepts `{"events": [...]}` with 1–100 events by default. The maximum is configured by `VENDING_ATTENDANCE_BATCH_MAX_EVENTS`. Each event uses the same contract and an independent transaction.

```json
{
  "results": [
    {"event_uuid": "...", "status": "STORED", "remote_id": "8123"},
    {"event_uuid": "...", "status": "DUPLICATE", "remote_id": "8001"},
    {"event_uuid": "...", "status": "REJECTED", "error_code": "INVALID_EMPLOYEE"}
  ],
  "server_time": "2026-09-05T00:31:08Z"
}
```

Mixed business outcomes return HTTP 200. An invalid/oversized envelope returns HTTP 422. Stable Phase 4 codes are `INVALID_EVENT`, `INVALID_EMPLOYEE`, `INVALID_TIMESTAMP`, `EVENT_UUID_CONFLICT`, `BATCH_LIMIT_EXCEEDED` and generic `INTERNAL_RECEIVER_ERROR` for an isolated unexpected batch-item failure.

## Attendance security and rate limits

Both endpoints require `device.hmac:vending`, timestamp tolerance, persisted single-use nonce and ACTIVE Device state. Defaults are 120 single requests/minute/Device and 30 batch requests/minute/Device. Invalid Device/signature attendance audits are capped at 10/minute/IP to prevent audit-log amplification. All values are environment-configurable.

## Deferred API policy

Employee and configuration manifests are FULL SNAPSHOT only. Delta cursors, biometric manifests/templates, Fortia attendance projection, client outbox/SQLite implementation and mobile storage remain deferred. Biometric capability is explicitly `supported: false`.
