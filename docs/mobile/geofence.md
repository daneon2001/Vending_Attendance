# Mobile geofence validation

The TypeScript implementation mirrors Laravel's circular Haversine policy with Earth radius
6,371,008.8 metres. Inputs are latitude/longitude degrees and accuracy, radius and tolerance metres.

`distance` is Haversine distance. `effectiveRadius = radius + tolerance`,
`minimumDistance = max(0, distance - accuracy)`, and `maximumDistance = distance + accuracy`.

- `UNCERTAIN` when reported accuracy exceeds `minimum_acceptable_accuracy_m`;
- `INSIDE` when `maximumDistance <= effectiveRadius`;
- `OUTSIDE` when `minimumDistance > effectiveRadius`;
- otherwise `UNCERTAIN` because the accuracy interval overlaps the boundary.

Coordinates outside legal ranges, negative accuracy/radius/tolerance, and the sentinel coordinate
0,0 are rejected. `UNCERTAIN` is not converted to `OUTSIDE`. Shared fixtures live at
`tests/Fixtures/vending-geofence-validation.json`; both runtimes can consume this neutral fixture.
Mobile evaluation is evidence only: Laravel independently recalculates against the historical
geofence version submitted with the event.
