# Phase 13.7.3 — Internal beta RC closeout, build 5

Audit: 2026-09-14 UTC (2026-09-13 America/Mexico_City). PHASE 13.7.3: PASS for current internal-beta closeout and checkpoint preparation. INTERNAL BETA: READY. REAL PILOT: NOT_READY. PRODUCTION: NOT_APPROVED. FINAL: READY_FOR_CHECKPOINT_AUTHORIZATION.

No rebuild, full suite, global visual review, second-device enrollment, data cleanup, staging, commit, tag, push or deploy was performed during this closeout.

## Final data and identity

Fresh read-only transactions on vending_attendance_dev and the verified isolated checkpoint confirm:

| Baseline | Result |
| --- | --- |
| Employees / users / assignments | 2507 / 5 / 8 |
| Attendance / vending attendance events | 0 / 22 |
| Support activities | IDs 1 and 2, both COMPLETED |
| FIELD_MOBILE | Exactly 1 ACTIVE, id 1 |
| Identity | Same UUID, public key/fingerprint, key version 1, User 4 / Employee 5, activated_at |
| SYBI 7 / assignment 7 | PRESERVED by exact complete-table comparison |
| ASISTENCIAS_FORTIA | Received clean baseline preserved; not accessed or independently re-audited |
| PHONE / phoneVerified / BIOMETRY | LOCAL_SIMULATED / false / NOT_IMPLEMENTED |

BUSINESS DATA: UNCHANGED. IDENTITY: UNCHANGED. SESSION/DIAGNOSTIC: EXPECTED CHANGE.

Complete hashes match in 12/14 datasets. Semantic comparison matches in 14/14, excluding only users.id=2.remember_token and employee_devices.id=1.last_seen_at/updated_at. This is not blanket exclusion of all users or device columns. Passwords, owners, activation, key material, counts and relationships remain part of the comparison. No rows were edited to obtain a match. Private evidence: storage/app/private/phase-13.7.3/final-baseline.json, plus the field-by-field physical audit from Phase 13.7.2.1.

## Exact installed artifact and source traceability

- Filename: VendingAttendance-1.0.1-beta.1-build5-lan-192.168.1.82.apk.
- VersionName 1.0.1-beta.1; versionCode 5; app ID com.medicalife.vendingattendance.
- Size: 29,284,669 bytes.
- SHA256: 8c990e317f667732886603d4543ffc82d14cde667e6096254a3c93754d4aec31.
- Artifact timestamp: 2026-09-14T04:02:50.088886Z; original manifest recorded 04:03:22.5873208Z.
- Direct adb sha256sum of the installed HONOR base.apk returns the identical hash; package manager confirms version/build. APK MATCHES INSTALLED: YES.
- Signing certificate SHA256: 2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec; same debug signer as prior artifacts.

All 102 packaged web assets match the current synchronized Android assets. The 100 Vite-produced assets match dist; the other two are capacitor-sync-generated cordova.js and cordova_plugins.js, absent from dist as expected. Packaged capacitor configuration also matches. Current mobile build-input hashes are captured privately; no selected executable/build input has a modification time later than the artifact. This supports current-build provenance together with the recorded build commands; it is not a claim of reproducible byte-identical rebuild on a clean host. No rebuild was needed.

Build inputs: mobile source/native resources, internal-beta.json build 5, package lock, Gradle configuration/wrapper, local environment, SDK configuration, installed JBR and dependency caches. Local mobile environment sets both API and identity HTTPS origins to 192.168.1.82:8443. Local environment, SDK paths, CA/server private keys, debug signing keystore and runtime artifacts remain outside staging. Their secrets are not reproduced here. The explicit LAN literals in native DEBUG recovery/trust policy and policy tests are intentional security allowlists belonging to source; historical LAN literals in documentation describe prior evidence. RELEASE has no generic cross-origin trust allowance.

## Consolidated verification

| Evidence | Result |
| --- | --- |
| Database safety | PASS; pre-bootstrap/environment/connection/destructive-command guards active |
| FieldIdentity | PASS; 51 tests within the subsequent 60-test safety/identity run, 570 assertions overall |
| Support | PASS; prior hardening evidence: 152 tests |
| Mobile | PASS; 329/330 initial build-5 run, stale build-number fixture corrected, all 9 tests in that file then passed |
| Android native | PASS; 11 tests, zero failures |
| Build web / cap sync / assembleDebug | PASS from build-5 execution; not rerun |
| Full suite | 789 PASS / 1 KNOWN BASELINE FAIL, 6758 assertions; prior execution, not rerun |
| Known failure | OnPremDiagnosticsCommandTest: SQLite fixture missing clocks; not caused by recovery hardening |
| Physical HTTPS / login / User→Employee | PASS |
| FIELD_MOBILE recovery / fresh challenge / existing-key signature | PASS, ACTOR_PROVED evidence |
| Restart recovery | PASS, new proof after force-stop/reopen; no registration prompt |
| Same identity / Keystore / no duplicate / no OTP | PASS; preserved / preserved / no duplicate / OTP NOT_RUN |
| Android and web visual | PASS within previously approved scope; recovery UX checked separately |

References: database-safety-final-classification.md, origin-safe-field-mobile-recovery-13.7.2.1.md, physical-session-recovery-build5.md, internal-beta-visual-review.md and historical internal-beta-closeout-13.7.1.md. Historical artifact/version and provisional findings remain historical; this document identifies the current RC. No full-green claim.

## Limits retained

LOCAL_SIMULATED; phoneVerified=false; biometrics NOT_IMPLEMENTED; HTTPS LAN with DEMO CA; START_ONLY_V1; remote push DEFERRED; performance at 1,000 devices NOT_CERTIFIED; ParaTest/parallel suite DEFERRED; MySQL privilege boundary NOT_IMPLEMENTED. Two simultaneous SQLite subprocess checks do not certify ParaTest. Four legacy MySQL harnesses remain DISABLED_PENDING_PORT, not newly executed. Repository guards are not a server-level privilege boundary against arbitrary privileged SQL.

## DEMO inventory and lifecycle

All listed items are KEEP_FOR_INTERNAL_BETA and REMOVE_BEFORE_REAL_PILOT from the future real-pilot environment. Removal means a separately approved separation/archive/cleanup plan, not deletion now or loss of historical evidence.

| Item | Current inventory |
| --- | --- |
| VM-DEMO-001 | Machine 1, source DEMO, ACTIVE |
| Técnico Demo | Employee 5 / 990001005 |
| Pilot users | Admin 2, Operator 3, Support 4, Viewer 5; total users remains 5 |
| Assignment 8 | Employee 5 → machine 1, ACTIVE |
| DEMO geofence | Machine 1: id 4/version 2 ACTIVE; id 1/version 1 SUPERSEDED |
| Support activities | 1 and 2 COMPLETED; preserve related history/evidence in archive plan |
| FIELD_MOBILE DEMO | id 1, ACTIVE, User 4 / Employee 5; never clone/reassign its binding |
| Phone simulation | One ignored local setting, not an approved corporate phone source |

SYBI 7, assignment 7 and imported corporate employee records are not DEMO cleanup targets.

## Second Android gate

SECOND ANDROID: BLOCKED for execution; requirements documented. No second tester/device/binding has been created. Requires a named tester, its own User and Employee, individual phone, separately trusted DEMO CA, exact build-5 APK and a separately authorized enrollment producing a new UUID/keypair/FIELD_MOBILE/binding. Required comparisons: Device A != Device B, Key A != Key B, Binding A != Binding B, while Device A remains untouched.

Current blocker is explicit in RegisteredPhoneSource and EnrollmentIdentity: local DEMO phone resolution is restricted to User 4 / Employee 5. RegisteredPhoneSource returns null for other employees; simply adding a user or copying the APK does not enable a second tester. A scoped, approved second-tester identity/phone-source configuration or implementation is required first. Do not borrow Técnico Demo, falsify employee provenance or broaden the production trust policy to bypass this gate.

## Git and security review

Branch: phase/14-biometric-engine-selection. HEAD: 702b641ef803793d025903435b81a26fc1f48a0c. Index remains unchanged/empty. Proposed commit: `feat(beta): finalize internal beta build 5`. Proposed tag: `internal-beta/1.0.1-beta.1-build.5`. Neither exists as an action from this closeout.

The exact per-file authorization inventory is [checkpoint-files-13.7.3.md](checkpoint-files-13.7.3.md). It classifies every modified/untracked file and separates source, historical documentation, test infrastructure and local-only files. Six biometric Phase 14 documents are preserved but deferred from the beta checkpoint; no implementation of Phase 14 is included. No git add -A or staging was used.

Security review: candidate file types, credential/private-key/bearer patterns and blind matching against current local secret values found no live secret in proposed files. Pattern hits were synthetic test tokens, synthetic phone fixtures and a deliberately invalid redacted private-key marker; no actual private key was found. This scoped scan is not a guarantee against all possible secrets. APKs, DB backups, SQLite, keystores, local credentials/configuration, CA/server private keys, runtime evidence/photos and temporary recovery scripts are excluded. Private evidence and input hashes reside under storage/app/private/phase-13.7.3, outside Git.

COMMIT: NOT_CREATED. TAG: NOT_CREATED. SECURITY: PASS within the stated scope. Ready for review of the exact checkpoint inventory; no second Android or real pilot starts automatically.
