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
| POST | `/device/heartbeat` | Update latest Device telemetry |
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

## Deferred API policy

Employee and configuration manifests are FULL SNAPSHOT only. Delta cursors, biometric manifests/templates, attendance upload, offline conflict handling, and mobile storage remain deferred. Biometric capability is explicitly `supported: false`.
