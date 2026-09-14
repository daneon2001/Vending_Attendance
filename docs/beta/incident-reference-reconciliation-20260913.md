# Reconciliaci?n de referencias del incidente

Fecha: 2026-09-13 UTC. Investigaci?n sin restauraci?n ni acceso a la DB principal.

REFERENCE RECONCILIATION: PASS  
REFERENCE COUNT: 14_CANONICAL_DOCUMENTATION_ERROR  
FINAL: READY_FOR_RESTORE_AUTHORIZATION

## Origen y alcance del error

La referencia documental m?s antigua localizada es docs/beta/internal-beta-closeout-13.7.1.md:371, cierre del 11/09/2026 a las 23:16:51 UTC. Dice 15, pero su archivo citado baseline-final-comparison.json contiene exactamente 14 claves ?nicas y 14 valores true. No contiene una entrada n?mero 15 ni una validaci?n externa adicional. El snapshot inicial de ese cierre inventariaba 26 tablas; no debe confundirse ese inventario amplio con el subconjunto final protegido. La lista final de 14 reaparece exactamente en los snapshots before/after y comparison de Phase 13.7.2, y en recovery-validation.json. Los campos field y activities de los snapshots son proyecciones de tablas ya incluidas, no datasets independientes adicionales.

El error est? en la prosa, no en un dataset perdido del archivo de comparaci?n. No se encontr? una lista de 15, un filtro n?mero 15, ni una identidad externa designada como tal. Los 14 son la lista can?nica completa del comparador hist?rico, no la totalidad de los datos que contiene el backup. La afirmaci?n anterior del agente de haber acreditado 15 fue incorrecta.

La comparaci?n suplementaria con las 26 tablas del snapshot del cierre da 25 hashes iguales; ?nicamente devices difiere. Ese snapshot fue escrito a las 23:13:12 UTC, despu?s del respaldo de las 23:09:04, y el propio cierre documenta last_seen/heartbeat/telemetr?a posteriores y excluye su estabilidad. Esto no convierte devices en un supuesto dataset 15. Sin filas hist?ricas completas no se atribuyen columnas concretas de esa diferencia s?lo por el hash.

## Referencias localizadas

Las fechas siguientes son mtime UTC de los archivos, no prueba de fecha de autor?a de cada l?nea. La fecha del cierre y los timestamps internos de snapshots aportan contexto adicional.

| Archivo | L?nea | mtime UTC | Referencia |
|---|---:|---|---|
| docs/beta/incident-test-database-20260913.md | 36 | 2026-09-13T04:27:11.115622+00:00 | Las 15 tablas protegidas coinciden por SHA256 con el baseline previo a este |
| docs/beta/incident-test-database-20260913.md | 45 | 2026-09-13T04:27:11.115622+00:00 | como recuperables; no confundir igualdad de 15 tablas con igualdad de toda la DB. |
| docs/beta/internal-beta-closeout-13.7.1.md | 371 | 2026-09-11T23:20:49.208531+00:00 | Comparación final 23:16:51 UTC: 15 conjuntos protegidos permanecen idénticos, |
| docs/beta/lan-rebinding-validation-13.7.2.md | 177 | 2026-09-13T03:33:36.467886+00:00 | Dos snapshots SELECT dentro de transacción READ ONLY, 15 tablas comparadas por |
| docs/beta/session-recovery-13.7.2.md | 8 | 2026-09-13T04:27:13.950092+00:00 | coincide en las 15 tablas protegidas. Restauración de la DB de uso pendiente |
| docs/beta/session-recovery-13.7.2.md | 45 | 2026-09-13T04:27:13.950092+00:00 | las 15 tablas protegidas son idénticas, incluida employee_devices completa con |
| storage/app/private/phase-13.7.2.1-incident/incident-recovery-result.txt | 13 | 2026-09-13T04:33:03.012350+00:00 | PROTECTED DATASETS: 14/14 MATCH in isolated copy; required 15/15 NOT established |

La b?squeda incluy? documentaci?n beta/operations/architecture, scripts, informes privados y archivos temporales del repositorio. Se excluyeron dependencias, binarios y dumps con datos. `.tmp_prepare_incident_recovery.php` itera las claves de baseline-after.json sin agregar un dataset. El guard posterior de restore.php exige 15 y por eso abort? antes de importar; ese guard no es evidencia hist?rica de un dataset adicional.

## Lista can?nica acreditada

Algoritmo hist?rico: SELECT de todas las filas, conversi?n a arrays PHP, orden lexicogr?fico por json_encode de cada fila y SHA256 del json_encode del array ordenado. No se sustituye por otro algoritmo. Fuente B: internal-beta-1.0.1-beta.1-build-2/baseline-before.json, subconjunto seleccionado en baseline-final-comparison.json. Fuente L: phase-13.7.2/baseline-before.json y baseline-after.json; timestamps internos 2026-09-13T03:20:46Z y 03:31:11Z. Todas las entradas siguientes coinciden con B y L y con la copia aislada.

| # | DATASET | TABLE(S) | FILTER | EXPECTED COUNT | EXPECTED HASH / IDENTITY | SOURCE OF EXPECTATION |
|---:|---|---|---|---:|---|---|
| 1 | attendance_logs | attendance_logs | NONE; todas las filas/columnas | 0 | `4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945` | B + L; MATCH aislada |
| 2 | vending_attendance_events | vending_attendance_events | NONE; todas las filas/columnas | 22 | `014af3b5205cb923e4e25a618e24060f781de8f9535ebfc680e501c1cf34bd19` | B + L; MATCH aislada |
| 3 | employees | employees | NONE; todas las filas/columnas | 2507 | `d67aee3f06337f17df1b0d5cea86a5c0f8a7d8cd12392876f7aebfb013d4c41e` | B + L; MATCH aislada |
| 4 | users | users | NONE; todas las filas/columnas | 5 | `8e0bb13186f72f48a07c4032b52054980c189a8a09dc545433f5cea1a9b79d29` | B + L; MATCH aislada |
| 5 | employee_machine_assignments | employee_machine_assignments | NONE; todas las filas/columnas | 8 | `42b9c3db65bb74eb8f4fe3b73df5053e8cfa87e425e7c2f95890857e71c303e5` | B + L; MATCH aislada |
| 6 | vending_machines | vending_machines | NONE; todas las filas/columnas | 5 | `596e8bdc05bdd38910bb49436e357a2e3952bd95905981a62e35cfb28b475e15` | B + L; MATCH aislada |
| 7 | machine_geofences | machine_geofences | NONE; todas las filas/columnas | 4 | `73c0aeb3241c75082d5f5cd4d3c2608ddd9972b6d15498be30f2e07e93684ee2` | B + L; MATCH aislada |
| 8 | employee_devices | employee_devices | NONE; todas las filas/columnas | 1 | `970e9eb2146e37d99877b9a603913adfe7a00a4b8cf9c47015f0bb88140f78f8` | B + L; MATCH aislada |
| 9 | field_device_otps | field_device_otps | NONE; todas las filas/columnas | 1 | `368a4953f47a60dfb728fa0628e767f1b78e4cc920d1fd21468c236e1932fe42` | B + L; MATCH aislada |
| 10 | vending_support_activities | vending_support_activities | NONE; todas las filas/columnas | 2 | `93154db80f5b43fb190231d5c1fab2801e21df79e674b6a016a77281f7f6579b` | B + L; MATCH aislada |
| 11 | vending_support_activity_events | vending_support_activity_events | NONE; todas las filas/columnas | 8 | `962ec05a53c9914b43b44c92251af1ed7a7e88c00239363f02b449c679df1f92` | B + L; MATCH aislada |
| 12 | support_activity_notes | support_activity_notes | NONE; todas las filas/columnas | 1 | `39d8c0c8e2f89fe75e2db5d05877206954adf868823412f33754de2cc8fdbb9f` | B + L; MATCH aislada |
| 13 | support_activity_evidence | support_activity_evidence | NONE; todas las filas/columnas | 1 | `de6792cd8c3b9db61d1d98ef7a2f5f21a0830cbe9dc672f2f1122992f244e714` | B + L; MATCH aislada |
| 14 | support_tickets | support_tickets | NONE; todas las filas/columnas | 2 | `07a7442daa3be569a6be035295a99ca1bdcccc5f1c90561a64e7e2d80395b0f9` | B + L; MATCH aislada |

Dataset 15: NONE. No se invent? un reemplazo ni se cambi? el universo para alcanzar una cifra.

## Backup y copia aislada

GOOD BACKUP SHA256: `6d090f4bbd3111755229c567d3a428e864fac63ac28e580cf6fd7f9f479fa5cd`. Recalculado y coincidente. El manifiesto identifica el archivo original de 2026-09-11 23:09:04 UTC. INCIDENT STATE BACKUP SHA256: `47b14b6f3f3149003737c10cf8099933bf2e09762c95fcc958430c362c471952`, tambi?n recalculado y preservado.

Se utiliz? exclusivamente vending_attendance_restore_test_20260913_incident, previamente restaurada desde el dump extra?do y verificado. No se volvi? a importar. Consultas mediante conexi?n independiente con nombre de base expl?cito, sin bootstrap de aplicaci?n ni conexi?n a vending_attendance_dev, dentro de transacci?n READ ONLY y rollback. No se consult? Fortia ni se ejecutaron tests.

Inventario completo de 82 tablas y sus hashes: storage/app/private/phase-13.7.2.1-incident/reference-isolated-audit.json. Columnas de esquema y evidencia cr?tica: reference-critical-evidence.json. Los reportes no contienen claves completas, contrase?as ni tel?fono.

## Baseline cr?tico

- EMPLOYEES: 2507; USERS: 5; ASSIGNMENTS: 8; MACHINES: 5; GEOFENCES: 4.
- ATTENDANCE: 0; VENDING ATTENDANCE EVENTS: exactamente 22.
- FIELD_MOBILE: ?nico registro id 1 ACTIVE; UUID a7807121-1079-4223-9fff-abe34043be6a; key_version 1; activated_at 2026-09-10 15:35:29.
- Owner: User 4 Pilot Support ? Employee 5 T?cnico Demo / 990001005. La FK del usuario confirma la misma relaci?n.
- Fingerprint: `f799df6dcd23f05d40594608d29e64d19793c0e6cfd3bc1a4face52494c7a702`. SHA256 del PEM almacenado coincide. La igualdad hist?rica de toda employee_devices acredita fingerprint, UUID, owner, versi?n y activated_at; no se efectu? challenge ni prueba del Keystore f?sico.
- Support: actividades 1 y 2 COMPLETED; cada timeline contiene created ? assigned ? started ? completed, ocho eventos en total. Nota 1 y evidencia 1 CONFIRMED pertenecen a actividad 2, Employee 5, FIELD_MOBILE 1; coinciden los hashes completos hist?ricos de las tablas. No se abri? el binario.
- SYBI 7: m?quina id 4, source SYBI, DRAFT, preservada por igualdad de tabla completa.
- Assignment 7: Employee 210 ? m?quina 4, PRIMARY, MANUAL, ACTIVE; preservada por igualdad de tabla completa.

## Esquema / migraciones

MIGRATIONS: MATCH. 105 nombres registrados en el backup y 105 archivos requeridos por database/migrations/*.php. Ning?n pendiente (PENDING_CODE_MIGRATION=0), ninguno adelantado (BACKUP_AHEAD=0). Tablas/columnas cr?ticas presentes y consultas resueltas. Compatibilidad PASS en este alcance; la tabla migrations no almacena checksums del contenido hist?rico de cada archivo, por lo que igualdad de nombres no es una certificaci?n criptogr?fica de su contenido. No se ejecutaron migrations ni artisan migrate:status sobre la base principal.

## Causa de las 37 diferencias y aislamiento de tests

Comando registrado en el incidente: variables de proceso APP_ENV=testing, DB_CONNECTION=sqlite y DB_DATABASE=:memory:, seguidas de php artisan test tests/Feature/FieldIdentity (51 fallos). No se us? --parallel en esa ejecuci?n. No se atribuye el incidente a un test MySQL personalizado.

Evidencia est?tica concreta:

1. bootstrap/cache/config.php, mtime 2026-09-13 03:25:33 UTC, fija app.env=local, database.default=mysql, database.connections.mysql.database=vending_attendance_dev. Se leyeron s?lo esos valores, sin imprimir credenciales.
2. vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/LoadEnvironmentVariables.php:21 omite carga de .env cuando existe configuraci?n cacheada. LoadConfiguration.php:25 carga esa cach? y :47 deriva app.env de ella. Las variables de PHPUnit no reemplazan los valores ya fijados dentro de ese array.
3. phpunit.xml previo al incidente solicitaba testing/sqlite/:memory:, bootstrap vendor/autoload.php, sin APP_CONFIG_CACHE aislado; no existe .env.testing en este workspace. La ausencia de force no fue por s? sola la causa: el comando ya fijaba las mismas variables, pero prevaleci? la cach?.
4. DeviceIdentityTest.php:34 llama parent::setUp() antes de sus asserts SQLite/:memory:. VendingDeviceApiTestCase.php:21 usa RefreshDatabase; el lifecycle del framework ejecuta setUpTraits y refreshDatabase antes de retornar a esos asserts.
5. RefreshDatabase.php:79 ejecuta migrate:fresh sobre la conexi?n efectiva y s?lo despu?s beginDatabaseTransaction. El rollback de los tests no recupera los datos previos borrados por esa recreaci?n. El archivo TestCase.php previo carec?a del guard de createApplication.
6. El inventario del estado afectado demuestra 82 tablas a?n presentes, 34 tablas de las 37 diferentes vac?as, migrations recreada con 105 filas (82 filas con diferencias) y residuos en permissions (11) y support_runtime_cursors (4). Las migraciones del repositorio contienen updateOrInsert de permissions e inserciones iniciales de support_runtime_cursors; no se atribuyen esos residuos a nuevas operaciones humanas.

Los guards actuales en tests/TestCase.php y phpunit.xml fueron introducidos despu?s del incidente, antes de esta investigaci?n; git diff lo demuestra. No se modificaron ahora ni se ejecutaron para reproducir el fallo. Esta investigaci?n confirma el mecanismo a partir de c?digo, cach? e inventario previo; no existe aqu? una traza SQL exhaustiva para adjudicar cada escritura a un m?todo individual.

## Clasificaci?n de diferencias

Fuente: damage-inventory.json preservado antes del intento de restore. No se reconsult? la DB principal. Los n?meros son filas de checkpoint y estado afectado. TEST_ARTIFACT se usa para residuos estructurales compatibles con la recreaci?n por migrations, no para inventar fixtures no observadas. SESSION incluye tokens/desaf?os; TELEMETRY incluye auditor?a t?cnica. BUSINESS_DATA incluye configuraci?n de negocio y autorizaci?n. La causalidad fina de cada timestamp queda limitada por la evidencia disponible.

| Tabla | Clase | Checkpoint | Afectada | Interpretaci?n |
|---|---|---:|---:|---|
| audit_logs | TELEMETRY | 2798 | 0 | Vac?a tras recreaci?n |
| device_attendance_metrics | TELEMETRY | 2 | 0 | Vac?a tras recreaci?n |
| device_manifest_states | TELEMETRY | 5 | 0 | Vac?a tras recreaci?n |
| device_nonces | SESSION | 2533 | 0 | Vac?a tras recreaci?n |
| device_provisioning_tokens | SESSION | 7 | 0 | Vac?a tras recreaci?n |
| devices | BUSINESS_DATA | 3 | 0 | Vac?a tras recreaci?n |
| employee_devices | BUSINESS_DATA | 1 | 0 | Vac?a tras recreaci?n |
| employee_import_rows | BUSINESS_DATA | 5004 | 0 | Vac?a tras recreaci?n |
| employee_import_runs | BUSINESS_DATA | 2 | 0 | Vac?a tras recreaci?n |
| employee_machine_assignments | BUSINESS_DATA | 8 | 0 | Vac?a tras recreaci?n |
| employees | BUSINESS_DATA | 2507 | 0 | Vac?a tras recreaci?n |
| field_device_audit_events | TELEMETRY | 145 | 0 | Vac?a tras recreaci?n |
| field_device_challenges | SESSION | 52 | 0 | Vac?a tras recreaci?n |
| field_device_otps | SESSION | 1 | 0 | Vac?a tras recreaci?n |
| machine_geofences | BUSINESS_DATA | 4 | 0 | Vac?a tras recreaci?n |
| migrations | TEST_ARTIFACT | 105 | 105 | Filas recreadas/defaults de migraci?n; detalles en inventario |
| notifications | BUSINESS_DATA | 15 | 0 | Vac?a tras recreaci?n |
| permission_role | BUSINESS_DATA | 40 | 0 | Vac?a tras recreaci?n |
| permissions | TEST_ARTIFACT | 77 | 11 | Filas recreadas/defaults de migraci?n; detalles en inventario |
| personal_access_tokens | SESSION | 1 | 0 | Vac?a tras recreaci?n |
| role_user | BUSINESS_DATA | 5 | 0 | Vac?a tras recreaci?n |
| roles | BUSINESS_DATA | 5 | 0 | Vac?a tras recreaci?n |
| support_activity_evidence | BUSINESS_DATA | 1 | 0 | Vac?a tras recreaci?n |
| support_activity_notes | BUSINESS_DATA | 1 | 0 | Vac?a tras recreaci?n |
| support_evidence | BUSINESS_DATA | 2 | 0 | Vac?a tras recreaci?n |
| support_operations | BUSINESS_DATA | 17 | 0 | Vac?a tras recreaci?n |
| support_runtime_cursors | TEST_ARTIFACT | 5 | 4 | Filas recreadas/defaults de migraci?n; detalles en inventario |
| support_ticket_events | BUSINESS_DATA | 7 | 0 | Vac?a tras recreaci?n |
| support_tickets | BUSINESS_DATA | 2 | 0 | Vac?a tras recreaci?n |
| support_verifications | BUSINESS_DATA | 2 | 0 | Vac?a tras recreaci?n |
| sybi_vending_source_records | BUSINESS_DATA | 5 | 0 | Vac?a tras recreaci?n |
| sybi_vending_sync_runs | TELEMETRY | 12 | 0 | Vac?a tras recreaci?n |
| users | BUSINESS_DATA | 5 | 0 | Vac?a tras recreaci?n |
| vending_attendance_events | BUSINESS_DATA | 22 | 0 | Vac?a tras recreaci?n |
| vending_machines | BUSINESS_DATA | 5 | 0 | Vac?a tras recreaci?n |
| vending_support_activities | BUSINESS_DATA | 2 | 0 | Vac?a tras recreaci?n |
| vending_support_activity_events | BUSINESS_DATA | 8 | 0 | Vac?a tras recreaci?n |

## Inventario del backup aislado

| Tabla | Filas |
|---|---:|
| areas | 0 |
| attendance_changes | 0 |
| attendance_dailies | 0 |
| attendance_logs | 0 |
| attendances_raw | 0 |
| audit_cleanup_runs | 0 |
| audit_cleanup_settings | 0 |
| audit_logs | 2798 |
| cache | 0 |
| cache_locks | 0 |
| centros_costo | 0 |
| clock_logs | 0 |
| clocks | 0 |
| companies | 0 |
| departamentos | 0 |
| device_attendance_metrics | 2 |
| device_manifest_states | 5 |
| device_nonces | 2533 |
| device_provisioning_tokens | 7 |
| devices | 3 |
| empleados | 0 |
| employee_allowed_locations | 0 |
| employee_details | 0 |
| employee_devices | 1 |
| employee_face_templates | 0 |
| employee_fingerprints | 0 |
| employee_import_metadata | 0 |
| employee_import_rows | 5004 |
| employee_import_runs | 2 |
| employee_machine_assignments | 8 |
| employee_scope_deletions | 0 |
| employee_status_changes | 0 |
| employee_sync_states | 0 |
| employee_template_deletions | 0 |
| employees | 2507 |
| enrolment_audits | 0 |
| failed_jobs | 0 |
| field_device_audit_events | 145 |
| field_device_challenges | 52 |
| field_device_otps | 1 |
| job_batches | 0 |
| jobs | 0 |
| locations | 0 |
| machine_geofences | 4 |
| migrations | 105 |
| mobile_release_policies | 0 |
| mobile_release_targets | 0 |
| mobile_releases | 0 |
| notifications | 15 |
| password_reset_tokens | 0 |
| periodos_pago | 0 |
| permission_role | 40 |
| permissions | 77 |
| personal_access_tokens | 1 |
| puestos | 0 |
| razones_sociales | 0 |
| registros_imss | 0 |
| role_user | 5 |
| roles | 5 |
| sessions | 0 |
| shift_profile_days | 0 |
| shift_profiles | 0 |
| support_activity_evidence | 1 |
| support_activity_notes | 1 |
| support_correlations | 0 |
| support_evidence | 2 |
| support_integration_machines | 0 |
| support_integrations | 0 |
| support_operations | 17 |
| support_policy_versions | 0 |
| support_runtime_cursors | 5 |
| support_ticket_events | 7 |
| support_tickets | 2 |
| support_verifications | 2 |
| sybi_vending_source_records | 5 |
| sybi_vending_sync_runs | 12 |
| ubicaciones | 0 |
| users | 5 |
| vending_attendance_events | 22 |
| vending_machines | 5 |
| vending_support_activities | 2 |
| vending_support_activity_events | 8 |

## Salida requerida

REFERENCE RECONCILIATION: PASS
ORIGINAL "15" SOURCE: docs/beta/internal-beta-closeout-13.7.1.md:371; contradice las 14 claves del JSON que cita.
CANONICAL DATASETS: 14
MISSING DATASET: NONE
REFERENCE COUNT: 14_CANONICAL_DOCUMENTATION_ERROR
GOOD BACKUP: VERIFIED
GOOD BACKUP SHA256: 6d090f4bbd3111755229c567d3a428e864fac63ac28e580cf6fd7f9f479fa5cd
ISOLATED RESTORE: PASS (existente, auditada; no nueva importaci?n)
EMPLOYEES: 2507
ATTENDANCE: 0
VENDING ATTENDANCE EVENTS: 22
SUPPORT ACTIVITIES: 2
SUPPORT ACTIVITIES COMPLETED: 2
FIELD_MOBILE: ACTIVE
SAME DEVICE UUID: YES
SAME KEY FINGERPRINT: YES
SYBI 7: PRESERVED
ASSIGNMENT 7: PRESERVED
SCHEMA COMPATIBILITY: PASS
MIGRATIONS: MATCH, 105; pending 0; backup ahead 0
37 TABLE DIFFERENCES ROOT CAUSE: Recreaci?n de esquema por RefreshDatabase; p?rdida de datos previos y defaults de migrations remanentes.
TEST ISOLATION ROOT CAUSE: Config cache local/mysql/dev prevaleci? antes de asserts de aislamiento, que se ejecutaban despu?s de parent::setUp.
MAIN DB MODIFIED DURING INVESTIGATION: NO
RESTORE: NOT_RUN
FINAL: READY_FOR_RESTORE_AUTHORIZATION

Este resultado no autoriza ni ejecuta restauraci?n. No implementaci?n, OTP, challenge, enrolamiento, modificaci?n de claves, commit, tag, push ni deploy. La base principal contin?a afectada.
