# Attendance geofence evidence

## Location evidence

Location is optional during this policy-discovery phase. Its typed status is:

- `VALID`: bounded coordinates and non-negative accuracy;
- `MISSING`: the edge supplied no location object;
- `INVALID`: out-of-range coordinates, negative accuracy or operationally invalid `0,0`;
- `LOW_ACCURACY`: valid coordinates whose accuracy exceeds the referenced geofence requirement.

Invalid or missing evidence is never replaced with machine coordinates. It produces `NOT_EVALUATED`, not `OUTSIDE`.

## Independent server evaluation

The server preserves `edge_geofence_result` and independently calls the existing Haversine-based `GeofenceValidationService`. Server outcomes are `INSIDE`, `OUTSIDE`, `UNCERTAIN` or `NOT_EVALUATED`. Low GPS accuracy remains `UNCERTAIN`; it is never coerced to `OUTSIDE`.

The lookup uses `(vending_machine_id, geofence_version)` from the authenticated Device machine. It does not automatically substitute today's ACTIVE geofence. A delayed version-4 event is evaluated against retained version 4 even if version 5 is active. Unknown/missing versions remain stored with `NOT_EVALUATED` and a warning.

## Mismatch policy

When both results exist and differ, `geofence_discrepancy` is true, `attendance.geofence_mismatch` is audited with only identifiers/results, and the mismatch metric increments. Phase 4 measures discrepancies but does not automatically reject them. Authorization and geofence classification remain separate evidence dimensions.
