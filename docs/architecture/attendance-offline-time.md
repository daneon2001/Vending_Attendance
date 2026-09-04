# Attendance offline time

## Independent clocks

`captured_at_device` preserves the UTC-normalized instant supplied by edge. `received_at_server` is assigned independently by Laravel. `sync_delay_seconds = received - captured`; it may be negative inside the configured future tolerance. Device heartbeat drift is copied to `device_clock_drift_seconds` as context and never silently rewrites capture evidence.

## Acceptance window

There is no short offline TTL. Events hours or days old remain eligible for storage and event-time evaluation. Dates before `VENDING_ATTENDANCE_MINIMUM_CAPTURED_YEAR` (default 2000) and dates more than `VENDING_ATTENDANCE_FUTURE_TOLERANCE_SECONDS` (default 300 seconds) in the future are rejected as `INVALID_TIMESTAMP`.

## Event-time state

Assignment validity and revocation timestamps are compared with server-interpreted capture time. Current employee/machine/permission state is not assumed to be historical truth. If current persisted data proves denial at event time, the result is `DENIED`; if a later update may have changed that state and no historical version exists, it is `UNVERIFIABLE`.

Reported configuration and employee manifest versions are stored and classified as `CURRENT`, `STALE` or `UNKNOWN`. A lower reported version is normal offline evidence, not an automatic rejection. The receiver does not use Device time to select a different server-side machine identity.
