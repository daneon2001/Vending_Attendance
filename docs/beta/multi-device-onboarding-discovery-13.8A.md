# Phase 13.8A — Multi-device internal beta: discovery and proposal

Implementation update: Phase 13.8A was subsequently authorized and implemented as a build 6 candidate. See [implemented policy and operational gate](multi-tester-policy-13.8A.md) for the current behavior. The discovery below is retained as checkpoint evidence and design history, not a claim that implementation remains pending. No real tester B, policy entry, assignment or device has been created; no APK was installed. The final registry schema and fixed DEMO maintenance scope are defined in the implementation document; general web employee selectors remain unchanged.

## Verified baseline

Checkpoint ea5852d151151014214e660abfd3032414997d44; annotated tag internal-beta/1.0.1-beta.1-build.5. Existing APK 1.0.1-beta.1 / build 5 SHA256 8c990e317f667732886603d4543ffc82d14cde667e6096254a3c93754d4aec31 remains the validated artifact; no rebuild.

Read-only discovery confirms 2507 employees: 2502 MANUAL and 5 DEMO; zero FORTIA-source employees. The imported corporate pool exists, but import provenance is not equivalent to source=FORTIA. Only one user has an employee link. FIELD_MOBILE A remains the sole ACTIVE binding. No person in the corporate pool has been selected or declared beta-authorized by this discovery.

## A–H: current architecture

| Question | Finding |
| --- | --- |
| A. Employee selection | Backend resolves authenticated User.employee_id; profile returns that employee. No arbitrary request employee_id authority. |
| B. User↔Employee | users.employee_id is nullable, unique and foreign-key constrained. At most one linked User per Employee. Assignment is not mass assignable. |
| C. Phone source | RegisteredPhoneSource only returns the ignored .env.local DEVICE_DEMO_PHONE for the existing local-demo pair. Other employees return null. |
| D. LOCAL_SIMULATED | Local/testing-only identity service, local OTP provider, encrypted phone storage, hashed OTP; API always phoneVerified=false. |
| E. Single tester restriction | EnrollmentIdentity.isLocalDemo fixes User 4, Pilot Support, Employee 5, 990001005, DEMO. Field support has a separate similarly restricted exception. |
| F. Extension needed | An explicit private beta allowlist for approved User/Employee pairs, per-tester phone source and narrowly scoped field-support eligibility for approved imported MANUAL employees. |
| G. Multiple ACTIVE devices | Supported across different Employees. UUID, fingerprint and operation UUID are unique; active_employee_id is unique, not globally fixed to Employee 5. |
| H. Same Employee | At most one ACTIVE FIELD_MOBILE. Historical PENDING/REVOKED/REPLACED rows may coexist. Explicit replacement needs a new enrollment and retires the old active binding after proof. |

Recommended cardinality: Employee → 0..1 ACTIVE FIELD_MOBILE, 1:N historical bindings. Preserve current constraints and replacement policy. Each Android uses its own UUID and ECDSA P-256 key in its own Android Keystore; backend receives only public key. No copying SQLite, SecureStorage, alias material, UUID, key or employee_device ID.

## Phone-source audit

employees, employee_details and users have no phone/telefono/celular/mobile columns in the current schema. EmployeeExcelImportService maps spreadsheet TELEFONO into employee_import_metadata.payload.telefono; it is import metadata, not a verified phone authority. Read-only inspection found **zero populated phone entries** and no phone-verification/provenance keys in the current metadata. No phone numbers or employee lists were printed or exported. No Fortia database, sync or original corporate spreadsheet was accessed.

RegisteredPhoneSource is intentionally an unimplemented corporate integration seam outside the exact DEMO exception. Numeric identifiers such as cla_trab, employee_number, postal codes or social-security identifiers must never be interpreted as phone numbers. No available trusted productive phone source was demonstrated.

## Tester B requirements

SECOND TESTER: NOT_SELECTED. USER: NOT_SELECTED. Operator must identify the actual consenting tester and existing corporate employee by stable employee ID/number, confirm employment/active state and beta authorization. Preserve MANUAL source and import history; never relabel as FORTIA or create a fake Employee to bypass a guard.

Check for an existing linked User after selection. If absent, request approval for exactly one named account with its real approved email, active state, explicit link to that Employee, minimal existing compatible role or separately approved limited role, and privately chosen password. Do not reuse Pilot Admin or Pilot Support. No user, link or phone entry is created in this phase.

## Phone-policy options and recommendation

| Option | Assessment |
| --- | --- |
| A. DEVICE_DEMO_PHONE_<EMPLOYEE> | Private and simple, but awkward multi-tester validation, expiry and provenance; variable names alone do not enforce User/Employee binding. |
| B. Private local beta registry | Recommended for this internal gate: explicit typed entries with User/Employee identity, expiry, approval reference and individual phone, fail-closed validation, no database migration required. |
| C. Admin panel entry | Useful later but requires new RBAC, secret-safe audit/edit flow, storage encryption and controls; unnecessary UI expansion for this gate. |
| D. Corporate phone connector | Preferred before a real pilot, unavailable/unapproved today; no automatic Fortia reads or writes. |

Proposed private path: storage/app/private/beta-onboarding/testers.json (not created). Each entry: user_id, employee_id, expected employee_number/source, enabled, approved_by/reference, approved_at, expires_at, phone, phone_source=BETA_LOCAL_OPERATOR, method=LOCAL_SIMULATED, allowed machine and activity types. Actual phone is private, not present in example files, output, request logs or exception text. Secure filesystem ACL; structural validation rejects duplicate/conflicting bindings and malformed or missing phones. Normalize valid individual Mexican numbers using existing MexicanPhone. Configuration is server-owned and not writable by an enrollment payload.

One reusable policy validates active User, exact persisted link, active Employee, approved identity/source, expiry, local environment and machine/activity scope on every applicable operation. Unlisted, expired, mismatched or missing entries deny access. Testing injects synthetic entries and never reads real local files. Production ignores this registry and retains the current failure behavior. Changing B's phone/config after OTP invalidates that pending proof rather than adopting another identity.

Keep A's existing exact DEMO exception and current phone unchanged through a compatibility path; do not migrate A's binding, key or phone. B and future approved testers use individual registry entries, not the single global DEVICE_DEMO_PHONE. No entry for B is populated until separate tester authorization.

## OTP and cryptographic isolation

Existing OTP rows are keyed by user_id, include employee_id and unique otp_uuid, phone ciphertext, hashed code, TTL, attempt/cooldown counters and consumed state. verifyOtp checks current User, Employee, otp_uuid and current configured phone; register consumes the same user's verified OTP under locking. Concurrent A and B sessions do not share a row.

Current OTP is an enrollment authorization per User/Employee/otp_uuid; it is not tied to a physical UUID at issuance because the device/key does not yet exist. It authorizes one successful registration; registration then binds the chosen unique UUID/public key and consumes it. Six-digit codes alone are not globally unique: security depends on owner plus otp_uuid and consumption, not the numeric code being different for every tester. Do not claim stronger physical-device OTP binding than implemented.

MULTI-TESTER OTP ISOLATION: REQUIRES_CHANGE for the end-to-end multi-tester gate (enable the scoped policy/phone provider and add explicit dual-user tests). Existing owner/UUID/consumption checks are retained; no shared OTP mechanism or blanket bypass is proposed. phoneVerified remains false, including after local OTP success. Existing phone_verified_at denotes the simulated event, not productive telephone possession.

Existing signature checks bind purpose, challenge UUID, device UUID, fingerprint, owner and stored public key, reject expired/consumed challenges and require the appropriate device status. Keep existing unique key fingerprint and UUID constraints and nonexportable Keystore behavior.

## RBAC, activities and machine

Mi dispositivo requires a valid human identity/session and does not require global admin permissions. Proposed tester role scope: support.view for own activity list, support.resolve for MAINTENANCE/REPAIR/COMPONENT_REPLACEMENT; add support.report and support.comment only if reporting/commenting is in the approved test. No users/settings/employee_device.manage, support.view_all, support.assign or support.manage for tester B.

DIAGNOSTIC requires support.verify. CONFIGURATION, INSTALLATION, CONNECTIVITY and SOFTWARE_UPDATE require support.configure. These are distinct business permissions; do not grant them automatically. Default second-phone test should match the approved maintenance scope.

Assignment proposal: existing VM-DEMO-001 (machine 1), tester B's existing Employee, active/effective assignment for the approved time window, maintenance_allowed=true only for maintenance tests; attendance_allowed=false and enrollment_allowed=false unless separately authorized. The latter is terminal attendance-enrollment capability, not permission to create a FIELD_MOBILE key. No new assignment or activity now. No SYBI 7 use.

Both identity and machine eligibility for imported MANUAL employees must be admitted only by the beta policy in the native field-support adapter. Keep User::authenticatedEmployee and general productive/Fortia eligibility unchanged. FieldSupportActivityAccess.identity/eligibleEmployee/visible/notificationScope and administrator activity employee selection must agree; extending only RegisteredPhoneSource would leave Mis actividades blocked. SupportActivityWebQueries currently filters productive employee choices to FORTIA and has a fixed DEMO path; any beta operator selection must apply the same explicit approval scope without changing global access.

Additional UI limitation: HomePage's Reportar incidencia is gated by terminalActive, not personal FIELD_MOBILE. A clean personal-only Android B must not receive cloned terminal credentials to expose it. Initial B scope should be Mi dispositivo/Mis actividades. If personal ticket creation from the APK is mandatory, it needs a separately approved native personal reporting flow; granting support.report alone will not expose that button. Web reporting remains subject to its own permissions.

DEMO geofence machine 1/version 2 can be reused only when B is physically at that location. No relocation or per-tester geofence. START_ONLY_V1 remains explicit; no new promise of continuous or completion-time location checks.

## Admin and per-phone diagnostics

DeviceAdminController already queries all employee_devices with Employee, paginated independently of the logged-in Employee, showing model, state, masked phone, local simulation, phoneVerified=false, last_seen_at and crypto_verified. It can display A and B as separate rows; no public/private key material in output. ADMIN MULTI-DEVICE: READY by code review, not a new physical two-row test. Add an isolated two-employee/two-device regression assertion. crypto_verified reflects verified_at, not continuous liveness; last_seen_at is separate. Biometry remains pending.

Diagnostics read App metadata, network and permissions locally, and select the authenticated profile's device using the local verified identity. Pending operations use origin/session/device-scoped SQLite context. Do not infer zero for an unprepared scope. Validate B's own metadata, identity, pending count and last sync; compare A remains intact.

## Clean Android, transport and target flow

A clean second Android means no prior app installation/data restore, Vending SQLite, SecureStorage or project Keystore alias, and no prior binding for that tester. It does not require factory reset. If prior artifacts exist, stop and investigate; no uninstall/clear without explicit authorization.

Compatible LAN; HTTPS https://192.168.1.82:8443; valid server certificate and operator-reviewed **public** Vending Local Demo CA only. Never transfer CA/server private keys, Android keystores or application data. Confirm CA/server validity at the future physical gate; certificates are time-limited. No HTTP/TLS bypass.

Target after approvals: public CA → exact authorized APK → User B login → persisted User/Employee resolution → private authorized phone → own LOCAL_SIMULATED OTP → fresh UUID and P-256 key generated locally → public-key registration → enrollment challenge/signature → ACTIVE → restart → fresh ACTOR challenge/signature → admin shows independent B. Compare A/B IDs, UUIDs, fingerprints and bindings, and preserve A throughout.

Build 5 remains immutable and can be distributed only under its original scope. This proposal needs server-side code; it cannot be represented as already implemented by build 5. Under the requested artifact rule, subsequent implementation validation must prepare a new explicitly identified candidate/build (proposed build 6) and request physical installation approval, even if Android behavior itself needs no change. Do not relabel or overwrite build 5.

## Implementation proposal — not executed

| File/component | Proposed change |
| --- | --- |
| New FieldIdentity/LocalBetaTesterRegistry.php | Read private structured registry, validate schema, no logging of values; injectable fixture source in tests |
| New FieldIdentity/BetaTesterPolicy.php | Exact User/Employee/source/expiry/environment and per-machine/type authorization; no implicit corporate-pool allow |
| EnrollmentIdentity.php | Admit explicitly approved imported MANUAL tester via policy; preserve FORTIA path and A's exact compatibility exception |
| RegisteredPhoneSource.php | Resolve per-approved-tester local phone; preserve A; deny unspecified/corporate fallback |
| FieldSupportActivityAccess.php | Scoped identity, eligibility, visibility and notification admission using the same policy |
| SupportActivityWebQueries.php and related activity selection boundary if required | Admit only operator-authorized beta employee candidates for approved machine/type; preserve strict productive selection |
| Config/schema example | Version only a redacted/empty schema and feature flag/path; actual registry remains private; no phone/User B entry |
| Models/migrations | No new persistent model/table, cardinality or employee source change proposed |
| Tests | Dual-user OTP/key/device isolation, missing/expired/conflicting config, unsupported environment, MANUAL allowlist, preserved A, admin list and scoped support visibility |

No generic admin UI or personal ticket API is included in this minimal proposal. Those require separate scope if requested.

## Test plan and gates

Use approved hardening and SQLite :memory: fixtures. Disposable MySQL only if separately needed/approved for uniqueness/concurrency; never run legacy harnesses or tests against vending_attendance_dev. No tests executed at this discovery stage.

1. Two distinct fixture users/employees/phones enroll concurrently and retain separate UUID/key/fingerprint/device rows.
2. A's otp_uuid/code fails for B and vice versa; missing/wrong/expired/reused OTP fails; consuming A does not mutate B.
3. User A cannot adopt/prove/revoke Device B; B cannot adopt A; payload employee_id cannot override authenticated linkage.
4. Key A cannot verify challenge B; swapped device/challenge/purpose, replay and missing native key fail.
5. Duplicate UUID/fingerprint denied; second ACTIVE for one Employee denied without explicit replacement; distinct Employees both ACTIVE.
6. Missing phone, unlisted/inactive/expired tester, mismatched User link/source and production environment fail closed.
7. A's existing phone/binding/key/activation remains unchanged; repeated recovery uses existing Keystore, no new OTP/key.
8. Admin can display both rows without key material; B cannot see/use A's activity or offline context.
9. Approved machine/type/assignment enforced; unapproved machine/SYBI 7 denied; attendance remains disabled; no global RBAC expansion.
10. Phone/config changes invalidate pending OTP; simulated verification never becomes phoneVerified=true.

Stop after discovery. Implementation requires explicit approval of this file/config/test scope (user section 28). Tester selection and all real data/physical actions require later authorization. No commit, tag, push or deploy.
