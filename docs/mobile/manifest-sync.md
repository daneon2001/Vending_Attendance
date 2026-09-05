# Manifest synchronization

`EdgeSyncService` performs the following foreground sequence:

1. signed bootstrap derived from the authenticated Device;
2. signed manifest status poll;
3. configuration full snapshot when the server version differs;
4. employee full snapshot when the server version differs;
5. pending attendance batch delivery;
6. heartbeat and clock-drift update.

Each snapshot is committed atomically to SQLite before `POST /device/manifests/ack` is sent.
Therefore DOWNLOAD is not APPLIED. The ACK uses `CONFIGURATION` or `EMPLOYEES`, the exact server
version/hash, and local application time. A failed ACK remains visible as `ACK_FAILED` and is safely
retried through the version protocol. Employee snapshots are complete desired state: a missing
employee is removed locally. The client only lists assignments effective at its current time and still
preserves `valid_from`/`valid_until` for offline enforcement.

Biometric manifests are not downloaded or simulated; the server placeholder remains unsupported.
