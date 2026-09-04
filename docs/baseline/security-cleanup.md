# Phase 0Q security cleanup

## `public/phpinfo.php`

- State at HEAD: tracked.
- Content: a two-line diagnostic endpoint invoking `phpinfo()`.
- References: none found elsewhere in the repository.
- Classification: REMOVE.
- Action: deleted from this clone. Git history was not rewritten.

## `respaldo_asistencias_dev.sql`

- State at HEAD: tracked.
- Size: 249,237,260 bytes.
- Limited non-content inspection: 46 `CREATE TABLE` statements, 147 `INSERT INTO` statement blocks, and personnel/attendance table names.
- Assessment: contains populated database data and must be treated as potentially real/sensitive.
- Dependencies: no code or documentation reference requires this file.
- Import: never imported.
- Classification: REMOVE.
- Action: deleted from this clone and added to `.gitignore`. The object remains recoverable in existing Git history until a separately authorized history-cleanup phase.

## Temporary PHP scripts

- Classification: KEEP 0, REMOVE 13, UNKNOWN 0.
- Removed: `.tmp_admin_check.php`, `.tmp_backfill_raw_to_logs.php`, `.tmp_check_att.php`, `.tmp_counts.php`, `.tmp_emp.php`, `.tmp_emp2.php`, `.tmp_emp_check.php`, `.tmp_lists.php`, `.tmp_seed_debug.php`, `.tmp_setup_check.php`, `.tmp_show_api_logs.php`, `.tmp_verify_bridge.php`, `.tmp_verify_final.php`.
- Evidence: all bootstrap the application as standalone diagnostics/backfills, none is referenced elsewhere, and the only mutating script is explicitly named and structured as a one-off backfill.
- Action: deleted from this clone; `/.tmp_*.php` added to `.gitignore` to prevent recurrence.

## Secrets

- `.env` is ignored and was never copied from `ASISTENCIAS_FORTIA`.
- No new secret-bearing file is intended for version control.
- Documentation records variable names and local endpoints only; password/key values are omitted.
