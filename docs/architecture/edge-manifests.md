# Edge manifests

## Protocol goal

The edge protocol exposes versioned desired state to one authenticated vending device. Its trust chain is strictly:

```text
Device -> VendingMachine -> effective EmployeeMachineAssignment -> Employee
```

It does not use branch, location, unit, `base_location_id`, `can_check_all_branches`, or `check_scope`. A device cannot supply or enumerate another machine UUID because the machine is always derived from the HMAC-authenticated device.

## Manifest types

| Type | Server version | Phase 3 support |
| --- | --- | --- |
| `CONFIGURATION` | `VendingMachine.config_version` | Full snapshot |
| `EMPLOYEES` | `VendingMachine.employee_manifest_version` | Full snapshot |
| `BIOMETRICS` | Not assigned | Explicit placeholder with `supported: false` |

No biometric template, biometric metadata, fake biometric version, or biometric download logic exists in Phase 3.

## Device flow

1. Poll `GET /api/v1/device/manifests/status`.
2. Download only changed supported manifests.
3. Validate the SHA-256 manifest hash.
4. Persist the complete snapshot atomically on the edge.
5. Send `POST /api/v1/device/manifests/ack` with `APPLIED` or `FAILED`.
6. Treat a successful `APPLIED` ACK as the only confirmation that server desired state is installed.

Downloading is not applying. Configuration bootstrap, heartbeat, status polling, and manifest GET responses never advance an applied version.

## Configuration desired state

`MACHINE_CONFIGURATION` contains only the device UUID, machine UUID/code/status/timezone, `config_version`, and current ACTIVE circular geofence. Administrative address, metadata, assignments, audit data, credentials, and other devices are omitted.

For a given device and `config_version`, the hashed configuration content is deterministic. `generated_at` and `server_time` are informational and excluded from the hash.

## Snapshot semantics

Employee manifests are complete desired state, not an additive list. When version 28 omits an employee present in version 27, the edge must remove that employee from its authorized cache after atomically applying version 28. This behavior is required for revocation and will later also govern biometric-template removal.

Phase 3 intentionally does not implement deltas. Small per-machine snapshots are preferred until fleet measurements justify additional complexity.

## Server time

Every manifest status, download, and ACK response contains UTC `server_time`. Server time, never device time, determines effective assignments. Validity windows are included so an offline client can independently stop authorizing an expired temporary assignment.
