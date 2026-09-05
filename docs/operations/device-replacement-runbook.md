# Device replacement runbook

## Expected invariant

The VendingMachine keeps its identity. Device A and its attendance history remain stored; provisioning Device B retires A, revokes A's credential and makes B the only ACTIVE device.

## Procedure

1. Confirm Device A, machine code, last attendance and pending-events count in Device Registry.
2. If A can still connect, wait for outbox zero. If it cannot, preserve it for forensic recovery; never clear app data.
3. Generate a new one-time provisioning token for the same machine.
4. Provision B using its own serial and the signed PILOT release.
5. Verify A=`RETIRED`, B=`ACTIVE`, and exactly one ACTIVE device for the machine.
6. Verify B bootstrap, both manifests `SYNCED`, active geofence/version and heartbeat.
7. Submit a controlled attendance from B and confirm it is associated with B and the same machine.
8. Confirm historical attendance from A remains queryable.

## Rollback

A retired credential cannot be reused. If B fails, preserve both records, revoke/retire B as appropriate and provision repaired/new hardware C with a new token. Escalate any unsynchronized evidence on A before disposing of hardware.
