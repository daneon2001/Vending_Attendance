# Manifest versioning and hashing

## Independent version domains

`VendingMachine.config_version` versions physical/operational configuration. It increments for edge-relevant machine identity, operational status, coordinates/verification, timezone, default radius, installation/retirement, and active geofence changes. Pure name or textual-address edits do not increment it.

`VendingMachine.employee_manifest_version` is an independent unsigned bigint starting at 1. It changes when employee authorization desired state may change and never reuses `config_version`.

## Employee version bump rules

`EmployeeManifestVersionService` centralizes monotonic increments. It invalidates the stored semantic state hash and increments under a `SELECT ... FOR UPDATE` machine lock for:

- assignment creation;
- assignment revocation;
- changes to assignment employee/machine, type, validity, status, revocation, or explicit permissions;
- relevant Employee status, operational identifier, or display-name changes while effective assignments exist.

Source, metadata, audit reason, and unrelated Employee fields do not bump the employee manifest version.

Temporal boundaries need no scheduler. The service stores an internal semantic hash of the current effective grants. On status/download generation, it recomputes that hash under the machine lock. If `valid_from` or `valid_until` changed the effective set without a database update, it increments the version once and records the new state hash.

Assignment mutation services acquire the same machine lock before writing. Manifest generation and version bump are therefore serialized. ACK processing locks the Device and its `(device_id, manifest_type)` state row, so duplicate/interleaved ACKs cannot move an applied version backwards.

## Manifest hash

Every supported snapshot includes `manifest_hash` as lowercase SHA-256. Hash input is the manifest content containing type, version, machine/device identity as applicable, and deterministic desired-state fields. It excludes `manifest_hash`, `generated_at`, `server_time`, `changed`, and any response-only values.

Canonicalization recursively sorts associative keys using binary string order, preserves the deliberate ordering of list entries, and serializes with JSON using unescaped Unicode/slashes and preserved zero fractions. Employee grants are ordered by employee ID and assignment UUID. The same version/content therefore produces the same hash even when generated at different times.

## Snapshot before delta

Phase 3 returns only FULL SNAPSHOT manifests. `manifest_version` and canonical hashes create a stable base for future DELTA support, but no cursor, tombstone stream, or delta behavior is claimed yet.
