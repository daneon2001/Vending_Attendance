# Fleet database and retention review

## Critical access paths

| Query path | Supporting index / strategy |
| --- | --- |
| Active devices by heartbeat age | `devices(status, last_heartbeat_at)` |
| App distribution/filter | `devices(platform, app_version)` |
| Release channel/group targeting | `devices(release_channel, release_group)` and `mobile_release_targets(target_type, target_value)` |
| Device lookup/auth | unique `devices.uuid` |
| Replay lookup/retention | unique `device_nonces(device_id, nonce)` and `device_nonces.expires_at` |
| Manifest state | unique `device_manifest_states(device_id, manifest_type)` |
| Device attendance timeline | `vending_attendance_events(device_id, captured_at_device)` |
| Machine attendance timeline | `vending_attendance_events(vending_machine_id, captured_at_device)` |
| Employee attendance timeline | `vending_attendance_events(employee_id, captured_at_device)` |
| Latest attendance aggregate | unique `device_attendance_metrics.device_id` |

No index was added for every dashboard counter. With approximately 1,000 device latest-state rows, selective composite indexes cover health and version filters while aggregate tables cover high-volume attendance. Excess indexes on immutable attendance would increase write cost.

## Growth controls

- device rows are historical lifecycle records, not heartbeat samples;
- nonces are pruned every minute with at most one configured batch per scheduler run;
- audit cleanup is daily with critical/important/noise categories;
- device attendance metrics remain one row per device;
- raw attendance is retained as business evidence pending legal/operational policy;
- manifest polling and successful heartbeat do not create audit rows;
- SYBI synchronization keeps its existing configurable cadence and is not modified by this phase.

## EXPLAIN evidence

The 1,000-device MySQL benchmark captures optimizer keys for the health scan, version distribution and attendance-by-device path. Actual keys and any deviation are recorded in `fleet-load-test.md`; no capacity conclusion is based on SQLite.
