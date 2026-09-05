# Device provisioning runbook

## Preconditions

- Machine identity, ACTIVE status, verified coordinates and active geofence are confirmed.
- The employee manifest contains only intended assignments.
- Device is factory/reset for this application, connected to the approved network and running the signed PILOT release.
- Support can identify the physical serial without treating it as a secret.

## Procedure

1. Open the machine detail as an authorized vending administrator.
2. Select **Generate Provisioning Token**. Use the default short expiry unless the change record approves another value.
3. Transfer the one-time token directly to the installer. Do not place it in email, logs, screenshots or tickets.
4. Enter it once in the device. The server must return a Device UUID and consume the token.
5. Hide the displayed token in the admin page. It cannot be recovered later.
6. In Device Registry confirm: lifecycle `ACTIVE`, expected machine, platform/version, recent heartbeat, configuration `SYNCED`, employees `SYNCED`, geofence `READY`, outbox zero.
7. Capture one controlled non-biometric attendance and verify latest attendance server-side.

## Failure handling

- Expired/revoked/used token: revoke if still visible as available, create a new token and retry once.
- Serial bound to another machine: stop; do not override. Escalate as an identity conflict.
- Device created but sync fails: do not reprovision or clear storage. Use the offline recovery runbook.
- Suspected token exposure before use: revoke immediately and create another token.

Record machine code, Device UUID, serial, release version, installer, timestamps and result. Never record the token or credential.
