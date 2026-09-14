# Resolución del baseline de empleados y SYBI7

Fecha:2026-09-08. Proyecto vending-attendance.
BASELINE INVESTIGATION: PASS.
SECURITY: PASS para el alcance investigado.
NEXT: READY_FOR_PHASE_13_6C, sin implementar la siguiente fase.

## Decisiones explícitas del operador

El operador confirmó separadamente:

1. El lote2 de importador de empleados.xlsx es el archivo corporativo autorizado.
2. La asignación7 fue una preparación manual autorizada de SYBI7 para prueba futura.
3. Autorizó actualizar únicamente el baseline documental de SYBI7.

No se dedujo una autorización de la otra. No rollback, borrado, revocación, restauración,
migración real, cambio de source ni modificación de capacidades.

Nuevo baseline:

- Employees:2507 =5 DEMO +2502 MANUAL importados.
- SYBI7:reservada para la prueba futura, DRAFT, sin geofence, sin Device,
  con assignment7 conservado como USER_AUTHORIZED_CHANGE.
- attendance_logs:0; vending_attendance_events:19.
- Usuarios:5. No cuentas masivas ni vínculos automáticos.

La reserva ya NO significa0 assignments: el cambio documental fue autorizado expresamente.
No se actualizan los comandos productivos demo-preflight ni demo-reset para simular este estado.

## Importación: evidencia independiente

EMPLOYEE 5 ->2507: AUTHORIZED_IMPORT.
EMPLOYEE NEW BASELINE:2507.

Lote2, UUID b0ca97e3-5173-4aac-b774-af0166d85fa3.
Archivo: importador de empleados.xlsx.
SHA256: f85ecdd810d96a5d81f0b90070aabf9697116cff2776ca1c5f2002c35106c609.
El lote1 PREVIEW del2026-09-07 00:37:41 UTC tiene exactamente el mismo nombre,
hash y2502 filas. No se abrió, copió ni volvió a importar el archivo real.

Actor: User2, Pilot Admin. Fuente técnica: importador web XLSX.
Columnas detectadas: CLA_TRAB→employee_number, NOMBRE→full_name,
ESTATUS_TRABAJADOR→status.
Inicio2026-09-08 19:52:42 UTC /13:52:42 CDMX.
Fin2026-09-08 19:53:31 UTC /13:53:31 CDMX.
Estado COMPLETED; failure_code null.

| Métrica | Resultado |
|---|---:|
| Filas |2502|
| Nuevos |2502|
| Actualizados |0|
| Sin cambio |0|
| Duplicados |0|
| Conflictos |0|
| Inválidos |0|
| Filas con errors no vacío |0|
| Filas enlazadas con Employee persistido |2502|
| Coincidencia número/nombre/status/source con staging |2502|

Audit2699: employee.import.completed, actor_user_id2.
request_id/correlation_id a25e7cc0-37d2-47a2-9346-ac82efade13f.
Metadata: run_uuid y contadores; no payload personal completo.
La misma petición contiene2502 employee.created y1 employee.import.completed.
No contiene assignment.created. User-agent registrado: navegador Chrome en Windows.

EmployeeImportService.apply sólo crea/actualiza Employee y staging/auditoría.
VendingEmployeeController exige confirmed y preview_hash y limita el lote al actor.
La ruta exige permiso employees.import. El actor tiene ese permiso; además el operador
confirmó que fue el archivo corporativo autorizado.

La prueba sintética de2502 genera CSV con nombres Sintético y sólo PREVIEW, nunca apply.
El lote real es XLSX COMPLETED, mismo hash que el preview previo, y0 filas coinciden con
los patrones sintéticos comprobados. La confirmación del operador completa la atribución;
no se infiere legitimidad sólo por un nombre de archivo.

### Origen corporativo vs source técnico

El operador confirmó origen corporativo del archivo; NO fue sincronización directa
del conector Fortia. El importador CSV/XLSX registra source=MANUAL por diseño.
No se cambió a FORTIA ni se inventó source_external_id.

El resolvedor13.6A sigue exigiendo Employee activo con source=FORTIA e identificador
externo. Conservar el pool corporativo no equivale a habilitar cuentas o ejecución.
Cualquier reconciliación posterior de identidad/origen necesita alcance explícito.
No se relaxa el resolvedor ni se generan Users automáticamente.

## SYBI7: evidencia independiente

SYBI7_ASSIGNMENT: USER_AUTHORIZED_CHANGE.
Assignment ID7; UUID ff334d71-b843-479d-b1ae-8f7928ca42bd.
Máquina operativa interna4, fuente SYBI identificador7.
Employee interno210; employee_number12015; el nombre se verificó y se informó al
operador, sin duplicarlo en este documento versionable.
Empleado pertenece al lote2; eso no implica que el importador creara su assignment.

| Campo | Valor persistido |
|---|---|
| assignment_type |PRIMARY|
| status |ACTIVE|
| source |MANUAL|
| attendance_allowed |true|
| enrollment_allowed |true|
| maintenance_allowed |true|
| valid_from |2026-09-08 19:54:00 UTC|
| valid_until (valid_to conceptual) |2026-09-09 13:54:00 UTC|
| created_at |2026-09-08 19:55:14 UTC|
| updated_at |2026-09-08 19:55:14 UTC|
| created_by / actor_user_id |2|

Audit2700: assignment.created.
request_id/correlation_id 2b50ac7f-4135-40bf-95a5-96513853f9db.
Metadata.after contiene exactamente la máquina, empleado, tipo, flags, vigencia y source
anteriores. User-agent: Chrome/Windows.
Esta petición sólo registra assignment.created y employee_manifest.version_changed.

Hay103 segundos entre import.completed y assignment.created y request_id distintos.
No corresponde a la petición de importación.

Mecanismo revisado:
VendingMachines/Show.vue envía el formulario independiente a
POST /vending-machines/{vendingMachine}/assignments.
EmployeeMachineAssignmentController.store llama MachineAssignmentService.create
y atribuye created_by al User autenticado. EmployeeMachineAssignmentObserver registra
assignment.created y actualiza la versión de manifest, sin generar asistencia.

La UI por defecto selecciona PRIMARY/MANUAL, pero enrollment y maintenance empiezan
en false; los tres flags true persistidos no son una consecuencia automática del importador.
El audit no guarda URL ni motivo libre: la ruta se identifica por código y la UI se
corrobora con navegador y confirmación explícita del operador, no con un access log.
No se encontraron esos request_id en storage/logs.

Por qué: el operador confirmó que fue una preparación manual autorizada para una
prueba futura de SYBI7. No fue fixture, migration, seeder, demo-reset ni efecto de importación.
No se dedujo la intención sólo de actor_user_id2.

ROLLBACK REQUIRED: NO.
ROLLBACK PROPOSAL: ninguno; conservar empleados y assignment7.
El baseline documental con1 assignment fue autorizado por separado.

## Aislamiento de pruebas y de importación

Los cuatro esquemas MySQL desechables de13.6B.1 fueron creados con prefijo exclusivo,
todas las conexiones redirigidas y comprobadas, y eliminados por el harness.
information_schema confirma0 esquemas field_support_136b1_test_* restantes.
Los fixtures MySQL usaron Employee FORTIA sintético, no el XLSX corporativo.

La DB real sigue sin User.employee_id y sin vending_support_activities.
Las migraciones de identidad/actividad sólo existieron en las bases de testing.
No se escribieron datos reales mediante el harness; las dos operaciones reales fueron
confirmadas por el operador y atribuidas a peticiones independientes.

Prueba agregada a EmployeeImportTest:
test_file_import_creates_only_employees_without_accounts_assignments_attendance_or_activities.

Ejecuta XLSX sintético en SQLite :memory:, crea y actualiza Employee,
comprueba preview/confirmación/replay, y compara snapshots de:
users, employee_machine_assignments, attendance_logs, vending_attendance_events,
support_tickets, vending_support_activities, vending_support_activity_events.
Conserva assignment existente con attendance=false/maintenance=true.
Employee nuevo no tiene User, assignment ni source_external_id fabricado.

IMPORT CREATED USERS:NO.
IMPORT CREATED ASSIGNMENTS:NO.
IMPORT CREATED ATTENDANCE:NO.
IMPORT CREATED SUPPORT ACTIVITIES:NO.

Tests dirigidos EmployeeImportTest + UserEmployeeIdentityTest:
37 PASS /318 assertions,6.28s.
Pint scoped y diff check:PASS.
No se repitieron MySQL, escala, concurrencia ni suite completa costosa.
Los gates técnicos13.6B.1 se conservan:
migraciones/rollback/constraints/concurrencia/CREATE/START/COMPLETE/CANCEL/historial/
ticket/geofence/API/indexes PASS; FIELD_PHYSICAL_V1 intacta;
escala1000 máquinas/10007 actividades.
Suite completa previa:692 PASS /1 fallo heredado OnPremDiagnosticsCommandTest.

## Protección final y archivos

Contadores reales antes/después de esta investigación:
attendance_logs0/0; vending_attendance_events19/19; employees2507/2507; users5/5.
SYBI7:baseline actualizado sólo documentalmente, sin mutación.
ASISTENCIAS_FORTIA clean.
Phase14/14A/14B y archivos protegidos13.6A:hashes preservados.

Sólo se añadieron la prueba anterior y este documento, y se actualizó el estado
de cierre en vending-support-activity-mysql-validation.md.
Ningún cambio de código productivo, permiso, configuración ni dato real.
No commit/tag/push/deploy ni implementación13.6C.
