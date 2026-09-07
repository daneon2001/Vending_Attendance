# Employee integration — discovery, decisions and validation

Discovery: 2026-09-05. Cierre de validación: 2026-09-06. EMPLOYEE INTEGRATION PHASE: **PARTIAL**.
FINAL: **WAITING_FOR_FORTIA_API**. No equivale a autorización de producción.

## Baseline y límites

- Trabajo: `C:/laragon/www/vending-attendance`, rama `phase/10-employee-integration`.
- Baseline aprobado: `vending-phase-9-pass`, `a246f0b659d3f2e103a7641c93138e19b791066d`.
- Referencia `C:/laragon/www/ASISTENCIAS_FORTIA`: exclusivamente lectura de archivos, búsquedas y git con `--no-optional-locks`. Estado git limpio al inicio y en la verificación posterior. No se ejecutaron comandos de aplicación, migraciones, configuración ni escrituras allí.
- El worktree Vending ya tenía cambios de identidad, enums, modelos/migraciones de staging, proyección de Employee/manifest y seeder demo. Se conservaron. No se restauraron archivos ni se atribuyen esos cambios previos a esta ejecución.
- Sin commit, push, merge, deploy, cambios de producción, biometría, Biometric Manifest, protocolo de asistencia, HMAC, SYBI ni arquitectura fleet. Se reutiliza el observer existente para el **Employee Manifest** operacional.
- No se modificó `.env`, no se inventaron contraseñas, no se crearon empleados reales ni se escribió hacia Fortia. `.env.example` sólo documenta variables vacías y defaults cerrados.

## A. Discovery de usuarios, roles y permisos

Inspeccionados antes del trabajo de importación: `app/Models/{User,Role,Permission}.php`, `config/permissions.php`, `app/Actions/SyncPermissionCatalog.php`, `database/seeders/{RolePermissionSeeder,VendingDemoSeeder}.php`, middleware y rutas administrativas.

Hechos del discovery inicial de la base local `vending_attendance_dev`:

- Un usuario: id 1, **Administrador Vending Demo**, `admin.vending.local@example.test`, activo.
- Rol asignado: **Vending Demo Admin**. Permisos: `vending_machines.view/create/update/assign/geofence/manage`.
- Cinco empleados demo preexistentes. No se imprimieron hashes ni valores de credenciales.
- En la revisión inicial no estaban disponibles las cuatro variables de contraseña. En la comprobación final ya estaban disponibles: **cuatro cuentas piloto creadas** con el seeder local protegido, sin mostrar valores ni hashes. Dos ejecuciones exit 0; mismos usuarios/contraseñas después de repetirlo; empleados permanecen en cinco. No se modificó el administrador demo existente.

Modelo real: RBAC propio con pivotes role_user y permission_role; no Spatie ni un modelo tenant SaaS. No hay directorio de Policies. `User::hasPermission(module, action)` admite `manage` para todas las acciones del módulo. `EnsurePermission` admite además settings.manage; `EnsureStrictPermission` no admite ese atajo. Las rutas nuevas usan este último.

Roles del seeder general: Administrador, Capturista, Supervisor y Consulta. El seeder general usa sync de permisos y puede asignar administrador al primer usuario: **no se ejecuta para el piloto**. Ya existen employees.view/sync/import/manage; no se creó un permission model paralelo.

### PILOT USERS

Estado final: **4/4 cuentas creadas localmente**. Los permisos se agregan sin quitar permisos preexistentes de roles administrados; una colisión de nombre/email/rol ajeno aborta toda la transacción. No se rota la contraseña de cuentas existentes.

| USER / email | ROLE | PERMISSIONS | PURPOSE |
|---|---|---|---|
| pilot.admin@example.test / Pilot Admin | Vending Pilot Admin | employees: view, sync, import, manage; vending_machines: view, create, update, assign, geofence, manage | Administrar catálogo y operación piloto, no administrar la plataforma completa |
| pilot.operator@example.test / Pilot Operator | Vending Pilot Operator | employees: view, sync, import; vending_machines: view, assign | Cargar archivos controlados, revisar sincronización y asignar explícitamente |
| pilot.support@example.test / Pilot Support | Vending Pilot Support | employees: view; vending_machines: view | Soporte de sólo lectura |
| pilot.viewer@example.test / Pilot Viewer | Vending Pilot Viewer | employees: view; vending_machines: view | Consulta de sólo lectura |

`VendingPilotUsersSeeder` exige contraseñas locales de al menos 12 caracteres para todas las cuentas ausentes, valida todo antes de escribir, usa syncWithoutDetaching y no imprime valores. Falla fuera de local/testing salvo autorización explícita mediante `VENDING_PILOT_USERS_ALLOW_PRODUCTION` (false por defecto; no habilitado).

Provisionamiento ejecutado con las variables disponibles en el entorno local: `php artisan db:seed --class=VendingPilotUsersSeeder`. Se repitió para comprobar idempotencia, sin rotar contraseñas. No se ejecutó el seeder general ni el demo completo. Ninguna contraseña debe pegarse en el reporte o en el chat.

## B. LEGACY EMPLOYEE IMPORT INVENTORY

Rutas en esta tabla son relativas a ASISTENCIAS_FORTIA, inspeccionado READ ONLY. REUSABLE significa reutilización conceptual o compatibilidad, no copia literal. ADAPT/DISCARD explicitan el tratamiento en Vending.

| FILE | RESPONSIBILITY | DEPENDENCIES | REUSABLE | ADAPT | DISCARD | REASON |
|---|---|---|---|---|---|---|
| app/Http/Controllers/Employees/EmployeeImportController.php | Preview, crear catálogos faltantes, import POST | Requests, ExcelImportService, AuditLogger, Log | Secuencia administrativa | Controlador nuevo con staging actor-bound | Reenviar archivo al aplicar, detalles crudos de excepción | La confirmación debe vincularse al preview aprobado |
| app/Http/Requests/Employees/ImportEmployeesExcelRequest.php | Archivo XLSX, 10 MB; authorize true | MIME/extensión, middleware de rutas | Validar antes de parsear | CSV + XLSX, 5 MB configurables, MIME real y límites internos | XLSX/octetstream/zip como confianza suficiente | La autorización se aplica también en backend; ZIP necesita límites propios |
| app/Http/Requests/Employees/CreateMissingEmployeeCatalogsRequest.php | Validar petición de creación de catálogos | Import XLSX, catálogo laboral | No para piloto | Ninguna creación automática | Creación masiva de estructura laboral | Sucursal/unidad no equivale a vending |
| app/Services/Employees/EmployeeExcelImportService.php | Analizar filas, actualizar/crear Employee, bajas y metadata; lotes 500 | Reader, catalog resolver, Employee, details, status changes | Encabezados CLA_TRAB/NOMBRE/ESTATUS_TRABAJADOR, A/B, diff conceptual | Normalizador/reader/staging/proyección mínimos independientes | Cast numérico de CLA_TRAB, PII de nómina, abortar todo por fila inválida, aplicar archivo reenviado | Preservar ceroes, ownership y aprobación exacta |
| app/Services/Employees/EmployeeExcelCatalogResolutionService.php | Resolver/crear empresas, unidades, razón social, IMSS, puesto, costo, área, departamento y periodo | Modelos de catálogos de nómina | Sólo evidencia para discovery | No invocarlo desde el flujo nuevo | Catálogos automáticos | No son necesarios para identidad Vending ni asignaciones |
| app/Support/TabularDataReader.php | CSV y primer worksheet XLSX; strings compartidos, inline y rich text | ZipArchive, SimpleXML | Lectura de valores sin ejecutar Excel | Reader acotado: ZIP/XML, fórmulas, UTF-8, una hoja y formato 00000 | ZIP sin límite, fórmula con valor cacheado aceptada como dato de identidad | Defensa contra archivos hostiles y alteración de números |
| resources/js/Pages/Employees/Partials/ImportEmployeesModal.vue | Validar, revisar catálogos y reenviar archivo para aplicar | Axios, modal, endpoints legacy | UX preview/confirmar | Wizard con uuid/hash, estados, paginación y diff | Re-upload final y creación de catálogos | El servidor conserva las filas aprobadas |
| resources/js/Pages/Employees/EmployeesCatalog.vue | Catálogo amplio laboral/biométrico y modal de import | APIs admin, filtros y componentes existentes | Navegación/convenciones visuales | Página VendingCatalog con identidad/fuente/estado/sync | PII y acciones biométricas en la nueva página | Minimización; página legacy permanece compatible |
| routes/api.php | /api/admin/employees/import/preview, /catalogs/missing/create y /import | auth:web,sanctum, rol administrador, perm.strict:employees,import | Permiso existente | Nuevas rutas web strict; rutas antiguas con opt-in de compatibilidad | Uso del import directo como flujo piloto | No retirar rutas sin coordinación |
| tests/Feature/Admin/EmployeeExcelImportTest.php | Cobertura extensa de import laboral, catálogos, bajas y variantes XLSX | Esquemas/fixtures históricos | Regresión legacy y patrones de fixtures | Conservada con flag legacy explícito en setup; nueva suite de staging separada | Requerir paridad de nómina | Pruebas sintéticas, sin acceso al origen real |
| database/migrations/2026_05_04_170000_add_employees_import_permission.php | employees.import | Catálogo de permisos | Sí, permiso ya disponible | Seeder piloto lo reutiliza | Duplicar permiso | Menor privilegio con convención existente |
| database/migrations/2026_05_04_170100_create_employee_import_metadata_table.php | Payload laboral JSON persistente | Employee/metadata | No para import nuevo | Runs compactos y filas temporales mínimas | Copia indefinida de nómina/PII | Retención limitada y minimización |
| database/migrations/2026_04_22_125624_create_employee_details_table.php | Datos laborales y personales extendidos | Employee/details | Sólo compatibilidad histórica | Sin escrituras nuevas | Poblar RFC/CURP/NSS/domicilio/salario/etc. | Sin necesidad operativa demostrada |
| database/migrations/2026_07_17_120000_add_employment_dates_to_employees_table.php | Fechas laborales | Employee, bajas | Semántica histórica como evidencia | No importar fechas sin contrato | Inferir fechas o antigüedad | Sólo timestamps de fuente cuando existen y son válidos |
| database/migrations/2025_12_11_230840_create_employees_table.php | PK bigint y fortia_employee_id numérico único | Base Employee | PK interno | Identidad operacional string y source ID separados | Inventar IDs Fortia para manuales | Los ceros perdidos históricamente no pueden recuperarse por inferencia |
| app/Services/Fortia/FortiaEmployeeService.php | Lectura DB incremental, cursor, payroll mapping y scope | Conexión fortia, EmployeeSyncState, EmployeeStatusChange, DB, catálogos | Firma pública/operación de sync | Fachada compatible hacia servicio mínimo | Copia PII y sincronización automática de scope | No era un cliente API HTTP |
| app/Services/FortiaMock/FortiaMockSyncService.php | Proyección incremental de mock DB | FortiaMockEmployee, estados/cursor | Modo mock explícito | Misma política mínima; fuente DEMO | Copiar PII y saltar ownership | No presentar mock como integración real |
| app/Services/Fortia/FortiaAuthService.php | Placeholder de autenticación | Config Fortia | Evidencia de ausencia de auth real | Token del adaptador HTTP separado y sólo configurable | Tratar dummy_token como credencial validada | La autenticación real aún requiere contrato |
| app/Models/EmployeeSyncState.php | Cursor y resultado incremental | Tabla sync states | Evidencia del modelo DB anterior | El nuevo dry-run no toca cursores | Cursor incremental sin contrato de paginación/cambios | Evitar perder empleados por interpretación parcial |
| app/Models/FortiaMockEmployee.php | Modelo del origen mock | Conexión fortia_mock | Lectura mock | Cliente DB selecciona sólo columnas mínimas | Serializar registro completo | Datos mínimos y sin escrituras al origen |
| app/Console/Commands/FortiaSyncEmployees.php | Sync, alineación de catálogos y detalles | Servicios/seeder extendidos | Nombre del comando y filtros | Default dry-run, --apply + write gate; flags legacy aceptados | Ejecutar catálogo/details implícitamente | Una consulta no debe producir escrituras |
| app/Console/Commands/FortiaMockSyncEmployees.php | Ejecutar sync mock | MockSyncService | Firma de comando | Recibe métricas mínimas vía fachada, gate obligatorio | Lista de cambios con PII deja de generarse | Compatibilidad sin payload completo |
| app/Http/Controllers/Api/EmployeeController.php | Listado, sync-fortia y estado | Servicios, recursos, permisos | Ruta/estructura de respuesta | Fachadas seguras y 409 para cambio manual de estado FORTIA | Override silencioso source-owned | Una ruta antigua no debe eludir ownership |
| app/Http/Controllers/Api/FortiaMock/FortiaMockSyncController.php | Ejecutar proyección desde mock local | dev.only.api, MockSyncService | Ruta local | Se agrega auth y employees.sync al endpoint de proyección | Sync anónimo incluso local | El modo desarrollo no sustituye permisos |
| composer.json / composer.lock | maatwebsite/excel 3.1.56 y PhpSpreadsheet transitivo | ZipArchive, XML, PHP | Dependencias existentes para fixtures/export legado | Reader seguro nuevo no evalúa fórmulas | Instalar nuevas dependencias | No necesario para CSV/XLSX mínimo |
| routes/console.php / app/Jobs | Scheduling y trabajos | Scheduler Laravel | Infraestructura existente | Sólo TTL de staging, hourly | Programar sync API sin contrato | No se encontró un job/scheduler específico del import; flujo legacy era síncrono |

## C. Dominio actual, mapping y ownership

`Employee.id` sigue siendo el PK interno. No se usa el nombre como identidad y no se generan números laborales. `EmployeeMachineAssignment` sigue siendo entidad separada con máquina, tipo, vigencia, permisos y estado propios.

| SOURCE FIELD / significado | EMPLOYEE FIELD | Regla |
|---|---|---|
| Número laboral Fortia / CLA_TRAB / numero_empleado | employee_number | String operacional, 120 caracteres, trim/BOM/Unicode; conserva ceros |
| ID interno del proveedor | source_external_id | String, hasta 191; único junto con source; no es el PK local |
| Nombre completo | full_name | Sin cambiar arbitrariamente mayúsculas/minúsculas ni descomponer nombres |
| Estado activo/inactivo explícito | status | A/B; acepta alias documentados; valor desconocido es inválido |
| Modo de procedencia | source | FORTIA, MANUAL, DEMO; LEGACY es procedencia histórica aún no certificada |
| Fecha de cambio enviada por la fuente | source_updated_at | Timestamp parseable, UTC; no inventar si falta ni borrar uno conocido |
| Sincronización aplicada | source_synced_at | Hora local del procesamiento guardada como timestamp |
| Identificador numérico histórico existente | fortia_employee_id | Nullable por compatibilidad; no se fabrica para un empleado MANUAL |
| PK autoincremental local | id | Referencias internas/FKs; no identidad operacional del archivo |

La migración de identidad backfill convierte el fortia_employee_id existente a texto y marca LEGACY. No puede reconstruir ceros ya perdidos ni afirmar procedencia FORTIA/DEMO sin evidencia. La adopción de esos registros por API requiere mapping revisado; no se fusionan automáticamente por nombre o número parecido.

Ownership:

- FORTIA: employee_number, source_external_id, full_name, status y timestamps de fuente sólo por sync aprobada. Import manual con cambios → CONFLICT_SOURCE; datos idénticos → UNCHANGED. Nunca cambia a MANUAL.
- MANUAL: full_name/status pueden modificarse con preview, diff y confirmación. Nuevos registros conservan exactamente el número informado.
- DEMO y LEGACY no son apropiados silenciosamente por import ni API. Cualquier cambio de ownership es una decisión posterior explícita.
- Local-owned: asignaciones a vending, vigencias y permisos operacionales, PK y estados locales existentes. No se mapean sucursal/unidad a vending.
- Payroll/PII desconocida se descarta antes del staging/mapping. No se escriben details ni import_metadata.

El nuevo catálogo selecciona columnas explícitas vía query builder: no serializa los appends biométricos del modelo. El binding genérico mantiene compatibilidad para IDs enteros canónicos legacy y permite números string cuando no hay coincidencia legacy; un número con ceros no se convierte a entero. En integración, la identidad se consulta explícitamente por employee_number, no mediante la ambigüedad del binding genérico.

Manifest: EmployeeObserver existente llama EmployeeManifestVersionService. Cambios relevantes: employee_number, fortia_employee_id, name, last_name, second_last_name, full_name, status. Se incrementan sólo vending con asignaciones efectivas/activas relevantes; timestamps/source metadata no incrementan. Sin crear asignaciones ni implementar otro versionador.

## D. CURRENT FORTIA y contrato

**FORTIA CURRENT STATE: MOCK (adaptador DATABASE); REAL_API: no verificada; integración HTTP: PARTIAL.**

Configuración local observada (sin imprimir credenciales ni URL):

- FORTIA_SYNC_DRIVER=mock.
- FORTIA_SYNC_CONNECTION=fortia_mock; FORTIA_SYNC_TABLE=fortia_employees.
- FORTIA_BASE_URL está definido; esto no demuestra un API real.
- Servicios originales leen DB; FortiaAuthService tiene autenticación placeholder/dummy.
- No había scheduler de sincronización de empleados. No se agrega uno sin contrato.

Separación implementada: FortiaEmployeeClient → cliente DB o HTTP → FortiaEmployeeMapper → FortiaEmployeeSyncService. La selección de campos DB usa el esquema realmente encontrado: employee_id, id, name/last_name/second_last_name, status, updated_at.

El adaptador HTTP es un contrato **normalizado provisional**, no una afirmación sobre nombres reales de Fortia: respuesta data[] con employee_number string, source_external_id, full_name, status y source_updated_at opcional. Campos extra se descartan. Hasta confirmar el contrato real exige flag explícito HTTP_CONTRACT_APPROVED, path y token; HTTPS sin credenciales en URL, sin redirects, timeout 20 s, connect 5 s, máximo 5 MB/5000 registros. Paginación reconocida como incompleta se rechaza, no se aplica como snapshot. No reintentos automáticos frente a errores contractuales.

Blockers exactos:

1. Endpoint/version/path reales aprobados y acceso de red verificado.
2. Autenticación real, permisos mínimos, custodia/rotación de credenciales (no dummy_token).
3. Esquema real, tipos, número laboral string y separación del ID interno.
4. Paginación/cursor, orden estable, timestamps/zona horaria, semántica de bajas y registros eliminados.
5. Confirmar si entrega snapshot completo o delta; política de ausencias y reconciliación autorizada.
6. Límites, rate limit y política de reintentos/backoff; SLA operativo.
7. Revisar dry-run con muestra autorizada antes de permitir cualquier WRITE local de datos reales.
8. Mapping explícito de las identidades LEGACY preexistentes; sin adopción silenciosa.

Reconciliación implementada hasta entonces: **EXPLICIT_STATUS_ONLY**. Fuente vacía/parcial no desactiva ausentes. Duplicados de número/ID externo son conflictos en todas sus apariciones; número/ID/source incompatibles y timestamps regresivos se excluyen.

`fortia:sync-employees` sin flags es dry-run. `--dry-run` consulta, valida y calcula métricas sin Employee, auditorías, cursores ni asignaciones. `--apply` exige además FORTIA_EMPLOYEES_ALLOW_WRITE=true, false actualmente. Se conservan filtros y flags --skip-details/--skip-catalog-sync como compatibilidad sin escrituras adicionales.

Fachadas legacy conservan rutas, métodos y contadores new/updated/unchanged/status_changed; changed queda vacío para no devolver PII ni inventar listas de cambios. El endpoint mock de proyección ahora exige autenticación y employees.sync además de dev.only.api. El import legacy directo queda detrás de EMPLOYEE_LEGACY_IMPORT_ENABLED=false; su habilitación reversible requiere una contingencia aparte y **no es el flujo piloto**. Aun habilitado rechaza identidades de fuente protegida. Se descartó retirar rutas con 410 porque rompería consumidores sin autorización.

### API DRY RUN

Ejecutado: **YES**, `php artisan fortia:sync-employees --dry-run`, exit 0.
Origen: **MOCK DB**, no API real. REAL API DRY RUN: **NO**.

| received | created candidate | updated candidate | unchanged | inactive | rejected | conflicts |
|---|---|---|---|---|---|---|
| 0 | 0 | 0 | 0 | 0 | 0 | 0 |

No se ejecutó --apply ni se habilitó la bandera de escritura.

## E. IMPORT y UI

Upload → Parse → Normalize → Validate → Preview/Diff → Confirm → Apply.

- Persistencia temporal en employee_import_runs / employee_import_rows porque preview y confirmación son requests distintos.
- Archivo en temp privado de PHP; no se copia a public ni a almacenamiento permanente. No binarios en DB.
- SHA-256 para trazabilidad de reimportación. Idempotencia por employee_number + fuente y estado del run, no por hash solamente.
- Filas mínimas con número real de fila, clasificación, errores field/code/reason y diferencias antes/después.
- VALID_NEW, VALID_UPDATE, UNCHANGED, INVALID, DUPLICATE_FILE, CONFLICT_SOURCE. Todas las apariciones de duplicados se excluyen; una fila inválida no bloquea las válidas.
- Preview paginado (50 default), resumen total/válidos/nuevos/cambios/unchanged/inválidos/duplicados/conflictos y mapping visible.
- Apply exige permiso employees.import, usuario iniciador, UUID y preview_hash del servidor, confirmed explícito. Otro actor recibe 404, aun con permiso import. Archivo/filas del cliente no sustituyen staging.
- Una transacción acotada al lote completo, locks de run/empleados, índices únicos y reintentos transaccionales. Una falla revierte todas las altas/cambios; FAILED conserva código seguro. COMPLETED repetido no aplica ni audita nuevamente.
- El apply recalcula clasificación/diff bajo lock. Si el catálogo cambió: actualiza preview, devuelve 409 y exige reconfirmación; no aplica empleados.
- Sin borrar/desactivar ausentes, sin copiar nómina ni crear vending/assignments.

Límites: CSV UTF-8 separado por coma; XLSX una sola hoja; 5 MB; 5000 filas; 100 columnas; 256 entradas ZIP; expansión total 50 MB; rechazo de compresión desproporcionada. XLS/XLSM no admitidos: mantener fuera formatos binarios/macros innecesarios para este piloto.

XLSX admite shared strings/inline/rich text y formatos numéricos simples 00000; no ejecuta fórmulas ni usa su resultado cacheado para aplicar. Rechaza fórmulas por fila, macros, objetos embebidos, enlaces externos, paths peligrosos, DOCTYPE/ENTITY, XML corrupto y ZIP inseguro. Celdas con identificadores de más de 15 dígitos deben guardarse como texto: un número ya redondeado por Excel no es recuperable.

### COLUMN MAPPING documentado

| Destino | Alias admitidos |
|---|---|
| employee_number | CLA_TRAB, numero_empleado, employee_number, Numero de empleado, Número de empleado, No. empleado |
| full_name | NOMBRE, Nombre completo, Empleado, full_name |
| status | ESTATUS_TRABAJADOR, Estado, Activo, status |

Matching sólo de estos aliases, ignorando acentos/case/separadores del encabezado; columnas ambiguas se rechazan. Valores no se transforman a números. Unicode normalizado NFC cuando intl está disponible; UTF-8 inválido y caracteres de control se rechazan. No columnas de fecha en import mínimo. Estados A/ACTIVO/ACTIVE/true/1 y B/BAJA/INACTIVO/INACTIVE/false/0 se normalizan conforme al normalizador; no se infiere estado por ausencia.

UI administrativa: `/vending/employees`, navegación Empleados, tabla mínima con source/estado/última sincronización, filtros y paginación. Sync muestra el driver real, dry-run y bloqueo de escritura. Wizard muestra archivo, mapping, validación, diff, confirmación y resultado; seleccionar archivo no aplica. Reusa Modal/tokens existentes; sin nuevas dependencias frontend, sin almacenamiento persistente del archivo en navegador. Validación visual/manual en navegador: pendiente; las cuentas ya están provisionadas. No se afirma E2E visual PASS.

## Auditoría y retención

AuditLogger reutilizado. Eventos: employee.import.started/completed/failed, employee.sync.started/completed/failed y employee.created/updated/status_changed para mutaciones relevantes. Sin eventos por UNCHANGED ni payload proveedor/archivo completo. Dry-run no escribe auditoría.

Runs conservan uuid, actor, nombre sanitizado, extensión, SHA-256, mapping, contadores, estado y tiempos. Filas mínimas expiran a las 24 horas. `employees:prune-imports` elimina únicamente filas staging expiradas y conserva metadata compacta; programado hourly sin solapamiento. Requiere scheduler operativo. No elimina Employee ni evidencia de asistencia. La retención de metadata compacta de runs requiere política operativa posterior.

## LEGACY PARITY

| FEATURE | LEGACY | VENDING | SAME | IMPROVED | NOT_NEEDED |
|---|---|---|---|---|---|
| Cargar XLSX | Sí | Sí, parser acotado | Sí | ZIP/XML/fórmulas | — |
| CSV administrativo | Reader parcial, request XLSX | CSV UTF-8 end-to-end | — | Sí | — |
| Preview/errores/mapping | Sí | Persistente, paginado, clasificación y diff | Concepto | Confirmación vinculada y errores por fila | — |
| Aplicación | Re-upload, lotes, todo inválido bloquea | Staging, confirmación, parcial seguro, transacción | Altas/cambios | Idempotencia y stale preview | — |
| Número laboral | Cast int | String/ceroes preservados | Identidad operacional | Sin número artificial | — |
| Fuente Fortia | Sin ownership tipado en import | Conflictos y proyección mínima | — | Sí | — |
| Crear catálogos nómina | Sí | No | — | — | Sí |
| Copia RFC/CURP/NSS/salarios/domicilio | Sí | No | — | Minimización | Sí |
| Asignaciones | Scope sucursal legado | Asignación vending explícita y separada | — | Sin automatismos | Sucursal→vending implícito |
| Sync | Mock/DB | DB/mock + abstracción HTTP bloqueada | Modo DB/mock | Dry-run y seguridad | PII/details/scope automático |
| Manifest operacional | Versionador existente en Vending | Reutilizado, sólo máquinas afectadas | Sí | Identidad string | Reinventar versionador |
| Limpieza | Archivo reenviado/metadata amplia | TTL staging y auditoría compacta | — | Sí | Binario persistente |

## Validación y estado local

- Targeted integration + legacy import + Employee Manifest: **47 PASS, 396 assertions**.
- Frontend `node --test tests/Frontend/employeeImport.test.js`: **4 PASS** (selección, confirmación, stale/paginación y estados terminales).
- Build `npm run build`: **PASS**. Advertencia no bloqueante de antigüedad de caniuse-lite; no se actualizaron dependencias ajenas.
- Pint scoped: **PASS, 33 archivos**; comprobado nuevamente con --test después del formateo.
- Laravel full: **443 PASS / 1 FAIL heredada**, 3488 assertions, 35.60 s. Falla: OnPremDiagnosticsCommandTest; sin nuevas regresiones observadas.
- Baseline aprobado ya documenta esa falla: `docs/architecture/pilot-validation.md` (420 PASS / 1 FAIL), y `baseline-audit.md` explica fixture sin clocks. No se modifican el test ni el controlador de heartbeat, fuera del alcance.
- Local: aplicadas exclusivamente migraciones 2026_09_05_000005 y 000006, con guardas local + DB vending_attendance_dev. Exit 0; empleados antes/después: **5 / 5**.
- Dataset manual sintético: **PASS en DB aislada de tests**, CSV y XLSX con confirmación/reimportación. **No se cargó un lote adicional en la base dev** mientras la suite global no está totalmente verde. Cero import runs en dev al cierre de la comprobación.
- Piloto: **cuatro cuentas provisionadas**, segunda ejecución idempotente, sin revelar ni rotar contraseñas. API real: no consultada. Producción: no modificada.
- Git diff --check: **PASS**. Estado final del legacy: **git limpio**, sin acciones de escritura.

No se certifica concurrencia multi-proceso sobre MySQL ni usabilidad visual con una sesión piloto real; las pruebas cubren locks/diff/reintentos e idempotencia a nivel servicio/HTTP con DB aislada y rollback inyectado.

## RISKS / OPEN DECISIONS

- HIGH: contrato y credenciales reales Fortia pendientes; no habilitar escritura ni interpretar mock como API. No se cargaron empleados reales.
- LOW: cuentas piloto ya disponibles; falta el retest visual/operativo del administrador.
- MEDIUM: única falla heredada de diagnóstico on-prem; resolver/aceptar en tarea separada antes de declarar suite completa PASS.
- MEDIUM: la compatibilidad legacy sólo debe activarse con autorización y revisión independiente. El flujo piloto es exclusivamente staging; no habilitar su flag para saltar confirmación.
- MEDIUM: confirmar scheduler/retención de metadata y probar concurrencia real/máximo de filas antes de ampliar volumen.
- MEDIUM: mapping aprobado para LEGACY→FORTIA; semántica de paginación/bajas/cursor API y zona horaria.
- LOW: validar navegador/escritorio/móvil con sesión piloto; adaptar tamaño de preview según operación.
- LOW: actualizar browserslist en mantenimiento separado, no como cambio de integración.

No afirmar READY_FOR_EMPLOYEE_PILOT completo: estado global PARTIAL / WAITING_FOR_FORTIA_API, además de la falla heredada y retest operativo indicados.


## FILES CREATED / nuevos en el worktree (28)

Los marcados PREEXISTENTE ya estaban sin seguimiento al iniciar; no se presentan como archivos creados por esta ejecución.

- `app/Console/Commands/PruneEmployeeImportStaging.php`
- `app/Contracts/FortiaEmployeeClient.php`
- `app/Enums/Employees/EmployeeImportRowClassification.php` — PREEXISTENTE; conservado/integrado.
- `app/Enums/Employees/EmployeeImportRunStatus.php` — PREEXISTENTE; conservado/integrado.
- `app/Enums/Employees/EmployeeSource.php` — PREEXISTENTE; conservado/integrado.
- `app/Http/Controllers/Employees/VendingEmployeeController.php`
- `app/Models/EmployeeImportRow.php` — PREEXISTENTE; conservado/integrado.
- `app/Models/EmployeeImportRun.php` — PREEXISTENTE; conservado/integrado.
- `app/Services/Employees/EmployeeIdentityNormalizer.php`
- `app/Services/Employees/EmployeeImportFileReader.php`
- `app/Services/Employees/EmployeeImportService.php`
- `app/Services/Employees/Fortia/DatabaseFortiaEmployeeClient.php`
- `app/Services/Employees/Fortia/FortiaEmployeeClientFactory.php`
- `app/Services/Employees/Fortia/FortiaEmployeeMapper.php`
- `app/Services/Employees/Fortia/HttpFortiaEmployeeClient.php`
- `app/Services/Employees/FortiaEmployeeSyncService.php`
- `config/employees.php` — PREEXISTENTE; conservado/integrado.
- `database/migrations/2026_09_05_000005_add_vending_identity_to_employees_table.php` — PREEXISTENTE; conservado/integrado.
- `database/migrations/2026_09_05_000006_create_employee_import_staging_tables.php` — PREEXISTENTE; conservado/integrado.
- `database/seeders/VendingPilotUsersSeeder.php`
- `docs/architecture/employee-integration-discovery.md`
- `resources/js/Pages/Employees/Partials/StagedEmployeeImport.vue`
- `resources/js/Pages/Employees/VendingCatalog.vue`
- `resources/js/Pages/Employees/importState.js`
- `tests/Feature/Vending/EmployeeImportTest.php`
- `tests/Feature/Vending/FortiaEmployeeIntegrationTest.php`
- `tests/Feature/Vending/VendingPilotUsersSeederTest.php`
- `tests/Frontend/employeeImport.test.js`

## FILES MODIFIED / cambios sobre baseline (17)

- `.env.example`
- `app/Console/Commands/FortiaSyncEmployees.php`
- `app/Http/Controllers/Api/EmployeeController.php`
- `app/Http/Resources/EmployeeCompactResource.php` — PREEXISTENTE; cambios preservados, sin nuevas ediciones aquí.
- `app/Models/Employee.php` — PREEXISTENTE; se corrigió además compatibilidad de binding.
- `app/Services/Employees/EmployeeCatalogQueryService.php` — PREEXISTENTE; cambios preservados, sin nuevas ediciones aquí.
- `app/Services/Employees/EmployeeExcelImportService.php`
- `app/Services/Fortia/FortiaEmployeeService.php`
- `app/Services/FortiaMock/FortiaMockSyncService.php`
- `app/Services/Vending/EmployeeManifestService.php` — PREEXISTENTE; cambios preservados, sin nuevas ediciones aquí.
- `app/Services/Vending/EmployeeManifestVersionService.php` — PREEXISTENTE; cambios preservados, sin nuevas ediciones aquí.
- `database/seeders/VendingDemoSeeder.php` — PREEXISTENTE; cambios preservados, sin nuevas ediciones aquí.
- `resources/js/Layouts/AuthenticatedLayout.vue`
- `routes/api.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/Admin/EmployeeExcelImportTest.php`

La lista excluye assets generados ignorados por git. El build escribe únicamente artefactos locales; no constituye un despliegue.


### Catálogo de permisos existente en la base local

- `asistencias`: `admin`, `edit`, `export`, `view`.
- `attendance`: `export`, `manage`, `sync`, `view`.
- `audit`: `manage`, `view`.
- `biometrics`: `face.manage`, `fingerprints.delete`, `fingerprints.read`, `templates.read`.
- `clocks`: `create`, `delete`, `disable`, `export`, `manage`, `sync`, `update`, `view`.
- `companies`: `create`, `disable`, `manage`, `update`, `view`.
- `dashboard`: `export`, `manage`, `view`.
- `employees`: `create`, `delete`, `disable`, `export`, `import`, `manage`, `sync`, `update`, `view`.
- `roles`: `create`, `delete`, `manage`, `update`, `view`.
- `settings`: `create`, `delete`, `manage`, `update`, `view`.
- `units`: `create`, `disable`, `manage`, `update`, `view`.
- `users`: `create`, `delete`, `disable`, `manage`, `update`, `view`.
- `vending_machines`: `assign`, `create`, `geofence`, `manage`, `update`, `view`.

No se asignaron estos módulos ajenos al piloto a las cuentas nuevas. La base tenía sólo Vending Demo Admin; tras provisionar tiene además los cuatro roles Vending Pilot de la matriz.
