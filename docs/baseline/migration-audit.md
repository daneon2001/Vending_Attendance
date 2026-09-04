# Phase 0Q migration audit

Scope: all 76 PHP migrations in `database/migrations`, reviewed in filename execution order against an empty MySQL 8.4 database. `SAFE` means straightforward and ordered for an empty database. `REVIEW` identifies legacy no-ops, destructive replacement, cross-connection work, data backfills, or engine-specific SQL that is safe here but deserves care on a populated database. `BLOCKED` means unsafe for this empty baseline.

| Migration | Operation | Affected tables | Risk | Safe on empty DB | Notes |
|---|---|---|---|---|---|
| 0001_01_01_000000_create_users_table | CREATE | users, password_reset_tokens, sessions | SAFE | YES | Framework base tables. |
| 0001_01_01_000001_create_cache_table | CREATE | cache, cache_locks | SAFE | YES | Framework cache tables. |
| 0001_01_01_000002_create_jobs_table | CREATE | jobs, job_batches, failed_jobs | SAFE | YES | Framework queue tables. |
| 2025_12_10_181904_create_asistencias_table | CONDITIONAL CREATE | asistencias, empleados | REVIEW | YES | No-op because `empleados` does not yet exist; legacy table is not created. |
| 2025_12_10_181920_create_fingerprints_table | CONDITIONAL CREATE | fingerprints, empleados | REVIEW | YES | No-op because `empleados` does not yet exist; legacy table is not created. |
| 2025_12_10_181939_create_attendance_logs_table | CREATE | attendance_logs | REVIEW | YES | First of two same-named migrations; later create is guarded. |
| 2025_12_10_181939_create_clocks_table | CREATE | clocks | SAFE | YES | Standalone initial clock table. |
| 2025_12_10_181939_create_companies_table | CREATE | companies | SAFE | YES | Created before dependent shift profiles. |
| 2025_12_10_181939_create_shift_profiles_tables | CREATE | shift_profiles, shift_profile_days, companies | REVIEW | YES | Ordered foreign keys are safe; `down()` has an incorrect legacy table name, irrelevant to `up()`. |
| 2025_12_10_182053_create_employee_sync_states_table | CONDITIONAL CREATE | employee_sync_states, empleados | REVIEW | YES | No-op because `empleados` is absent at this point. |
| 2025_12_10_185218_add_estatus_to_users_table | ALTER | users | SAFE | YES | Adds guarded user status field. |
| 2025_12_10_190756_add_apellidos_to_empleados_table | CONDITIONAL ALTER | empleados | REVIEW | YES | Guarded no-op because legacy table is absent. |
| 2025_12_10_190925_add_status_to_empleados_table | CONDITIONAL ALTER | empleados | REVIEW | YES | Guarded no-op because legacy table is absent. |
| 2025_12_10_193111_create_personal_access_tokens_table | CREATE | personal_access_tokens | SAFE | YES | Sanctum token table. |
| 2025_12_10_200100_create_locations_table | CREATE | locations, companies | SAFE | YES | Companies already exists. |
| 2025_12_10_200200_update_clocks_table_with_monitoring_fields | ALTER | clocks | SAFE | YES | Clock table already exists; additions are guarded in code. |
| 2025_12_11_090000_add_program_status_to_clocks_table | ALTER | clocks | REVIEW | YES | First program-status compatibility migration. |
| 2025_12_11_090100_create_clock_logs_table | CREATE | clock_logs, clocks | SAFE | YES | Parent table exists. |
| 2025_12_11_110000_update_locations_with_unit_fields | ALTER | locations | SAFE | YES | Location table exists. |
| 2025_12_11_221500_add_program_status_column_to_clocks_table | CONDITIONAL ALTER | clocks | REVIEW | YES | Duplicate compatibility migration; `hasColumn` prevents duplicate add. |
| 2025_12_11_230840_create_employees_table | CREATE | employees | SAFE | YES | Canonical employee table. |
| 2025_12_11_230841_create_attendance_logs_table | CONDITIONAL CREATE | attendance_logs, employees | REVIEW | YES | Guarded no-op because earlier `attendance_logs` exists. |
| 2025_12_11_230841_create_employee_fingerprints_table | CREATE | employee_fingerprints, employees, clocks | SAFE | YES | Both parents already exist. |
| 2025_12_13_000000_create_fortia_employees_table | CROSS-CONNECTION CREATE | fortia_employees | REVIEW | YES | Runs only on configured `fortia_mock`; local mock DB was explicitly provisioned. |
| 2025_12_13_000100_recreate_employee_sync_states_table | DROP + CREATE | employee_sync_states | REVIEW | YES | Destructive replacement in `up()`; table is absent/empty in this baseline. |
| 2025_12_13_000110_create_employee_status_changes_table | CREATE | employee_status_changes, employees | SAFE | YES | Parent exists. |
| 2025_12_13_000120_add_email_company_to_employees_table | ALTER | employees | SAFE | YES | Canonical table exists. |
| 2025_12_13_184317_drop_legacy_empleados_tables | DROP IF EXISTS | asistencias, fingerprints, employee_sync_states, empleados | REVIEW | YES | Intentional legacy cleanup; destructive on populated databases. |
| 2025_12_18_000000_add_missing_columns_to_attendance_logs_table | ALTER | attendance_logs | REVIEW | YES | Compatibility expansion of the earlier table; guarded column checks. |
| 2025_12_19_000000_create_roles_and_permissions_tables | CREATE | roles, permissions, permission_role, role_user | SAFE | YES | Ordered role/permission schema. |
| 2025_12_20_000000_create_audit_logs_table | CREATE | audit_logs | SAFE | YES | Audit table creation. |
| 2025_12_24_000000_create_empleados_table | CREATE | empleados, users, companies | REVIEW | YES | Reintroduces a legacy compatibility table after the explicit legacy drop. |
| 2025_12_24_010000_create_employee_sync_states_table | DROP + CREATE | employee_sync_states | REVIEW | YES | Second destructive replacement; previous instance was already dropped and baseline is empty. |
| 2026_01_27_120000_add_vendor_template_id_to_employee_fingerprints_table | ALTER | employee_fingerprints | SAFE | YES | Parent exists and addition is guarded. |
| 2026_02_03_120000_harden_enrolment_storage | ALTER + CREATE | employee_fingerprints, enrolment_audits | REVIEW | YES | Compatibility path adds indexes/columns to existing fingerprint table. |
| 2026_02_03_170000_add_local_id_to_attendance_logs_table | ALTER + INDEX INSPECTION | attendance_logs | REVIEW | YES | Uses MySQL `SHOW INDEX`; valid on selected MySQL engine. |
| 2026_02_03_180000_create_employee_template_deletions_table | CREATE | employee_template_deletions, employees | SAFE | YES | Parent exists. |
| 2026_02_04_090000_add_last_seen_ip_to_clocks_table | ALTER | clocks | SAFE | YES | Parent exists; guarded addition. |
| 2026_02_18_100000_add_central_fields_to_attendance_logs_table | ALTER + INDEX INSPECTION | attendance_logs | REVIEW | YES | Uses MySQL index introspection and guarded additions. |
| 2026_02_18_100100_create_attendance_changes_table | CREATE + ALTER | attendance_changes | SAFE | YES | Creates then adds guarded foreign keys/indexes. |
| 2026_02_18_100200_create_attendance_dailies_table | CREATE + ALTER | attendance_dailies | SAFE | YES | Creates then adds guarded indexes. |
| 2026_02_18_120000_create_devices_table | CREATE | devices | SAFE | YES | Device registry creation. |
| 2026_02_18_120100_create_device_nonces_table | CREATE | device_nonces, devices | SAFE | YES | Parent exists. |
| 2026_02_18_120200_create_attendances_raw_table | CREATE | attendances_raw, devices | SAFE | YES | Parent exists. |
| 2026_02_18_120300_set_default_timezone_for_locations | DATA UPDATE | locations | REVIEW | YES | Data backfill is harmless on an empty table but mutates existing rows elsewhere. |
| 2026_02_18_130000_add_heartbeat_fields_to_devices_table | ALTER | devices | SAFE | YES | Device table already exists. |
| 2026_02_20_120000_add_compact_catalog_indexes_to_employees_table | ALTER INDEXES | employees | SAFE | YES | Canonical table exists. |
| 2026_02_20_130500_harden_audit_and_attendance_forensics | ALTER | attendance_logs, audit_logs | REVIEW | YES | Compatibility hardening of existing forensic tables. |
| 2026_02_20_140000_add_biometrics_permissions | DATA UPSERT | permissions, roles, permission_role | REVIEW | YES | Seed-like permission mutation; safe on empty role tables. |
| 2026_02_21_160000_add_biometric_fingerprint_delete_permission | DATA UPSERT | permissions, roles, permission_role | REVIEW | YES | Seed-like permission mutation. |
| 2026_03_17_130000_add_can_check_all_branches_to_employees_tables | ALTER, CROSS-CONNECTION ALTER | employees, fortia_employees | REVIEW | YES | Touches main and explicitly local mock connections with guards. |
| 2026_03_19_120000_add_face_administration_to_employees_and_template_deletions | ALTER + DATA UPDATE | employees, employee_template_deletions | REVIEW | YES | Adds face fields and backfills deletion scope; empty tables make update harmless. |
| 2026_03_19_120100_add_biometric_face_manage_permission | DATA UPSERT | permissions, roles, permission_role | REVIEW | YES | Seed-like permission mutation. |
| 2026_03_20_090000_add_template_provider_columns_and_scope_deletions | ALTER + CREATE | employee_fingerprints, employee_template_deletions, employee_scope_deletions | SAFE | YES | Guarded changes; `DROP` appears only in `down()`. |
| 2026_04_06_144027_add_check_scope_to_employees_table | ALTER + RAW UPDATE | employees | REVIEW | YES | Guarded schema change plus MySQL backfill; empty table is safe. |
| 2026_04_06_144030_create_employee_allowed_locations_table | CREATE | employee_allowed_locations, employees, locations | SAFE | YES | Both parents exist. |
| 2026_04_06_144032_backfill_check_scope_on_employees_table | RAW UPDATE | employees | REVIEW | YES | Data-only compatibility backfill; empty table is safe. |
| 2026_04_21_000001_create_razones_sociales_table | CREATE | razones_sociales | SAFE | YES | Independent catalog. |
| 2026_04_21_000002_create_registros_imss_table | CREATE | registros_imss | SAFE | YES | Independent catalog. |
| 2026_04_21_000003_create_puestos_table | CREATE | puestos | SAFE | YES | Independent catalog. |
| 2026_04_21_000004_create_centros_costo_table | CREATE | centros_costo | SAFE | YES | Independent catalog. |
| 2026_04_21_000005_create_areas_table | CREATE | areas | SAFE | YES | Independent catalog. |
| 2026_04_21_000006_create_departamentos_table | CREATE | departamentos | SAFE | YES | Independent catalog. |
| 2026_04_21_000007_create_ubicaciones_table | CREATE | ubicaciones | SAFE | YES | Independent catalog. |
| 2026_04_21_000008_create_periodos_pago_table | CREATE | periodos_pago | SAFE | YES | Independent catalog. |
| 2026_04_22_125624_create_employee_details_table | CONDITIONAL CREATE | employee_details, employees | SAFE | YES | Parent check and absent-table guard. |
| 2026_04_24_180000_add_fortia_keys_to_companies_and_locations | ALTER | companies, locations | SAFE | YES | Guarded unique fields. |
| 2026_05_04_170000_add_employees_import_permission | DATA UPSERT | permissions, roles, permission_role | REVIEW | YES | Seed-like permission mutation. |
| 2026_05_04_170100_create_employee_import_metadata_table | CONDITIONAL CREATE | employee_import_metadata, employees | SAFE | YES | Parent and absent-table guards. |
| 2026_05_14_000001_create_employee_face_templates_table | CREATE | employee_face_templates, employees | SAFE | YES | Parent exists. |
| 2026_05_14_120000_add_performance_indexes_to_biometric_tables | ALTER INDEXES + INDEX INSPECTION | companies, locations, clocks, employee_template_deletions, employee_face_templates, employee_fingerprints, attendance_logs | REVIEW | YES | MySQL-specific `SHOW INDEX`; guards verify tables, columns, and existing indexes. |
| 2026_06_09_120000_add_metadata_to_enrolment_audits_table | ALTER | enrolment_audits | SAFE | YES | Guarded addition. |
| 2026_07_01_090000_create_audit_cleanup_settings_table | CREATE | audit_cleanup_settings | SAFE | YES | Independent settings table. |
| 2026_07_01_090100_create_audit_cleanup_runs_table | CREATE | audit_cleanup_runs | SAFE | YES | Independent run history table. |
| 2026_07_17_120000_add_employment_dates_to_employees_table | ALTER + DATA BACKFILL | employees, employee_import_metadata | REVIEW | YES | Adds dates and attempts a guarded metadata backfill; empty tables are safe. |
| 2026_07_17_130000_correct_hire_date_from_group_import_date | DATA BACKFILL | employees, employee_import_metadata | REVIEW | YES | Corrective data migration; empty tables are safe. |

## Result

- SAFE: 44
- REVIEW: 32
- BLOCKED: 0
- Empty MySQL 8.4 baseline decision: migration execution is permitted only with `fortia_mock` bound to the verified local mock database.
- No migration is inferred safe for populated or shared databases from this review; the decision applies solely to the empty isolated Phase 0Q databases.
