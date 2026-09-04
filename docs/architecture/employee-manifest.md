# Employee manifest

## Authorization scope

An entry is included only when all of these conditions are true at generation server time:

- assignment belongs to the authenticated device's vending machine;
- assignment status is `ACTIVE`;
- `revoked_at` is null;
- `valid_from <= server_time`;
- `valid_until` is null or `valid_until >= server_time`;
- at least one of attendance, enrollment, or maintenance permission is true;
- the projected Employee has an active operational status (`A`, `ACTIVE`, or `active`).

Future, expired, revoked, inactive, permissionless, and other-machine assignments are excluded. Historical Employee and assignment rows are preserved centrally.

The service accepts an explicit timestamp internally for deterministic testing and future scheduled-prefetch design. Public Phase 3 endpoints always generate the current effective snapshot using server time and do not prefetch future assignments.

## Included data

One manifest entry represents one effective assignment grant and contains:

- opaque central `employee_id`;
- operational `employee_number` from the existing Fortia projection;
- operational display `name`;
- assignment UUID and type;
- `valid_from` and nullable `valid_until` in UTC;
- explicit attendance, enrollment, and maintenance permissions.

Multiple simultaneous grants for one employee remain distinct entries so their permission and validity provenance is not lost.

## Excluded data

The query selects no address, phone, email, CURP, RFC, NSS/IMSS number, salary, organization/location fields, branch scope, Fortia metadata, face state, fingerprint state, or biometric template. The manifest serializer uses an explicit allow-list; it never serializes the Employee model wholesale.

## Removal and offline validity

The `employees` array is complete desired state. Anything missing from a newly applied version must be removed from the local authorization cache. Even while offline, `valid_from` and `valid_until` remain authoritative; a client must not continue authorization beyond `valid_until` merely because the assignment existed in the last snapshot.
