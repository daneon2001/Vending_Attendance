# Beta migration classification - Phase 13.9A

105 files retained. TEST_ONLY: 0 whole files; external fixture branches are opt-in. Legacy tables are part of the own database, not a dependency on ASISTENCIAS_FORTIA.

CORE_VENDING: 24, EXTERNAL_INTEGRATION: 1, FIELD_IDENTITY: 2, FORTIA_LOCAL: 45, LEGACY: 24, SUPPORT: 5, SYBI: 4

Proof: owned empty random MySQL database, testing safety guard, integrations.fortia_mock_migrations=false, migrate (not fresh), all 105 records, reject queries to other connections. Finally DROP owned database and verify residual 0. The external guard also rejects beta/production even if its flag is enabled. No migration ran against the real database.

| File | Class | Identified local tables | Beta treatment |
|---|---|---|---|
| 0001_01_01_000000_create_users_table.php | CORE_VENDING | password_reset_tokens, sessions, users | Own schema. |
| 0001_01_01_000001_create_cache_table.php | CORE_VENDING | cache, cache_locks | Own schema. |
| 0001_01_01_000002_create_jobs_table.php | CORE_VENDING | failed_jobs, job_batches, jobs | Own schema. |
| 2025_12_10_181904_create_asistencias_table.php | LEGACY | asistencias, empleados | Local history retained; no external dependency. |
| 2025_12_10_181920_create_fingerprints_table.php | LEGACY | empleados, fingerprints | Local history retained; no external dependency. |
| 2025_12_10_181939_create_attendance_logs_table.php | FORTIA_LOCAL | attendance_logs | Own shared catalog/history; no Fortia server. |
| 2025_12_10_181939_create_clocks_table.php | LEGACY | clocks | Local history retained; no external dependency. |
| 2025_12_10_181939_create_companies_table.php | FORTIA_LOCAL | companies | Own shared catalog/history; no Fortia server. |
| 2025_12_10_181939_create_shift_profiles_tables.php | FORTIA_LOCAL | shift_profile_days, shift_profiles | Own shared catalog/history; no Fortia server. |
| 2025_12_10_182053_create_employee_sync_states_table.php | FORTIA_LOCAL | empleados, employee_sync_states | Own shared catalog/history; no Fortia server. |
| 2025_12_10_185218_add_estatus_to_users_table.php | FORTIA_LOCAL | users | Own shared catalog/history; no Fortia server. |
| 2025_12_10_190756_add_apellidos_to_empleados_table.php | LEGACY | empleados | Local history retained; no external dependency. |
| 2025_12_10_190925_add_status_to_empleados_table.php | LEGACY | empleados | Local history retained; no external dependency. |
| 2025_12_10_193111_create_personal_access_tokens_table.php | CORE_VENDING | personal_access_tokens | Own schema. |
| 2025_12_10_200100_create_locations_table.php | FORTIA_LOCAL | locations | Own shared catalog/history; no Fortia server. |
| 2025_12_10_200200_update_clocks_table_with_monitoring_fields.php | LEGACY | clocks | Local history retained; no external dependency. |
| 2025_12_11_090000_add_program_status_to_clocks_table.php | LEGACY | clocks | Local history retained; no external dependency. |
| 2025_12_11_090100_create_clock_logs_table.php | LEGACY | clock_logs | Local history retained; no external dependency. |
| 2025_12_11_110000_update_locations_with_unit_fields.php | FORTIA_LOCAL | locations | Own shared catalog/history; no Fortia server. |
| 2025_12_11_221500_add_program_status_column_to_clocks_table.php | LEGACY | clocks | Local history retained; no external dependency. |
| 2025_12_11_230840_create_employees_table.php | FORTIA_LOCAL | employees | Own shared catalog/history; no Fortia server. |
| 2025_12_11_230841_create_attendance_logs_table.php | FORTIA_LOCAL | attendance_logs | Own shared catalog/history; no Fortia server. |
| 2025_12_11_230841_create_employee_fingerprints_table.php | LEGACY | employee_fingerprints | Local history retained; no external dependency. |
| 2025_12_13_000000_create_fortia_employees_table.php | EXTERNAL_INTEGRATION | See local operation in file | NO-OP beta; fortia_mock only local/testing with opt-in. |
| 2025_12_13_000100_recreate_employee_sync_states_table.php | FORTIA_LOCAL | employee_sync_states | Own shared catalog/history; no Fortia server. |
| 2025_12_13_000110_create_employee_status_changes_table.php | FORTIA_LOCAL | employee_status_changes | Own shared catalog/history; no Fortia server. |
| 2025_12_13_000120_add_email_company_to_employees_table.php | FORTIA_LOCAL | employees | Own shared catalog/history; no Fortia server. |
| 2025_12_13_184317_drop_legacy_empleados_tables.php | LEGACY | See local operation in file | Local history retained; no external dependency. |
| 2025_12_18_000000_add_missing_columns_to_attendance_logs_table.php | FORTIA_LOCAL | attendance_logs | Own shared catalog/history; no Fortia server. |
| 2025_12_19_000000_create_roles_and_permissions_tables.php | CORE_VENDING | permission_role, permissions, role_user, roles | Own schema. |
| 2025_12_20_000000_create_audit_logs_table.php | CORE_VENDING | audit_logs | Own schema. |
| 2025_12_24_000000_create_empleados_table.php | LEGACY | empleados | Local history retained; no external dependency. |
| 2025_12_24_010000_create_employee_sync_states_table.php | FORTIA_LOCAL | employee_sync_states | Own shared catalog/history; no Fortia server. |
| 2026_01_27_120000_add_vendor_template_id_to_employee_fingerprints_table.php | LEGACY | employee_fingerprints | Local history retained; no external dependency. |
| 2026_02_03_120000_harden_enrolment_storage.php | LEGACY | employee_fingerprints, enrolment_audits | Local history retained; no external dependency. |
| 2026_02_03_170000_add_local_id_to_attendance_logs_table.php | FORTIA_LOCAL | attendance_logs | Own shared catalog/history; no Fortia server. |
| 2026_02_03_180000_create_employee_template_deletions_table.php | LEGACY | employee_template_deletions | Local history retained; no external dependency. |
| 2026_02_04_090000_add_last_seen_ip_to_clocks_table.php | LEGACY | clocks | Local history retained; no external dependency. |
| 2026_02_18_100000_add_central_fields_to_attendance_logs_table.php | FORTIA_LOCAL | attendance_logs, users | Own shared catalog/history; no Fortia server. |
| 2026_02_18_100100_create_attendance_changes_table.php | FORTIA_LOCAL | attendance_changes, attendance_logs, users | Own shared catalog/history; no Fortia server. |
| 2026_02_18_100200_create_attendance_dailies_table.php | FORTIA_LOCAL | attendance_dailies, employees, locations | Own shared catalog/history; no Fortia server. |
| 2026_02_18_120000_create_devices_table.php | FORTIA_LOCAL | devices | Own shared catalog/history; no Fortia server. |
| 2026_02_18_120100_create_device_nonces_table.php | FORTIA_LOCAL | device_nonces | Own shared catalog/history; no Fortia server. |
| 2026_02_18_120200_create_attendances_raw_table.php | FORTIA_LOCAL | attendances_raw | Own shared catalog/history; no Fortia server. |
| 2026_02_18_120300_set_default_timezone_for_locations.php | FORTIA_LOCAL | locations | Own shared catalog/history; no Fortia server. |
| 2026_02_18_130000_add_heartbeat_fields_to_devices_table.php | FORTIA_LOCAL | devices | Own shared catalog/history; no Fortia server. |
| 2026_02_20_120000_add_compact_catalog_indexes_to_employees_table.php | FORTIA_LOCAL | employees | Own shared catalog/history; no Fortia server. |
| 2026_02_20_130500_harden_audit_and_attendance_forensics.php | FORTIA_LOCAL | attendance_logs, audit_logs, devices, users | Own shared catalog/history; no Fortia server. |
| 2026_02_20_140000_add_biometrics_permissions.php | LEGACY | permission_role, permissions, roles | Local history retained; no external dependency. |
| 2026_02_21_160000_add_biometric_fingerprint_delete_permission.php | LEGACY | permission_role, permissions, roles | Local history retained; no external dependency. |
| 2026_03_17_130000_add_can_check_all_branches_to_employees_tables.php | FORTIA_LOCAL | employees | Own column; fortia_mock branch disabled in beta. |
| 2026_03_19_120000_add_face_administration_to_employees_and_template_deletions.php | LEGACY | employee_template_deletions, employees | Local history retained; no external dependency. |
| 2026_03_19_120100_add_biometric_face_manage_permission.php | LEGACY | permission_role, permissions, roles | Local history retained; no external dependency. |
| 2026_03_20_090000_add_template_provider_columns_and_scope_deletions.php | LEGACY | employee_fingerprints, employee_scope_deletions, employee_template_deletions | Local history retained; no external dependency. |
| 2026_04_06_144027_add_check_scope_to_employees_table.php | FORTIA_LOCAL | employees | Own shared catalog/history; no Fortia server. |
| 2026_04_06_144030_create_employee_allowed_locations_table.php | FORTIA_LOCAL | employee_allowed_locations | Own shared catalog/history; no Fortia server. |
| 2026_04_06_144032_backfill_check_scope_on_employees_table.php | FORTIA_LOCAL | See local operation in file | Own shared catalog/history; no Fortia server. Own-data backfill; backup required. |
| 2026_04_21_000001_create_razones_sociales_table.php | FORTIA_LOCAL | razones_sociales | Own shared catalog/history; no Fortia server. |
| 2026_04_21_000002_create_registros_imss_table.php | FORTIA_LOCAL | registros_imss | Own shared catalog/history; no Fortia server. |
| 2026_04_21_000003_create_puestos_table.php | FORTIA_LOCAL | puestos | Own shared catalog/history; no Fortia server. |
| 2026_04_21_000004_create_centros_costo_table.php | FORTIA_LOCAL | centros_costo | Own shared catalog/history; no Fortia server. |
| 2026_04_21_000005_create_areas_table.php | FORTIA_LOCAL | areas | Own shared catalog/history; no Fortia server. |
| 2026_04_21_000006_create_departamentos_table.php | FORTIA_LOCAL | departamentos | Own shared catalog/history; no Fortia server. |
| 2026_04_21_000007_create_ubicaciones_table.php | FORTIA_LOCAL | ubicaciones | Own shared catalog/history; no Fortia server. |
| 2026_04_21_000008_create_periodos_pago_table.php | FORTIA_LOCAL | periodos_pago | Own shared catalog/history; no Fortia server. |
| 2026_04_22_125624_create_employee_details_table.php | FORTIA_LOCAL | employee_details, employees | Own shared catalog/history; no Fortia server. |
| 2026_04_24_180000_add_fortia_keys_to_companies_and_locations.php | FORTIA_LOCAL | companies, locations | Own shared catalog/history; no Fortia server. |
| 2026_05_04_170000_add_employees_import_permission.php | FORTIA_LOCAL | permission_role, permissions, roles | Own shared catalog/history; no Fortia server. |
| 2026_05_04_170100_create_employee_import_metadata_table.php | FORTIA_LOCAL | employee_import_metadata, employees | Own shared catalog/history; no Fortia server. |
| 2026_05_14_000001_create_employee_face_templates_table.php | LEGACY | employee_face_templates | Local history retained; no external dependency. |
| 2026_05_14_120000_add_performance_indexes_to_biometric_tables.php | LEGACY | See local operation in file | Local history retained; no external dependency. |
| 2026_06_09_120000_add_metadata_to_enrolment_audits_table.php | LEGACY | enrolment_audits | Local history retained; no external dependency. |
| 2026_07_01_090000_create_audit_cleanup_settings_table.php | FORTIA_LOCAL | audit_cleanup_settings | Own shared catalog/history; no Fortia server. |
| 2026_07_01_090100_create_audit_cleanup_runs_table.php | FORTIA_LOCAL | audit_cleanup_runs | Own shared catalog/history; no Fortia server. |
| 2026_07_17_120000_add_employment_dates_to_employees_table.php | FORTIA_LOCAL | employee_import_metadata, employees | Own shared catalog/history; no Fortia server. |
| 2026_07_17_130000_correct_hire_date_from_group_import_date.php | FORTIA_LOCAL | employee_import_metadata, employees | Own shared catalog/history; no Fortia server. Own-data backfill; backup required. |
| 2026_09_04_000001_create_vending_machines_table.php | CORE_VENDING | vending_machines | Own schema. |
| 2026_09_04_000002_create_employee_machine_assignments_table.php | CORE_VENDING | employee_machine_assignments | Own schema. |
| 2026_09_04_000003_create_machine_geofences_table.php | CORE_VENDING | machine_geofences | Own schema. |
| 2026_09_04_000004_add_vending_machine_id_to_devices_table.php | CORE_VENDING | devices | Own schema. |
| 2026_09_04_000005_add_vending_machine_permissions.php | CORE_VENDING | permission_role, permissions, roles | Own schema. |
| 2026_09_04_000006_extend_devices_for_vending_identity.php | CORE_VENDING | devices | Own schema. |
| 2026_09_04_000007_create_device_provisioning_tokens_table.php | CORE_VENDING | device_provisioning_tokens | Own schema. |
| 2026_09_04_000008_require_device_uuid.php | CORE_VENDING | devices | Own schema. |
| 2026_09_04_000009_add_employee_manifest_version_to_vending_machines.php | CORE_VENDING | vending_machines | Own schema. |
| 2026_09_04_000010_create_device_manifest_states_table.php | CORE_VENDING | device_manifest_states, devices | Own schema. |
| 2026_09_04_000011_create_vending_attendance_events_table.php | CORE_VENDING | vending_attendance_events | Own schema. |
| 2026_09_04_000012_create_device_attendance_metrics_table.php | CORE_VENDING | device_attendance_metrics | Own schema. |
| 2026_09_04_000013_add_sybi_tracking_to_vending_machines.php | SYBI | vending_machines | Own projection; no external request. |
| 2026_09_04_000014_create_sybi_vending_sync_runs_table.php | SYBI | sybi_vending_sync_runs | Own projection; no external request. |
| 2026_09_04_000015_create_sybi_vending_source_records_table.php | SYBI | sybi_vending_source_records | Own projection; no external request. |
| 2026_09_04_000016_add_projection_metrics_to_sybi_vending_sync_runs.php | SYBI | sybi_vending_sync_runs | Own projection; no external request. |
| 2026_09_05_000001_add_fleet_state_to_devices_table.php | CORE_VENDING | devices | Own schema. |
| 2026_09_05_000002_create_mobile_releases_table.php | CORE_VENDING | mobile_releases | Own schema. |
| 2026_09_05_000003_create_mobile_release_policies_table.php | CORE_VENDING | mobile_release_policies | Own schema. |
| 2026_09_05_000004_add_mobile_release_targeting.php | CORE_VENDING | devices, mobile_release_targets | Own schema. |
| 2026_09_05_000005_add_vending_identity_to_employees_table.php | CORE_VENDING | employees | Own schema. |
| 2026_09_05_000006_create_employee_import_staging_tables.php | CORE_VENDING | employee_import_rows, employee_import_runs | Own schema. |
| 2026_09_07_130000_create_support_domain.php | SUPPORT | notifications, support_correlations, support_evidence, support_integration_machines, support_integrations, support_operations, support_policy_versions, support_runtime_cursors, support_ticket_events, support_tickets, support_verifications | Own schema. |
| 2026_09_07_130100_link_support_verifications_and_policy_cursor.php | SUPPORT | support_runtime_cursors, support_tickets, support_verifications | Own schema. |
| 2026_09_08_140000_add_employee_link_to_users_table.php | FIELD_IDENTITY | users | Own schema. |
| 2026_09_08_150000_create_vending_support_activities.php | SUPPORT | vending_support_activities, vending_support_activity_events | Own schema. |
| 2026_09_08_160000_index_support_activity_queries.php | SUPPORT | vending_support_activities | Own schema. |
| 2026_09_09_120000_create_field_device_identity_tables.php | FIELD_IDENTITY | employee_devices, field_device_audit_events, field_device_challenges, field_device_otps | Own schema. |
| 2026_09_10_190000_create_support_activity_contributions.php | SUPPORT | See local operation in file | Own schema. |
