# Geofence validation

## Initial policy

Phase 1 supports circular geofences only. All distances, radii, accuracy values, and tolerance values use metres. Coordinates use decimal WGS84 latitude/longitude.

Distance is calculated with the Haversine formula and mean Earth radius `6,371,008.8 m`:

```text
a = sin²(Δlat/2) + cos(lat1) × cos(lat2) × sin²(Δlon/2)
c = 2 × atan2(√a, √(1−a))
distance = 6,371,008.8 × c
```

This is appropriate for the short operational distances expected around vending machines and avoids treating address text as a position.

## Accuracy and tolerance

GPS `accuracy` is an uncertainty radius, not an exact correction. The configured decision boundary is:

```text
effective radius = radius + tolerance
minimum possible distance = max(0, distance − accuracy)
maximum possible distance = distance + accuracy
```

Decision order:

1. When `minimum_acceptable_accuracy_m` exists and reported accuracy exceeds it, result is `UNCERTAIN` (`ACCURACY_BELOW_REQUIREMENT`).
2. When maximum possible distance is inside or on the effective radius, result is `INSIDE` (`DEFINITELY_INSIDE`).
3. When minimum possible distance is outside the effective radius, result is `OUTSIDE` (`DEFINITELY_OUTSIDE`).
4. When the uncertainty interval overlaps the boundary, result is `UNCERTAIN` (`ACCURACY_OVERLAPS_BOUNDARY`).

The output includes raw calculated distance, conservative `effective_distance_m` (`distance + accuracy`), radius, accuracy, tolerance, result, and reason. Boundary equality is inside.

## Input safety

Latitude must be in `[-90, 90]`; longitude in `[-180, 180]`; radius must be positive; accuracy and tolerance cannot be negative. Operational validation rejects the `0,0` pair for both capture and geofence centre.

## Limitations

- GPS accuracy quality depends on the mobile platform and environment.
- Haversine does not model elevation, indoor multipath, or spoofing.
- `UNCERTAIN` needs a later product policy (retry, supervisor review, or alternative evidence); it must not silently create or reject attendance.
- Polygon validation is intentionally deferred. Attendance integration must consume the structured result so another shape strategy can be introduced without rewriting attendance.
