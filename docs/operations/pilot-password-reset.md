# Controlled pilot password reset

Status: implementation tested; **WAITING_FOR_RESET_APPROVAL**. The command has not been executed against the local pilot database.

## Explicit command

```console
php artisan vending:pilot-users --reset-passwords
```

Do not run until the operator approves the reset. Without --reset-passwords the command performs no writes and returns exit code 2.

Only APP_ENV=local/testing is accepted. Production, staging, pilot and all other environments are rejected. The raw APP_ENV variable, application environment and app.env configuration must all be local/testing. Neither Artisan --env nor the seeder's VENDING_PILOT_USERS_ALLOW_PRODUCTION flag can override production.

## Required variables

- VENDING_PILOT_ADMIN_PASSWORD
- VENDING_PILOT_OPERATOR_PASSWORD
- VENDING_PILOT_SUPPORT_PASSWORD
- VENDING_PILOT_VIEWER_PASSWORD

Values must be nonblank strings of at least 12 characters, consistent with the existing pilot seeder. Never put values in CLI arguments, documentation, logs or source control.

The command reads these exact current environment variables through Laravel Env, not values stored in the config cache. The existing config/employees.php mapping and seeder are unchanged. With configuration caching, Laravel does not load .env; supply variables securely in the command process environment, or refresh local configuration separately before the approved reset. Missing variables fail closed even when cached config contains an old password.

## Fixed targets and output

| Email | Existing role | Successful result |
|---|---|---|
| pilot.admin@example.test | Vending Pilot Admin | PASSWORD_RESET |
| pilot.operator@example.test | Vending Pilot Operator | PASSWORD_RESET |
| pilot.support@example.test | Vending Pilot Support | PASSWORD_RESET |
| pilot.viewer@example.test | Vending Pilot Viewer | PASSWORD_RESET |

The command prints exactly four tab-separated lines: email, role, PASSWORD_RESET, only after the transaction commits. Failures return exit code 1 with no output, rather than disclosing exception contents.

Emails and expected roles are a fixed allowlist, not configurable reset targets. All four accounts and their expected roles must exist before the first write. Missing or mismatched accounts abort; the command does not create users or repair roles.

Only the users.password column is updated using Laravel's configured password hasher. No changes to admin.vending.local@example.test or other users, timestamps, role_user, roles, permission_role or permissions. No seeder invocation, model update events, logging or audit writes. All four changes are transactional; output and successful status occur after commit.

## Tests and verification

PilotUserPasswordResetCommandTest uses an explicit testing + SQLite :memory: guard before migrations. It creates synthetic accounts and random test passwords, saves/restores test-only environment variables, and never resets the real local accounts.

Coverage: local/testing success; exact output with no plaintext or hashes; production even with the seeder override or forced application environment; cached production configuration; other forbidden environments; each missing variable even with stale configuration; blank/short values; explicit flag and no CLI password arguments/options; unrelated/demo accounts unchanged; RBAC unchanged; fixed targets despite altered config emails; missing account; unexpected role; rollback after the second database write with a secret-bearing exception; no logs/audit.

The existing VendingPilotUsersSeeder retains its original create/idempotency behavior and is not used to reset passwords. Its existing tests are included in targeted regression.

Validation on 2026-09-06:

- Targeted command and seeder regression: 23 passed, 129 assertions.
- Full Laravel suite: 462 passed, 1 inherited failure, 3600 assertions. The unchanged OnPremDiagnosticsCommandTest failure is documented in the approved baseline; no reset-related failure remains.
- Pint scoped: PASS for the two new PHP files.
- git diff --check: PASS; the new files were also checked for whitespace errors.
- No frontend changes; frontend build is not applicable to this command-only task.

Only three new files were added for this task: app/Console/Commands/VendingPilotUsersCommand.php, tests/Feature/Vending/PilotUserPasswordResetCommandTest.php and this document. Existing seeder, config and .env were not changed.

The real local pilot reset was NOT executed. No approval to execute it is inferred from passing tests. **WAITING_FOR_RESET_APPROVAL**.
