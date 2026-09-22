# Phase 13.8C-S — partial validation, 2026-09-14

The operator manually authenticated Tester B (User 6, Employee 12 / 10083) on the existing HONOR A running build 8. The UI showed B's employee profile, a blocked registration state, and the warning that no identity would be replaced automatically. It did not show A as B's active device. No OTP, enrollment, B key generation or support operation was initiated.

Read-only backend verification confirmed B's profile contained no devices. The existing shared ownership lookup returned no Device A for B; the Field Support device guard returned HTTP 403. These checks exercised lookup/authorization methods directly, not mutating HTTP endpoints. Proof and revocation ownership conditions were reviewed in source; no real revocation or proof mutation was attempted as B. A SQL pre-execution guard rejected every non-SELECT statement and the transaction was rolled back. An initial READ ONLY transaction rejected the guards' SELECT FOR UPDATE; it did not perform a mutation.

B retained exactly support.view and support.resolve, the enabled unexpired private beta policy, phoneVerified=false, and assignment 9 with maintenance allowed and attendance/enrollment disallowed. B's human session was closed; its live human session count became zero.

The operator then manually authenticated Pilot Support. Backend ACTOR_PROVED at 17:30:28 UTC confirmed recovery of A. After force-stop, normal reopening and opening Mi dispositivo, ACTOR_PROVED at 17:31:21 UTC confirmed another fresh proof. The UI showed Dispositivo autorizado, Estado activo and Identidad verificada without another login, OTP or registration.

Device A remained id 1, User 4 / Employee 5, key version 1, fingerprint prefix f799df6dcd23, activated_at 2026-09-10 15:35:29. Its full-row hash matched the checkpoint after normalizing only last_seen_at and updated_at in memory. Device count stayed 1. B OTP count stayed 0. Challenge count increased 58 to 60 for the two authorized A recoveries.

Final counts: employees 2507, users 6, attendance 0, vending attendance events 22, support activities 2. Hashes matched for activities/events/notes/evidence/tickets, employees, machines, assignments, geofences, OTPs and RBAC. Existing users other than B matched the pre-provision backup exactly.

## Unresolved baseline evidence

The users-table hash does not match phase13.8C-before.json (checkpoint 16:57:06 UTC). Normalizing B's remember_token to null and updated_at to created_at in memory also did not reproduce that hash. The historical checkpoint contains a table hash rather than per-field historical values for B. This does not establish corruption or that the difference was caused by this physical test. It does prevent asserting an entirely unchanged semantic user baseline without further evidence. No corrective database write was made.

PHASE 13.8C-S: PARTIAL. Physical login isolation and A recovery passed; final baseline reconciliation remains unresolved. MULTI_DEVICE_PHYSICAL: DEFERRED_SECOND_ANDROID. No production-code change, full test suite, commit, tag, push or deployment.
