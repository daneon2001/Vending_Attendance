# Vending demo seeder

`Database\Seeders\VendingDemoSeeder` creates a small, recognizable dataset for local UI validation. It is not called by `DatabaseSeeder` and refuses to run outside `local` or `testing`.

## Run

When the database has no administrator with all vending permissions, define a password only in the ignored local `.env`:

```dotenv
VENDING_DEMO_ADMIN_PASSWORD=<local-only value>
```

The seeder never prints that value. Run it explicitly:

```bash
php artisan db:seed --class=VendingDemoSeeder
```

Subsequent executions reuse the deterministic identities and do not duplicate machines, employees, assignments, geofences, devices, manifest states or attendance events.

## Dataset

Machines:

- `VM-DEMO-001`: ACTIVE, CDMX, active 50 m geofence, synced active Device.
- `VM-DEMO-002`: ACTIVE, Naucalpan, active 75 m geofence, pending Device.
- `VM-DEMO-003`: MAINTENANCE, Toluca, draft 60 m geofence, no Device.

The five requested employee aliases map to a reserved numeric range because the inherited `employees.fortia_employee_id` column is an unsigned integer:

| Demo alias | Stored employee number | Name |
| --- | ---: | --- |
| `DEMO1001` | `990001001` | Empleado Demo Uno |
| `DEMO1002` | `990001002` | Empleado Demo Dos |
| `DEMO1003` | `990001003` | Empleado Demo Tres |
| `DEMO1004` | `990001004` | Supervisor Demo |
| `DEMO1005` | `990001005` | Técnico Demo |

No CURP, RFC, NSS, company email, fingerprint, face template or real personal data is created.

Assignments include PRIMARY, SUPERVISOR, TEMPORARY, TECHNICIAN and a revoked historical SUBSTITUTE. Permissions are explicit. Five attendance events cover INSIDE, UNCERTAIN, OUTSIDE, delayed synchronization and a three-day offline event.

If no suitable administrator exists, the seeder creates `admin.vending.local@example.test`, verifies the local account and grants only the vending module permissions through the `Vending Demo Admin` role. Its password comes exclusively from `VENDING_DEMO_ADMIN_PASSWORD`.

## Cleanup

Remove only deterministic demo records with:

```bash
php artisan vending:demo-cleanup
```

For non-interactive local cleanup:

```bash
php artisan vending:demo-cleanup --force
```

The command is also restricted to `local` and `testing`. It removes the demo attendance evidence first, followed by metrics, manifest state, Devices, assignments, geofences, machines, employees and the exact demo administrator/role. It does not use `migrate:fresh`, truncate shared tables or delete non-demo rows.

## Safety boundaries

- No Fortia or SYBI request is performed.
- No Device credential or administrator password is printed.
- No production execution is allowed.
- All UUIDs, machine codes, serials and attendance event UUIDs are deterministic and visibly reserved for DEMO.
- `VendingDemoSeeder` remains opt-in and is not part of the default seed chain.
