# Mobile release management

## Separation of concerns

Phase 8 implements release metadata and version policy, not binary delivery or an updater. `mobile_releases` never stores APK/IPA bytes. A published record references an HTTPS artifact URL and requires its SHA-256 checksum. Production artifact hosting, TLS pinning, signed-download authorization and CDN design remain separate deployment decisions.

`mobile_release_policies` defines `current`, `recommended`, and `minimum` releases per platform and channel. A policy may only reference a published release in the same platform/channel.

## Platforms, channels and rollout

Initial platforms are `ANDROID` and `IOS`. Channels are `DEV`, `PILOT`, and `PRODUCTION`. Every device has one channel, defaulting to `PRODUCTION`.

Allowed rollout percentages are `0`, `1`, `5`, `25`, and `100`. Eligibility uses a deterministic SHA-256 bucket of device UUID, so the same device remains in the cohort as the rollout grows. A release with no explicit targets applies to its whole channel; `mobile_release_targets` can narrow it to one or more device UUIDs and/or operator-defined device groups. Devices carry an optional `release_group`, while channel defaults to `PRODUCTION`. The catalog does not push or download anything automatically.

## Version state

The derived states are:

- `CURRENT`: installed version satisfies current/recommended policy;
- `UPDATE_AVAILABLE`: an eligible, non-mandatory target is newer;
- `UPDATE_REQUIRED`: an eligible mandatory target is newer;
- `UNSUPPORTED`: installed app is below the minimum release or platform OS is below the target minimum;
- `UNKNOWN`: app version or policy is unavailable. This explicit state avoids silently treating missing evidence as current.

Comparison uses semantic version first and build number as the tie-breaker. Minimum policy applies regardless of rollout percentage. Rollout affects the recommended target only.

## Release integrity and signing gate

- Published artifacts require HTTPS and a 64-hex-character SHA-256 checksum.
- Android production artifacts must be signed by the controlled release key; unsigned/debug APKs are never production candidates.
- iOS artifacts require the approved certificate/profile workflow on macOS.
- Device HMAC credentials and application-signing keys are separate trust domains.
- Rollback must use a new explicit policy decision; silently lowering a minimum/current version is not an edge instruction.
- Artifact signatures/checksums must be verified by the future updater before installation. That updater is not implemented in Phase 8.

The administration page displays only checksum fingerprints and metadata. It never accepts a signing key, certificate, provisioning profile or device secret.
