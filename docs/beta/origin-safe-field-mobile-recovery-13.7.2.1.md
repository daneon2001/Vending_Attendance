# Phase 13.7.2.1 — Origin-safe recovery

Status: PARTIAL — READY_FOR_PHYSICAL_SESSION_RECOVERY. Automated validation and APK preparation completed; installation, human login, Android Keystore proof and restart on HONOR are NOT_RUN.

## Problem and implementation

The old enrollment draft combined transport origin with device recovery. A draft for the previous LAN caused origin rejection before consulting the authenticated profile; the initial screen also presented registration before human login.

`FieldMobileStore.ts` separates human session, enrollment draft and verified persistent identity. `FieldMobileFlow.ts` first obtains the authenticated profile and selects the existing UUID, checks employee, status, native key version and fingerprint, then proves a fresh ACTOR challenge using `FieldEnrollment.verifyExisting`. Only successful proof saves the verified identity and displays ACTIVE. The backend derives the employee from the authenticated user and verifies the registered public key. Existing ownership, activation timestamp and enrollment history remain authoritative.

A stale draft is retained but its OTP metadata is not used for a new origin. With no ACTIVE binding the user reaches explicit normal registration. Before login, `FieldMobilePage.vue` says “Inicia sesión para consultar tu dispositivo.”

`FieldOriginRecoveryPolicy` permits only the explicit old-to-new HTTPS LAN transition in DEBUG. RELEASE does not permit that transition. Native fingerprint canonicalization is shared with a pure Java test using a synthetic public key; the existing alias convention and signing key are unchanged. No trust-all, TLS bypass or permissive hostname verifier was added.

## Automated evidence

| Scenario | Result and coverage |
| --- | --- |
| 1. Same identity/key, new authorized HTTPS origin | PASS — Flow recovery plus FieldMobileUxTest new-origin login/profile/ACTOR proof |
| 2. Wrong key | PASS — DeviceIdentityTest wrong-key ACTOR proof and Flow fingerprint mismatch |
| 3. Different UUID | PASS — Flow rejects another ACTIVE UUID when local reference exists |
| 4. Different user/employee | PASS — backend ownership proof denial and Flow employee mismatch |
| 5. Revoked | PASS — backend outstanding-proof invalidation and Flow blocked binding |
| 6. Replaced | PASS — backend replacement history/status checks and Flow non-ACTIVE rejection |
| 7. Stale draft with ACTIVE | PASS — repeated recovery preserves draft and existing key |
| 8. Stale draft without ACTIVE | PASS — explicit registration without stale OTP reuse |
| 9. HTTP | PASS — transport/native/backend human endpoint rejection |
| 10. Unauthorized RELEASE origin | PASS — native origin policy and Flow denial before challenge |
| 11. Replay | PASS — backend ACTOR proof consumes challenge |
| 12. Missing local key | PASS — blocks without generating replacement key |
| 13. Normal enrollment | PASS — login/OTP/key/challenge/ACTIVE remains covered |

- Mobile: 36 files, 330 tests PASS.
- PHP: 60 tests, 570 assertions PASS in `tests/Unit/Testing`, `tests/Feature/Testing`, `tests/Feature/FieldIdentity`; testing/SQLite safety banner PASS. OpenSSL uses the installed PHP OpenSSL config.
- Android: 11 tests PASS, including fingerprint canonicalization, signature, origin policy, network security and activity behavior.
- Pint for modified PHP test: PASS. `git diff --check`: PASS.
- `npm run build`, `npx cap sync android`, `gradlew -PinternalBeta=true assembleDebug --no-daemon`: PASS.
- Known unrelated OnPremDiagnosticsCommandTest baseline failure remains accepted; full suite was not rerun. No legacy disabled harness was run.

## Data and artifact

Read-only Laravel queries in a read-only transaction compared counts and canonical row SHA256 hashes before/after: **14/14 MATCH**. Private evidence: `storage/app/private/phase-13.7.2.1-incident/origin-resume-baseline-{before,after}.json`.

Employees 2507; users 5; assignments 8; attendance 0; vending attendance events 22; support activities 2; employee_devices 1. The full employee_devices hash is unchanged, preserving id, UUID, public-key fingerprint, key version, owner, ACTIVE status and activated_at. SYBI 7 and assignment 7 are preserved by their complete table comparisons. ASISTENCIAS_FORTIA was not accessed or modified in this phase; no fresh independent Fortia audit is claimed.

APK: `storage/app/private/phase-13.7.2.1/VendingAttendance-1.0.1-beta.1-build4-origin-recovery.apk`.

- App ID: `com.medicalife.vendingattendance`; version `1.0.1-beta.1`; versionCode `4`; DEBUG internal beta.
- Size: 29,284,676 bytes.
- SHA256: `ac46adbc903bb2cc7896f09d651e0fb204d452dd3f879f8a62635882ed7a6870`.
- Signing certificate SHA256: `2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec`, identical to preserved build 3 APK.
- APK signature verification PASS. Packaged new HTTPS origin and pre-login text verified. File-type/private-key-marker scan PASS; this is a scoped scan, not a claim of exhaustive secret detection.
- Artifact and manifest excluded from Git; build 3 preserved.

No physical OTP, challenge, key generation, installation or device mutation was executed. No commit, tag, push or deploy.

## Physical gate

User section 15 requires authorization before `adb install -r` of this new artifact. After approval, preserve SQLite, SecureStorage and Android Keystore; let the user enter credentials privately. Verify existing binding proof and all identity fields, then force-stop/reopen. Stop on registration/OTP/identity-creation prompts or changed fingerprint. Automated proof does not establish that physical recovery has already succeeded.
