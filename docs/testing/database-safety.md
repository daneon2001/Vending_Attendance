# Test database safety ? Phase 13.7.2.0

## Incident and scope

On 2026-09-13, the agent ran `php artisan test tests/Feature/FieldIdentity` while `bootstrap/cache/config.php` fixed local/mysql/vending_attendance_dev. Environment overrides did not replace cached configuration. RefreshDatabase executed migrate:fresh before the assertions after parent::setUp and before its transaction. Recovery restored the verified backup; the historical protected comparator has 14 datasets, not 15. See [reference reconciliation](../beta/incident-reference-reconciliation-20260913.md).

## Defense layers

- `tests/bootstrap.php` runs before PHPUnit discovery; `artisan test` prepares the same environment before Laravel. `Tests/TestCase::createApplication` independently prepares it before calling its parent.
- Preboot selects testing, SQLite memory by default, HTTP localhost as a deterministic synthetic request base, array cache/session and a process-specific absent config-cache path. A preexisting normal or requested cache aborts without including it. It is never silently deleted. A relative cache path is intentional: this Laravel version does not treat Windows drive prefixes as absolute by default.
- `bootstrap/app.php` attaches a LoadConfiguration completion guard, before RegisterProviders and therefore before RefreshDatabase, DatabaseMigrations and transactions. It validates effective values rather than desired env variables. Cached configuration is rejected. PHPUnit detection also covers application boot outside the shared TestCase.
- `SafeDatabaseManager` validates every requested connection, including custom connection extensions and previously resolved connections, before resolution; `SafeConnectionFactory` validates before PDO creation. Secondary aliases cannot bypass policy. Connection guards retain the validated boot environment while revalidating each target; tests may simulate local/production business configuration after safe boot without granting access to another database. Destructive command guards still require effective testing configuration. TestCase restores its validated configuration before trait rollback. Testing Fortia fixtures are independent SQLite memory connections, never the external databases.
- Dedicated wrappers for fresh/refresh/wipe/reset/rollback validate the requested --database before the parent handler, including nested Artisan::call calls. A command-start listener is additional defense, not the only gate. These destructive commands are denied outside testing as well; ordinary serving, read-only commands and normal migrations are not replaced.
- `phpunit.xml`, composer test/test:safety and `.env.testing.example` specify the local/CI entry points. No CI workflow existed in the repository. Pest is not installed; if added, it must use this PHPUnit bootstrap and TestCase.

## Allowed / denied

SQLite: only `:memory:`. Disk SQLite is rejected.

MySQL: only loopback host 127.0.0.1 or localhost and exact `vending_attendance_test_[a-f0-9]{16}`. No URL override, read/write split or socket override. Random database names cannot be shared by unrelated workers. Names merely containing test/testing are not sufficient; persistent vending_attendance_testing is deliberately not accepted by this stricter disposable policy.

Explicit deny includes vending_attendance_dev, vending_attendance, vending_attendance_pilot, ASISTENCIAS_FORTIA, fortia, fortia_mock and fortia_fake. All other unapproved names/drivers also fail closed. Errors use TEST_DATABASE_SAFETY_BLOCKED, safe effective environment/driver/database fields and a reason; arbitrary strings/URLs are redacted.

## Safe execution and gates

Run from repository root:

```powershell
php vendor/bin/phpunit tests/Unit/Testing
php vendor/bin/phpunit tests/Feature/Testing
php tests/Support/disposable_mysql.php
php vendor/bin/phpunit tests/Feature/FieldIdentity
php vendor/bin/phpunit tests/Feature/Support
php artisan test
```

Advance only after the previous gate passes. Before and after tests compare the 14 protected full-row hashes with the same historical JSON/lexicographic algorithm. Do not test by running any destructive command on the real database; sentinel configurations use isolated app containers and connection tripwires.

On the installed Windows PHP build, crypto tests need OPENSSL_CONF pointing to that PHP installation's extras/ssl/openssl.cnf. This is not a key file and contains no app credentials. Do not print `.env`. Do not rebuild local config cache for tests.

`migrate:refresh` safety is tested with a small reversible fixture. A preexisting SYBI migration down path fails under SQLite by dropping an indexed source column before its index. That unrelated migration was not edited and a successful safety gate does not certify rollback of every historical migration. All 105 up migrations are exercised in memory and disposable MySQL.

## Disposable MySQL lifecycle

`Tests/Support/DisposableMysql` takes local credentials in memory, validates a generated name before opening a server-only admin connection, CREATEs without IF NOT EXISTS (never adopts another database), and closes with DROP of that exact owned name and a residual check. It never selects vending_attendance_dev. The standalone harness and refactored integration concurrency test close in finally/teardown, including migration failure. No MySQL user or permission is changed.

The four legacy standalone scripts field_identity_mysql.php, field_support_mysql.php, support_mysql_concurrency.php and field_contributions_mysql.php previously booted against local configuration. They now abort before their original bootstrap, with an explicit message. Their old bodies are retained for a future reviewed port; they are not counted as successful coverage. Use the new disposable migration gate and SQLite FieldIdentity/Support suites. The Integration concurrency test was converted to the shared owner/cleanup lifecycle; its worker uses tests/bootstrap.php.

Parallel SQLite uses independent per-process memory and cache paths. Two simultaneous subprocesses prove isolation. ParaTest is absent, so actual `php artisan test --parallel` execution is deferred; no package was installed. MySQL parallel automatic provisioning is not enabled: generated/suffixed names outside the exact policy abort. Deliberate concurrency workers may share only their owning test's disposable database.

## Limits and evidence

These guards protect the supported repository runners, framework connections and destructive commands. They are not an OS/database privilege boundary against arbitrary PHP that deliberately bypasses the framework and uses privileged raw PDO. MySQL credentials limited to test schemas would provide an additional infrastructure boundary; this phase does not change global MySQL permissions. New raw PDO helpers require review and must use the owner-scoped disposable lifecycle.

Private evidence resides in storage/app/private/phase-13.7.2.1-incident/: hardening-baseline-before.json, hardening-baseline-after.json, hardening-schema-mutation-inventory.txt and hardening-phase-result.txt. The inventory includes direct schema operations as well as inherited RefreshDatabase consumers. No real phone, key material, credential, or full backup content is included in this document.

## Validation outcome

Final classification: **PASS_WITH_KNOWN_BASELINE_FAILURE**. See [the final classification and exact historical references](database-safety-final-classification.md). The previous provisional block is superseded by the authorized acceptance of the documented inherited OnPrem fixture failure.

Safety 9/9, FieldIdentity 51/51, Support 152/152 and disposable MySQL migration/cleanup passed. Full suite remains 789 passed / 1 known failure; it was not rerun during classification. A fresh read-only comparison confirms 14/14 identical protected datasets. ParaTest remains DEFERRED; four legacy MySQL helpers remain DISABLED_PENDING_PORT; the MySQL privilege boundary is NOT_IMPLEMENTED. Final status: READY_TO_RESUME_PHASE_13_7_2_1, without automatically resuming that phase.
