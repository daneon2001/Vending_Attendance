# MySQL backup and restore runbook

## Safety model

The scripts accept only configured source databases beginning with `VENDING_BACKUP_ALLOWED_DB_PREFIX` (default `vending_attendance_`). Restore refuses the configured source DB and only creates a new database matching `vending_attendance_*restore*test*`. Passwords use an ephemeral MySQL defaults file and are not placed in process arguments or output. Backups, checksums and local audit logs live under ignored `storage` paths.

Do not use these scripts for `ASISTENCIAS_FORTIA`, Fortia, SYBI, production shared schemas or any pre-existing restore target.

## Backup

1. Confirm `.env` points to the intended vending source without printing the password.
2. Ensure `mysqldump` is installed or configure `MYSQLDUMP_PATH` outside Git.
3. Run:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\security\backup_mysql.ps1 -SkipOffsite
   ```

4. Verify JSON reports `status=ok`, expected DB, SHA-256 and no stderr.
5. Copy `.zip` and `.sha256` to approved encrypted/offsite storage through the operational backup system. Apply restricted access and retention policy.

## Restore drill

1. Never use the live/current DB name. Select a new name such as `vending_attendance_restore_test_YYYYMMDDHHMMSS`.
2. Run:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\security\restore_backup_test.ps1 -RestoreDbName vending_attendance_restore_test_YYYYMMDDHHMMSS
   ```

3. The script validates SHA-256 before extraction, creates a new database, restores it and reports counts for vending machines, devices, assignments, attendance events, manifest states and audit logs.
4. Compare counts and sample only non-sensitive identifiers through an approved DBA session. Run application integrity checks against the restored copy if required.
5. Record RPO (backup time), RTO (start to validated restore), checksum, target DB and result. Never paste rows or credentials into the record.
6. After approval and retention of evidence, a DBA may drop the exact disposable restore DB. Never automate a wildcard drop.

## Phase 9 evidence

On 2026-09-05 UTC, a local controlled dump of `vending_attendance_dev` was checksummed and restored into the newly created `vending_attendance_restore_test_20260905183858` database. SHA-256 verification passed before extraction. Validation returned non-zero, queryable counts for machines, devices, assignments, vending attendance, device manifest states and audit logs. The configured source DB was not modified by restore.
