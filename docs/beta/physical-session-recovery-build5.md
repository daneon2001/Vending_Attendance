# Phase 13.7.2.1 physical recovery — build 5

Recovery and restart: PASS. READY_TO_RESUME_BETA_CLOSEOUT; closeout itself not executed.

HONOR AX3C026107002120 runs 1.0.1-beta.1 / versionCode 5 at identity origin https://192.168.1.82:8443. The exact authorized APK was installed with adb install -r (SHA256 8c990e317f667732886603d4543ffc82d14cde667e6096254a3c93754d4aec31). SQLite and SecureStorage retained all four pre-install file hashes. HTTPS certificate/hostname validation passed. Pre-login displayed consultation/login, not registration.

The user entered credentials privately and reported successful fresh web and APK login. Database evidence confirms User 4 / Employee 5, and ACTOR_PROVED events at 2026-09-14 04:40:24 and 04:43:22 UTC. Consumed challenges alone were not treated as proof success; ACTOR_PROVED is emitted only after registered-public-key signature verification.

An authorized force-stop/reopen was performed. Once the operator unlocked the phone, opening Mi dispositivo recovered without another login or registration. UI readback: Dispositivo autorizado; Técnico Demo; 990001005; Estado: activo; Identidad: Verificada. A fresh ACTOR challenge created 04:46:39 UTC was consumed 04:46:40 UTC, with a corresponding ACTOR_PROVED event. Restart recovery PASS.

Identity matches the pre-incident reference exactly: employee_device id 1; UUID a7807121-1079-4223-9fff-abe34043be6a; key version 1; fingerprint f799df6dcd23f05d40594608d29e64d19793c0e6cfd3bc1a4face52494c7a702; activated_at 2026-09-10 15:35:29; User 4; Employee 5; ACTIVE. Registered public key and other fields match in the full row comparison. Existing-key signature confirms continued use of the matching private key. No key regeneration, enrollment, replacement, duplicate device or OTP. No Android Keystore modification was performed.

## Exact baseline comparison

**12/14 complete table hashes match**, not 14/14. Read-only comparison with the verified isolated checkpoint identified exactly these differences:

- users.id=2: remember_token (web session state).
- employee_devices.id=1: last_seen_at and updated_at (successful ACTOR proof telemetry).

Excluding only those three named fields on those exact rows, **14/14 normalized datasets match**. All counts match. No password differs from the checkpoint; the earlier private reset helper was not completed. The pending helper's old-baseline guard prevents its reset after these changes.

Employees 2507; attendance 0; vending attendance events 22; support activities 2; FIELD_MOBILE count 1. SYBI 7 and assignment 7 preserved through exact table hashes. OTP table unchanged. Phone verification and binding ownership unchanged. ASISTENCIAS_FORTIA was not accessed or modified; no independent Fortia audit is claimed.

Evidence: storage/app/private/phase-13.7.2.1-incident/physical-build5-recovery-audit.json and physical-build5-final.json; storage/app/private/phase-13.7.2.1/build5-restart-labels.json and build5-install-prelogin-result.txt.

No full test suite, commit, tag, push or deploy. No credential capture or request-body logging. Do not describe the baseline as 14/14 raw hash equality; the observed session/telemetry differences remain recorded.
