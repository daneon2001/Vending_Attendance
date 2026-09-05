# Android E2E validation

## Scope and result

Validation date: 2026-09-05.

The Android edge flow was validated end to end against the local Laravel and
MySQL environment using only `VM-DEMO-001`. No SYBI production record, Fortia
integration, biometric implementation, or Phase 6 feature was used.

Result: **PASS**.

## Test environment

- Physical Android device connected and authorized through ADB.
- Debug APK application id: `com.medicalife.vendingattendance`.
- API path: direct LAN (`LAN_DIRECT`), with the workstation and Android device
  on the same private Wi-Fi network.
- The session-specific API address was supplied by ignored
  `mobile/.env.local`; it is not embedded as a tracked production setting.
- ADB reverse was not used. It remains an optional debug-only fallback and is
  not part of the application architecture.

## Local HTTP policy

The local Laragon endpoint uses HTTP, so the debug manifest enables Android
cleartext traffic. The main manifest explicitly disables it and therefore the
release package does not permit HTTP cleartext.

Capacitor mixed content is enabled only when the build is given an explicit
HTTP `VITE_API_BASE_URL`. Normal HTTPS or unconfigured builds keep it disabled.
Production remains required to use HTTPS.

## Provisioning and device lifecycle

A new high-entropy, single-use provisioning token was generated through the
server domain service for `VM-DEMO-001`. The plaintext token was passed directly
to the app and was never written to this document or a file.

Provisioning created `ANDROID-DEMO-001` as the only ACTIVE device for the
machine and consumed the token. The previously ACTIVE demo terminal became
RETIRED, preserving its historical record.

The individual device credential was returned only by the provisioning
response and saved through native Secure Storage. It was not written to SQLite,
browser local/session storage, ordinary preferences, application logs, or UI.
Reopening and reinstalling the debug APK without clearing app data recovered
the identity and authenticated successfully, demonstrating usable native
credential persistence.

## Bootstrap and manifests

The provisioned device authenticated with the real HMAC protocol. Machine
identity was derived server-side from the authenticated device.

Bootstrap persisted the demo machine, timezone, configuration version, active
circular geofence, and server time in SQLite. Manifest status then downloaded
and transactionally applied:

- configuration manifest version 2;
- employee manifest version 5;
- two effective employees scoped only to `VM-DEMO-001`.

Each APPLIED acknowledgement was sent only after the corresponding local
transaction committed. Server and device applied versions ended at 2/2 and 5/5
with SYNCED state. Biometrics remained unsupported and no biometric template or
matching logic was introduced.

## SQLite and restart persistence

After a process stop/restart, the app retained device identity, machine,
geofence, two employees, assignments, manifest versions, and sync state. It did
not request provisioning again.

SQLite contains operational state, manifest snapshots, attendance evidence, and
the outbox. Its schema contains only `credential_version`; it does not contain a
credential, secret, provisioning token, or HMAC signature.

## GPS and geofence

The app requested precise foreground location permission through the Android
permission UI. A physical GPS reading was obtained with approximately 14.2 m
reported accuracy.

The TypeScript circular-geofence validator classified the reading as OUTSIDE,
approximately 4.98 km from the unchanged demo geofence. Laravel independently
validated the historical geofence version and also returned OUTSIDE. There was
no edge/server discrepancy and no coordinates were changed to force a result.

## Offline attendance and outbox

Wi-Fi and mobile data were both disabled after manifests were available. The
app remained usable with cached machine, geofence, and both authorized
employees. A CHECK_IN was generated with an edge UUID, real GPS evidence,
effective assignment, config version 2, and employee manifest version 5.

The event and its outbox row committed locally before any network attempt. The
outbox state was PENDING. A full process restart while still offline preserved
the same event and the PENDING count.

After restoring both radios, the connectivity listener detected ONLINE and sent
the batch with device HMAC. Laravel stored exactly one immutable vending
attendance event and the local outbox moved to SYNCED without deleting its
evidence. Captured and received times remained separate; the observed offline
sync delay was 114 seconds.

The stored event was AUTHORIZED from a valid assignment, used location evidence
status VALID, retained matching OUTSIDE edge/server results, and recorded
`NOT_USED` for the biometric placeholder.

## Idempotency

The already-synchronized outbox row was placed back in PENDING for one
controlled replay. Neither its UUID nor canonical payload was edited. The app
resent the original payload through the normal HMAC batch endpoint.

The API returned DUPLICATE, the outbox returned to SYNCED, the server row count
remained one, and no UUID conflict audit was created.

## Heartbeat and device registry

A real heartbeat updated the ACTIVE device with Android platform and app
versions, zero pending events, recent `last_seen_at`, applied manifest versions,
and a reasonable clock drift of approximately one second.

The administration Device Registry contract for `VM-DEMO-001` exposes the new
ACTIVE Android device with its sync state and the previous RETIRED terminal.
The registry status service reports configuration and employees as SYNCED.

## Security review

- Provisioning token: single-use, consumed, and absent from logs and tracked
  files.
- Device credential: no plaintext match in SQLite, WebView storage, ordinary
  preferences, or Android logcat; native Secure Storage holds the protected
  value.
- HMAC signature: absent from Laravel logs and Android logcat.
- APP_KEY, database passwords, and configured SYBI secrets: no matches in
  Laravel logs.
- No response body from successful provisioning was printed or documented.
- Debug HTTP is isolated from release policy.
- Temporary WebView debugging forwards were removed after diagnostics.

## Post-E2E verification

- Laravel: 401 tests passed with 3,171 assertions. The single failing
  `OnPremDiagnosticsCommandTest` is the inherited, previously documented
  baseline failure and is not a new regression.
- Mobile: `npm test` passed 9 files and 20 tests.
- Web bundle: `npm run build` passed. Vite reported only the known stale
  Browserslist data, dependency Tailwind-content, Ionic `:host-context`, and
  large-chunk warnings.
- Android: Gradle `assembleDebug` passed and the final APK was installed on the
  physical device while preserving the provisioned state.
- Administration: the vending detail feature tests passed, its Device Registry
  includes all lifecycle/sync columns, and the live local status service
  returned ACTIVE/SYNCED for the Android device and RETIRED for its predecessor.

## E2E defects corrected

1. The installed bundle lacked the current local API URL; the debug bundle was
   rebuilt with the ignored local environment.
2. Android WebView blocked the local HTTP endpoint as mixed content; Capacitor
   now enables it only for an explicit HTTP build while release cleartext stays
   disabled.
3. Sanctum classified Capacitor's localhost origin as a stateful human SPA and
   applied CSRF to Device APIs; only `api/v1/device/*` is excluded because those
   routes use provisioning-token or HMAC authentication, never a human session.
4. A detached native `fetch` function caused WebView `Illegal invocation`;
   clients now call a `globalThis.fetch` wrapper while preserving injected test
   transports.
5. SQLite `run()` opened nested transactions inside explicit snapshot/outbox
   transactions; statements now participate in the existing transaction.
6. The Home employee list did not refresh when initial automatic sync completed;
   it now reloads when the applied employee-manifest version changes.

## iOS

`mobile/ios` remains generated. Build validation is **PENDING_MACOS** and was not
attempted on Windows; this does not block the Android E2E result.
