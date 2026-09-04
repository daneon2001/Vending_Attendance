# Manifest acknowledgement and observability

## ACK contract

`POST /api/v1/device/manifests/ack` accepts:

```json
{
  "manifest_type": "EMPLOYEES",
  "manifest_version": 27,
  "manifest_hash": "sha256-hex",
  "applied_at": "2026-09-04T18:30:00Z",
  "status": "APPLIED",
  "error_code": null,
  "error_message": null
}
```

`CONFIGURATION` and `EMPLOYEES` are supported. `BIOMETRICS` is rejected as unsupported. Error code/message are optional for `FAILED`; messages are stripped of markup, whitespace-normalized, and length-limited before persistence. Audit metadata records the code but not the message.

## State persistence

`device_manifest_states` provides one row per Device/type with applied version/hash, last ACK version/hash/status, server receipt time, reported application time, and sanitized last error. Existing `Device.config_version_applied` and `employee_manifest_version_applied` are maintained as compatibility mirrors.

The separate table avoids adding type-specific error/hash columns for each future manifest while retaining the Phase 2 Device columns. Biometric rows may exist as empty placeholders but have no server version or supported protocol.

## Idempotency and validation

- An identical successful ACK returns `duplicate: true` without a second version change or audit event.
- A valid `FAILED` ACK records ERROR but never advances applied version.
- A valid later `APPLIED` ACK clears the error and advances applied version.
- Version/hash must match current desired state before application is accepted.
- A hash/future-version mismatch returns HTTP 422, records ERROR, and emits `manifest.hash_mismatch`.
- An ACK older than server or already-applied state is ignored, cannot decrement applied version, returns `stale: true`, and emits `manifest.stale_ack`.

Concurrent ACKs serialize on the Device/state locks. Applied version is always written as the maximum accepted version.

## Observable sync state

For configuration and employees, status/admin expose server version/hash, applied version/hash, changed flag, and state. Overall state precedence is `ERROR`, `STALE`, `PENDING`, then `SYNCED`:

- `SYNCED`: supported server and applied versions match and no error/staleness exists;
- `PENDING`: server differs from applied, including a never-ACKed manifest;
- `STALE`: last seen exceeds `VENDING_MANIFEST_STALE_AFTER_MINUTES` (default 10) or version lag reaches `VENDING_MANIFEST_STALE_VERSION_LAG` (default 3);
- `ERROR`: latest ACK for a supported type is `FAILED`.

Thresholds are explicit environment configuration. Successful polling without change is not audited. Only version changes, failed ACKs, hash mismatches, and stale ACKs create manifest audit events.
