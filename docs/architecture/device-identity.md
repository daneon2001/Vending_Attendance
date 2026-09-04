# Device identity

## Boundary

`VendingMachine` is the durable operational identity imported from the machine catalogue. `Device` is one physical application installation attached to that machine. Replacing hardware retires the old `Device` and provisions a new one without changing the machine UUID, assignments, geofence history, or configuration version.

A machine may retain many historical devices. `devices.active_vending_machine_id` is populated only for an ACTIVE vending device and has a unique database index, so at most one operational primary device can exist for a machine. Legacy `unit_id`, `clock_id`, `shared_secret`, and OnPrem relationships remain available and are not migrated automatically.

## Identity and credential

- External identity: `devices.uuid`, sent as `X-Device-Id`.
- Inventory attributes: serial, name, platform and hardware model. These are identifiers, not credentials.
- Credential: 256 random bits generated independently for each device.
- Storage: Laravel's encrypted cast protects `credential_secret` at rest; the plaintext is returned only by issue/rotation and never serialized by the model.
- Rotation: increments `credential_version`, invalidates the prior credential immediately, and emits `device.credential.rotated`.
- Revocation/retirement: clears the encrypted credential and prevents future device authentication.

The inherited OnPrem protocol still uses `X-Device-Serial` and its legacy `shared_secret`. New vending endpoints never use `machine_code`, `device_serial`, or the legacy shared secret as the credential.

## Lifecycle

| Status | Meaning | Operational API access |
| --- | --- | --- |
| `PENDING` | Registered but not activated | No |
| `ACTIVE` | Current installation with valid credential | Yes |
| `SUSPENDED` | Temporarily disabled; credential retained | No |
| `REVOKED` | Permanently denied; credential removed | No |
| `RETIRED` | Historical replaced/decommissioned hardware; credential removed | No |

Allowed transitions are `PENDING -> ACTIVE/REVOKED/RETIRED`, `ACTIVE -> SUSPENDED/REVOKED/RETIRED`, and `SUSPENDED -> ACTIVE/REVOKED/RETIRED`. `REVOKED` and `RETIRED` are terminal in Phase 2.

## Latest operational state

The device row stores the latest heartbeat state: application/platform version, manifest/config versions applied, last seen, battery when applicable, free storage, pending-event count, device time, and calculated clock drift. Heartbeats are not appended indefinitely to a telemetry table in this phase.

## Audit boundary

Lifecycle, provisioning, rotation, bootstrap, excessive clock drift, and invalid future configuration versions are audited. Credentials, provisioning-token plaintext, request signatures, biometric data, and unrestricted metadata are excluded from audit payloads.
