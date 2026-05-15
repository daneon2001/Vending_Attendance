# Database Performance Indexes

- Fecha: 2026-05-14
- Proyecto: `C:\laragon\www\asistencias_fortia`
- Objetivo: agregar indices seguros y reversibles para consultas frecuentes de empleados, asistencias, biometria, relojes y catalogos sin cambiar logica funcional.

## Tablas revisadas

- `employees`
- `attendance_logs`
- `attendances_raw`
- `employee_fingerprints`
- `employee_face_templates`
- `employee_template_deletions`
- `employee_allowed_locations`
- `employee_scope_deletions`
- `clocks`
- `locations`
- `companies`
- `devices`
- `clock_logs`
- `attendance_changes`

## Consultas y patrones revisados

- Empleados:
  - filtros por `status`, `base_location_id`, `company_id`, `updated_at`
  - catalogos y sincronizacion incremental por `updated_at`
  - filtros administrativos por unidad, empresa, biometria facial y orden por `full_name` o `updated_at`
- Asistencias:
  - duplicidad por `local_id + device_id`
  - consultas por `employee_id`, `location_id`, `device_id`, `attendance_status`, `source`, `log_date`
  - reportes y dashboards con rangos de fecha
- Eventos crudos on-prem:
  - duplicidad por `device_serial + local_event_id`
  - filtros por `collaborator_id`, `unit_id`, `clock_id`, `event_time_utc`
- Huellas:
  - filtros por `employee_id`, `status`, `deleted_at`, `enrolment_type`, `updated_at`
  - sincronizacion de templates por `updated_at`
- Face ID:
  - busqueda por `template_hash`
  - desactivacion/rotacion por `employee_id + is_active`
  - sincronizacion por `is_active`, `model_name`, `updated_at`
- Relojes:
  - resolucion por `serial_number`
  - catalogos por `company_id`, `location_id`, `status`, `monitoring_status`, `program_status`
- Catalogos:
  - unidades por `company_id`, `status`
  - empresas por `status`
- Tombstones de templates:
  - filtros por `vendor_template_id`, `scope_location_id`, `employee_id`, `deleted_at`, `biometric_type`

## Indices existentes detectados

### `employees`

- `employees_fortia_employee_id_unique` sobre `fortia_employee_id`
- `employees_base_location_id_index` sobre `base_location_id`
- `employees_status_index` sobre `status`
- `employees_full_name_index` sobre `full_name`
- `employees_updated_at_index` sobre `updated_at`
- `employees_status_base_location_updated_idx` sobre `status, base_location_id, updated_at`

### `attendance_logs`

- `attendance_logs_local_device_unique` sobre `local_id, device_id`
- `idx_company_employee_date` sobre `company_id, employee_id, log_date`
- `attendance_logs_fortia_employee_id_index` sobre `fortia_employee_id`
- `attendance_logs_log_date_idx` sobre `log_date`
- `attendance_logs_employee_log_date_idx` sobre `employee_id, log_date`
- `attendance_logs_location_log_date_idx` sobre `location_id, log_date`
- `attendance_logs_device_log_date_idx` sobre `device_id, log_date`
- `attendance_logs_status_log_date_idx` sobre `attendance_status, log_date`
- `attendance_logs_source_log_date_idx` sobre `source, log_date`
- `attendance_logs_integrity_hash_idx` sobre `integrity_hash`
- `attendance_logs_integrity_prev_hash_idx` sobre `integrity_previous_hash`
- `attendance_logs_device_serial_idx` sobre `device_serial`
- `attendance_logs_ingested_at_utc_idx` sobre `ingested_at_utc`
- `attendance_logs_request_id_idx` sobre `request_id`

### `attendances_raw`

- `att_raw_device_local_unique` sobre `device_serial, local_event_id`
- `att_raw_collab_utc_idx` sobre `collaborator_id, event_time_utc`
- `att_raw_unit_utc_idx` sobre `unit_id, event_time_utc`
- `att_raw_clock_utc_idx` sobre `clock_id, event_time_utc`
- `att_raw_utc_idx` sobre `event_time_utc`

### `employee_fingerprints`

- `employee_fingerprints_employee_vendor_unique` sobre `employee_id, vendor_template_id`
- `employee_fingerprints_vendor_template_id_idx` sobre `vendor_template_id`
- indice FK de `clock_id`

### `employee_face_templates`

- `employee_face_templates_template_hash_unique` sobre `template_hash`
- `employee_face_templates_employee_id_index` sobre `employee_id`
- `employee_face_templates_fortia_employee_id_index` sobre `fortia_employee_id`

### `employee_template_deletions`

- `employee_template_deletions_biometric_type_idx` sobre `biometric_type`
- `employee_template_deletions_scope_location_idx` sobre `scope_location_id`

### `clocks`

- `clocks_location_id_index` sobre `location_id`
- `clocks_last_heartbeat_at_index` sobre `last_heartbeat_at`

### `locations`

- `locations_company_id_code_unique` sobre `company_id, code`
- `locations_fortia_location_id_unique` sobre `fortia_location_id`
- `locations_status_index` sobre `status`

### `companies`

- `companies_fortia_company_id_unique` sobre `fortia_company_id`

## Indices agregados por la migracion

Archivo: `database/migrations/2026_05_14_120000_add_performance_indexes_to_biometric_tables.php`

| Tabla | Indice | Columnas | Consulta beneficiada | Motivo |
| --- | --- | --- | --- | --- |
| `attendance_logs` | `att_logs_emp_dev_date_idx` | `employee_id, device_id, log_date` | deduplicacion central en `OnPremAttendanceController::syncCentralAttendanceLog()` | acelera la verificacion exacta por empleado, reloj y fecha/hora sin cambiar la unicidad existente |
| `employee_fingerprints` | `emp_fp_emp_status_del_idx` | `employee_id, status, deleted_at` | templates activos por empleado y filtros de biometria | cubre filtros recurrentes por empleado con estado y descarte de eliminados |
| `employee_fingerprints` | `emp_fp_type_stat_del_upd_idx` | `enrolment_type, status, deleted_at, updated_at` | sincronizacion incremental de templates por tipo biometrico | favorece `where status/deleted_at`, filtro por tipo y `updated_at > since` con `orderBy(updated_at)` |
| `employee_face_templates` | `emp_face_emp_active_idx` | `employee_id, is_active` | rotacion/desactivacion de template activo por empleado | acelera el `update` previo a guardar el nuevo template activo |
| `employee_face_templates` | `emp_face_act_model_upd_idx` | `is_active, model_name, updated_at` | sincronizacion Face ID por `is_active`, `model_name`, `updated_at` | optimiza el catalogo incremental de Face ID sin indexar blobs |
| `employee_template_deletions` | `emp_tpl_del_vendor_tpl_idx` | `vendor, vendor_template_id` | tombstones por template proveedor | repone un acceso compuesto util para localizar eliminaciones por template externo |
| `employee_template_deletions` | `emp_tpl_del_scope_del_idx` | `scope_location_id, deleted_at` | tombstones filtrados por sucursal y fecha | mejora sincronizacion incremental y orden por `deleted_at` |
| `employee_template_deletions` | `emp_tpl_del_emp_del_idx` | `employee_id, deleted_at` | tombstones globales o por empleado permitido | acelera subconsultas por empleado con recorte incremental |
| `clocks` | `clocks_serial_number_idx` | `serial_number` | resolucion de reloj por serial y heartbeat por serial | evita full scan en endpoints on-prem y API de configuracion |
| `clocks` | `clocks_location_status_idx` | `location_id, status` | catalogos/filtrado de relojes por unidad y estatus | cubre filtros recurrentes de administracion |
| `clocks` | `clocks_company_location_idx` | `company_id, location_id` | catalogos/filtrado de relojes por empresa y unidad | mejora filtros conjuntos usados en catalogos y vistas admin |
| `locations` | `locations_company_status_idx` | `company_id, status` | catalogos de unidades por empresa y estatus | complementa `company_id + code` para filtros operativos |
| `companies` | `companies_status_idx` | `status` | catalogos de empresas por estatus | evita scan completo en listados administrativos |

## Indices evaluados y no agregados

- `employees(fortia_employee_id)`: ya existe unico.
- `employees(status, base_location_id, updated_at)`: ya existe como `employees_status_base_location_updated_idx`.
- `employees(employee_code)`: no aplica a la tabla operativa `employees`; la columna real no existe ahi.
- `attendance_logs(location_id, log_date)`, `attendance_logs(device_id, log_date)`, `attendance_logs(attendance_status, log_date)`, `attendance_logs(source, log_date)`: ya existen.
- `attendances_raw(device_serial, local_event_id)`: ya existe unico.
- `employee_face_templates(template_hash)`: ya existe unico.
- `employee_face_templates(fortia_employee_id, is_active)` y `employee_face_templates(employee_code, is_active)`: se revisaron, pero no aparecieron consultas frecuentes directas sobre esas combinaciones en el codigo actual.
- `clocks(serial, status)`: el nombre real de columna es `serial_number`; se agrego indice simple porque las consultas actuales son por serial exacto, no por serial + status.
- `sync_logs(...)`: no existe una tabla `sync_logs` en este proyecto; el estado de sincronizacion actual reside en `employee_sync_states`.
- campos `TEXT`, `LONGTEXT` o `JSON` como `embedding_encrypted`, `template_b64`, `raw_payload`, `meta`, `face_meta`: excluidos por costo y seguridad.

## Seguridad aplicada

- No se agregaron indices `UNIQUE` nuevos.
- No se cambiaron columnas, relaciones, controladores, requests, vistas ni endpoints.
- No se indexaron columnas `TEXT`, `LONGTEXT` o `JSON`.
- La migracion usa validaciones por tabla, columna e indice antes de crear.
- La migracion evita duplicados por nombre y tambien por la misma secuencia de columnas, aunque el indice ya exista con otro nombre.
- El `down()` revierte unicamente los indices creados por esta migracion.

## Riesgos

- Cada nuevo indice incrementa costo de escritura en `INSERT` y `UPDATE`, especialmente en `attendance_logs`, `employee_fingerprints` y `employee_face_templates`.
- Algunas bases pueden tener diferencias historicas respecto a migraciones previas; por eso la deteccion real se hizo con `SHOW INDEX` y la migracion incluye guards.
- En entornos grandes, `CREATE INDEX` puede bloquear parcialmente tablas si no se programa en ventana de mantenimiento.

## Como revertir

- Revertir solo esta migracion:
  - `php artisan migrate:rollback --path=database/migrations/2026_05_14_120000_add_performance_indexes_to_biometric_tables.php`
- Revertir por lote si aplica:
  - `php artisan migrate:rollback`

## Comandos usados

- `rg --files database app routes docs`
- `rg -n "whereBetween|orderBy|latest\\(|join\\(|groupBy\\(|updateOrCreate\\(|firstOrCreate\\(|upsert\\(|exists\\(|count\\(|where\\(" app database\\migrations`
- `php artisan migrate:status`
- inspeccion de esquema e indices con `SHOW COLUMNS` y `SHOW INDEX` via bootstrap Laravel
- `php artisan migrate --pretend`
- `php artisan migrate`
- `php artisan test`

## Resultado de validacion

- `php artisan migrate --pretend`
  - Laravel reporto la migracion `2026_05_14_120000_add_performance_indexes_to_biometric_tables`.
  - En esta base el modo `--pretend` solo mostro validaciones sobre existencia de tablas en `information_schema`; no imprimio los `ALTER TABLE ... ADD INDEX`.
  - Se verifico sintaxis adicionalmente con `php -l database/migrations/2026_05_14_120000_add_performance_indexes_to_biometric_tables.php`.
- `php artisan migrate`
  - Resultado: `2026_05_14_120000_add_performance_indexes_to_biometric_tables ... DONE`
- Confirmacion con `SHOW INDEX`
  - `attendance_logs`: aparece `att_logs_emp_dev_date_idx`
  - `employee_fingerprints`: aparecen `emp_fp_emp_status_del_idx` y `emp_fp_type_stat_del_upd_idx`
  - `employee_face_templates`: aparecen `emp_face_emp_active_idx` y `emp_face_act_model_upd_idx`
  - `employee_template_deletions`: aparecen `emp_tpl_del_vendor_tpl_idx`, `emp_tpl_del_scope_del_idx`, `emp_tpl_del_emp_del_idx`
  - `clocks`: aparecen `clocks_serial_number_idx`, `clocks_location_status_idx`, `clocks_company_location_idx`
  - `locations`: aparece `locations_company_status_idx`
  - `companies`: aparece `companies_status_idx`
- `php artisan test`
  - Resultado general: `180 passed`, `2 failed`
  - Fallas detectadas:
    - `Tests\\Feature\\Api\\EmployeeTemplatesSyncTest::test_face_filter_uses_same_allowed_universe`
    - `Tests\\Feature\\Api\\EmployeeTemplatesSyncTest::test_face_templates_require_face_sync_ready_flags`
  - Causa observada:
    - entorno SQLite de pruebas sin tabla `employee_face_templates`
    - error: `SQLSTATE[HY000]: General error: 1 no such table: employee_face_templates`
  - La falla no proviene de la migracion de indices creada en esta tarea; la migracion solo agrega indices y no modifica consultas ni tablas de pruebas.

## Validacion posterior recomendada

- `SHOW INDEX FROM employees;`
- `SHOW INDEX FROM attendance_logs;`
- `SHOW INDEX FROM employee_fingerprints;`
- `SHOW INDEX FROM employee_face_templates;`
- `SHOW INDEX FROM employee_template_deletions;`
- `SHOW INDEX FROM clocks;`
- `SHOW INDEX FROM locations;`
- `SHOW INDEX FROM companies;`
