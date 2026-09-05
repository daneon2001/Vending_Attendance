# Machine bootstrap contract

## Endpoint

`GET /api/v1/device/bootstrap` is authenticated by the vending-device HMAC middleware. The associated machine comes exclusively from the authenticated `Device`; callers cannot choose a machine UUID.

An optional signed query parameter, `config_version_applied`, reports the snapshot already installed on the device. If it equals the server machine version, `configuration_changed` is `false`; otherwise it is `true`. Phase 2 always returns a small consistent snapshot and does not implement deltas.

As of Phase 3 this query value is comparison-only. Bootstrap and heartbeat never persist an applied version; only a valid `POST /api/v1/device/manifests/ack` with status `APPLIED` advances manifest state.

## Response

```json
{
  "device": {"uuid": "...", "status": "ACTIVE"},
  "machine": {
    "uuid": "...",
    "machine_code": "...",
    "status": "ACTIVE",
    "config_version": 5,
    "timezone": "America/Mexico_City"
  },
  "geofence": {
    "uuid": "...",
    "version": 3,
    "type": "CIRCLE",
    "center_latitude": 19.4326,
    "center_longitude": -99.1332,
    "radius_m": 40,
    "minimum_acceptable_accuracy_m": 25,
    "tolerance_m": 0
  },
  "configuration_changed": true,
  "server_time": "...",
  "sync": {
    "employee_manifest_version": null,
    "biometric_manifest_version": null
  }
}
```

`geofence` is null when no ACTIVE/effective geofence exists. The response excludes credentials, hashes, internal metadata, audit records, employees, biometrics, and other devices.

## Configuration version policy

`VendingMachine.config_version` starts at 1. It increments for edge-relevant changes: machine/operational identity, operational status, coordinates and their verification state, timezone, default geofence radius, installation/retirement state, or activation of a geofence version. Activation is bumped through `MachineConfigurationVersionService`; machine model updates apply one centralized attribute policy. Name and textual address edits do not increment the version.

Future Employee and Biometric Manifest versions remain null in this phase.

## Heartbeat

`POST /api/v1/device/heartbeat` uses the same device identity and HMAC contract. It accepts latest-state telemetry only: app version/build, platform version, applied config version, optional battery, optional free storage, optional pending-event count, network state, a sanitized latest error category/code/time, and required device time. The edge client uses the returned `next_heartbeat_seconds` for a lightweight periodic heartbeat; it does not rerun bootstrap or manifest polling each interval.

The server calculates:

```text
clock_drift_seconds = server_unix_time - device_unix_time
```

Drift does not reject the heartbeat. Absolute drift over the configurable 300-second default produces a response warning. `device.clock_drift_detected` is emitted only when entering the warning state, avoiding one audit row per interval. Reporting a configuration version greater than the server version produces `device.invalid_configuration_version`. Normal heartbeats update current state without an audit row.
