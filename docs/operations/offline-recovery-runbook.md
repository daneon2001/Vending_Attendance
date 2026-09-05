# Offline and client recovery runbook

## Symptoms

Offline/degraded health, old heartbeat, outbox count rising, manifest pending/stale, NETWORK error, or the mobile recovery screen.

## Safe recovery

1. Do not clear app storage, uninstall, reprovision or change device time.
2. Record Device UUID, machine code, last heartbeat, network state, outbox count, applied/server manifest versions, last error code, app version and free storage.
3. Verify Wi-Fi link, captive portal absence, DNS resolution, HTTPS certificate validity and reachability of the approved API origin.
4. Restore the approved network. The client retries full sync with bounded jittered backoff; bringing the app to foreground is an additional safe trigger.
5. Wait until outbox reaches zero and both manifests show `SYNCED`. Confirm last attendance arrived with captured and received times preserved.
6. For process death/force stop, reopen the app. Identity, SQLite cache, geofence, manifest state and pending outbox must return without provisioning.
7. For device reboot, unlock the device, reopen the app and perform the same checks. Secure Storage is device-only and requires the normal OS unlock state.

## Escalation

- `AUTHENTICATION_FAILED`: stop retries and escalate; never ask for the device credential.
- startup recovery screen: retry once, preserve hardware, then escalate as SQLite/storage incident.
- low storage: remove only unrelated OS/user files through the approved device policy; never manipulate the app database.
- rejected attendance: retain evidence and error code; do not edit SQLite.
- persistent DNS/TLS/server failure: open an incident and keep devices offline-capable.
