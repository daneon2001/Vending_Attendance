# Mobile deployment origin contract

CP-C06-FIX. This contract is implemented by `mobile/src/config/deployment-origins.mjs`, shared by runtime, Vite and the Android Node gate.

## Configuration and metadata

Build environment variables are the source of truth. Beta uses explicit `BETA_API_BASE_URL` and `BETA_FIELD_IDENTITY_BASE_URL`. Other modes use `VITE_DEPLOYMENT_MODE`, `VITE_API_BASE_URL` and optional `VITE_FIELD_IDENTITY_BASE_URL`. An absent/empty identity origin falls back only to the validated API origin (except beta, which requires both).

Vite validates before bundling and uses the same normalized values for compiled environment constants and generated `deployment.json`. Runtime validates again at startup. Android's beta/release gates invoke `mobile/build/verify-deployment.mjs` with the build environment and the synced asset metadata. A different API, identity or mode, missing metadata, or invalid configuration aborts the build. Node must be available to Gradle. Rebuild and sync the matching web assets before a native beta/release build.

Persisted session/draft origins and `verifiedOrigin` are isolation and proof controls, not endpoint selectors. They do not override the compiled destination. Moving to another origin requires a new origin-scoped session and the existing authorized recovery proof; stored tokens are not transferred across origins.

Strict beta, pilot and production origins require HTTPS and a concrete DNS hostname. Wildcards, IP literals (including loopback/IPv6), localhost/local/invalid suffixes, userinfo, query, fragment, non-root paths and malformed URLs are rejected. Explicit HTTPS ports remain supported. Case and a single root slash are normalized by URL.origin. Examples use reserved synthetic DNS:
- `https://api.example.test`
- `https://identity.example.test`
- `https://api.example.test:8443`

Development preserves explicitly configured local HTTPS IP origins and the existing optional empty API for local frontend development. The existing debug-only host-scoped CA policy is unchanged. No release trust-all, user CA, cleartext or ATS exceptions are introduced.

## Recovery and release boundary

The beta recovery target is canonicalized with the same validator before compilation. Existing exact-target and previous-origin SHA256 authorization, installation key, challenge/proof and cross-origin session rejection remain unchanged. Production release recovery remains disabled as before; this fix does not enable a new transition policy.

Existing signing requirements, package/version metadata and SDK levels are unchanged. Metadata consistency is a build check, not proof of real signing, DNS ownership, successful deployment or store readiness. Historical Build 12 metadata is not changed.

Build 12 is a historical physical baseline, not validation of a newly configured domain. No real deployment domain is configured or approved by this contract. HTTPS URL validation does not establish TLS trust: release uses system certificate authorities; local user-CA trust remains confined to the explicit debug host.

For the CP-C06 consolidation candidate, the optional `betaUseExistingDebugSigning` branch and its related checks remain unstaged. Beta inherits the existing release signing configuration and fails closed when that configuration is absent. This does not provision signing material or authorize store distribution. The safe `mobile/.env.beta.example` template is tracked explicitly; the unrelated pending `.gitignore` exception is not part of CP-C06.

## Verification

Historical CP-C06-PRE negative audit: 4 FAIL / 1 PASS; three production failures also reproduced against the prior HEAD.

CP-C06-FIX was verified in a new detached worktree from `b5ff9252be8f1d33d7647f3229a4f9bfaf135c6c` with only the necessary pending mobile CP-C06 candidates and this fix:
- Lock installs: root 184 packages, mobile 370 packages.
- Specific beta/runtime/native-gate/network/FIELD_MOBILE tests: 184 PASS.
- Full mobile suite: 478 PASS, 39 files.
- Default, synthetic beta and synthetic production web builds: PASS.
- Production wildcard build: rejected before compilation.
- Android JDK 21 debug unit suite: 13 PASS; includes exact DNS-to-DNS recovery.
- Scoped debug CA and recovery subset: 4 PASS using a reserved synthetic IP.
- Actual Gradle release gate rejects discordant API, identity and mode metadata.
- `:app:processReleaseMainManifest :app:mergeReleaseResources`: PASS. The signing configuration gate required a temporary synthetic keystore, deleted after inspection. No APK was built or signed.

The merged release manifest uses `usesCleartextTraffic=false` and `@xml/secure_network_policy`. Release resource merging excludes the debug policy; secure policy trusts system certificates only. No background-location or foreground-service permission is present. Exported components are the launcher MainActivity and the existing ProfileInstallReceiver protected by `android.permission.DUMP`; providers and Room service remain unexported. No new permission was added by this fix.

No physical devices, local environment files, LAN configuration, real keys or data were used or modified. This is technical build evidence, not physical or production validation.
