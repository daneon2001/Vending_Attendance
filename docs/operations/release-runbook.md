# Mobile release and rollback runbook

## Release preparation

1. Build from an approved Git checkpoint with `VITE_DEPLOYMENT_MODE=pilot` and the approved HTTPS API URL.
2. Supply the Android keystore, alias and passwords only through protected pipeline/environment variables. `preReleaseBuild` runs `verifyPilotReleaseConfiguration` and must pass.
3. Store the signed artifact in approved HTTPS storage. Calculate SHA-256 independently.
4. Register metadata in **Vending > Releases**. Do not upload a binary, keystore or token to Laravel.
5. Keep v1.0.0 as current. Register v1.0.1 as a PILOT candidate only after testing.
6. Target explicit pilot devices/group. Use 1%, 5%, 25%, then 100%; for fewer than 100 devices, deterministic percentage buckets may select zero devices, so explicit DEVICE/GROUP targets are authoritative.
7. Confirm checksum, version/build, OS floor and channel before changing policy.

The current system advertises policy and health state; it does not download or install updates automatically.

## Rollout observation

At every step observe crashes reported by the device/OS process, heartbeat age, outbox growth, manifest state, attendance failures, update-required alerts, API latency and support reports. Promotion is manual and requires an observation window agreed by the pilot owner.

## Rollback

1. Detect a bad release and freeze expansion.
2. In Releases select **Bloquear rollout**. This changes status to `BLOCKED`, sets rollout to 0%, audits the action and excludes the release from eligibility.
3. Change the PILOT policy so the last known-good published version is `current` and `recommended`; set `minimum` consistently.
4. Because no updater exists, reinstall/downgrade the signed known-good APK using the approved device management/manual maintenance procedure. Do not clear app data; validate Android downgrade compatibility before use.
5. Verify the device reports the known-good app version, heartbeat and both manifest states, and flushes its outbox.
6. Record affected devices, bad/good hashes, times and outcome. Never attach APKs or signing credentials to a ticket.

A blocked release is not deleted and cannot silently become eligible. Automatic binary rollback is explicitly not implemented.
