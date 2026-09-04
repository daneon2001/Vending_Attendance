# Vending API v1

Base prefix: `/api/v1`. Every endpoint is protected with `auth:sanctum` and the strict `vending_machines.view` permission; there are no public vending routes.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/vending-machines/{uuid}` | Machine configuration and active geofence |
| GET | `/vending-machines/{uuid}/geofence` | Current effective ACTIVE geofence |
| GET | `/vending-machines/{uuid}/assignments` | Assignment history for a machine |
| GET | `/employees/{employee}/vending-machines` | Effective assignments and machines for an employee |
| POST | `/geofence/validate` | Spatial validation only |

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

## Deferred API policy

Sanctum establishes the authentication boundary. Fine-grained mobile scopes, device identity, rate limits, manifest endpoints, replay protection, and offline synchronization contracts are decisions for a later API/mobile phase.
