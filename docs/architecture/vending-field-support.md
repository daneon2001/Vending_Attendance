# Fase 13.6A — actividades operativas de campo

Fecha: 2026-09-08. Discovery, identidad y autorización.
Rama observada: phase/14-biometric-engine-selection.
HEAD: 702b641ef803793d025903435b81a26fc1f48a0c.
No se cambia rama ni se implementa Fase 14.

**SUPPORT ACTIVITY != ATTENDANCE.**
**SUPPORT ACTIVITY != BIOMETRIC VERIFICATION.**

Estado: **PASS para el vínculo mínimo autorizado de13.6A**; no certifica ejecución en MySQL
ni habilita actividades en la aplicación real. El diseño posterior se implementa en13.6B,
sin aplicar migraciones reales; ver [dominio de actividades](vending-support-activity-domain.md).

## Continuación13.6B

Actualización 13.6C: la experiencia web está implementada y pendiente de revisión visual
externa. Ver [revisión web](../operations/vending-field-support-web-review.md).
El baseline vigente es 2507 empleados (2502 MANUAL corporativos + 5 DEMO) y SYBI 7
DRAFT, sin geofence/Device, con assignment 7 autorizado conservado. Las cifras anteriores
de este discovery son evidencia histórica, no instrucciones para restaurar datos.
No se aplicaron migraciones reales ni se relajó la identidad FORTIA exigida por 13.6A.

El nuevo adjunto autoriza el núcleo de dominio y API humana de actividades, preservando
íntegros los archivos de identidad13.6A. El documento enlazado registra autorización,
geocerca, workflow, pruebas, límites MySQL y los gates de datos. Las propuestas posteriores
de este discovery que no figuran allí como implementadas siguen diferidas.

La autorización directa posterior del usuario permitió modificar únicamente modelos,
migración, pruebas y esta documentación. Se implementaron las relaciones y un resolvedor
read-only en User; no un servicio de gestión, endpoint, formulario ni permisos nuevos.
El rechazo de herramientas del intento previo quedó resuelto por esa autorización.
No se aplicó la migración a datos reales. La comprobación de MySQL es DDL generado sin
conexión: MYSQL COMPATIBILITY PARTIAL hasta una ejecución en MySQL de testing autorizada.

## Recuperación y baseline

El último prompt sustituye expresamente el intento anterior. Se comenzó desde inspección,
no se continuó código truncado. git status --short sólo mostró seis documentos untracked:

- biometric-engine-selection.md
- biometric-engine-discovery-license-audit.md
- biometric-engine-spike-plan.md
- biometric-model-license-matrix.md
- biometric-reuse-license-audit.md
- biometric-dssia-vending-compatibility.md

Todos bajo docs/architecture, clasificados UNRELATED a13.6A, esperados y protegidos de14/14A/14B.
No hubo archivos ejecutables incompletos, ni cambios EXPECTED_PHASE_13_6 o UNCERTAIN.
PREVIOUS TRUNCATED RUN: NO_CHANGES ejecutables observados.
git diff --stat vacío; git diff --check PASS. Sin reset/stash/clean/checkout.

Baseline read-only:
vending_attendance_events19; attendance_logs0; attendance_dailies0; attendances_raw0.
No existen tablas literales attendance, attendances ni attendance_records en esta DB.
AttendanceRecord declara table=attendance_logs: su contador es0. No agregar esos
contadores como si fueran eventos Vending.
SYBI7 fuente única, proyección DRAFT, geofences0, devices0, assignments0: RESERVED.
ASISTENCIAS_FORTIA clean.

Preflight inicial FAIL: heartbeat2092s/<180s; última red ONLINE; configuración server2/applied2
y empleados server5/applied5 STALE; outbox0, HIGH1/MEDIUM1. No se generó actividad artificial.
Esta falta de frescura no se atribuye a cambios13.6A ni invalida evidencia física histórica.

## Fortia: pool corporativo, no filtro de asistencia

Referencia de código: C:/laragon/www/ASISTENCIAS_FORTIA, sólo lectura.
No se ejecutaron comandos de aplicación, sync, migraciones ni consultas a su DB.

| Componente | Comparación y evidencia real | Decisión para13.6A |
|---|---|---|
| app/Services/Fortia/FortiaEmployeeService.php | Fortia heredado hace incremental con EmployeeSyncState, payload laboral amplio, status history y scope sucursal. Vending usa facade hacia Employees/FortiaEmployeeSyncService, con ownership y gate de escritura | ALREADY_PRESENT; REJECT restauración literal |
| app/Services/FortiaMock/FortiaMockSyncService.php | Heredado copia datos laborales; Vending delega al facade seguro y exige driver mock | ALREADY_PRESENT; mock es DEMO, no fuente FORTIA real |
| Employees/FortiaEmployeeSyncService.php | Proyección mínima, dry-run, transacción, rechazo duplicados/conflictos/cursor antiguo, bajas explícitas y reactivación | ALREADY_PRESENT |
| Employees/Fortia/DatabaseFortiaEmployeeClient.php | Lee catálogo sin join de attendance/assignments; filtros opcionales de empresa/identificador; límite cerrado | ALREADY_PRESENT |
| Employees/Fortia/HttpFortiaEmployeeClient.php | Contrato explícitamente aprobado, HTTPS, read-only upstream, errores seguros, límite y rechazo de paginación incompleta | ALREADY_PRESENT; no contrato real nuevo aprobado aquí |
| Employees/Fortia/FortiaEmployeeMapper.php | Conserva número como string, source_external_id, nombre, status y cursor; descarta nómina/PII innecesaria | ALREADY_PRESENT |
| Fortia/CatalogAlignmentService.php | Archivo idéntico por SHA256 a Fortia; deriva empresas/unidades de empleados, no máquinas SYBI | ALREADY_PRESENT; no ejecutarlo ni usar ubicación laboral como permiso de máquina |
| Employee / employee_details | Modelo laboral y detail/details presentes; EmployeeDetail idéntico a Fortia | ALREADY_PRESENT; no poblar detalles sin necesidad demostrada |
| employee_sync_states | Modelo idéntico; usado por legacy, no cursor global del facade mínimo actual | ALREADY_PRESENT esquema; ADAPT sólo si se acuerda futuro incremental |
| employee_status_changes | Modelo idéntico; legacy registra cambios; proyección nueva audita eventos employee.status_changed | ALREADY_PRESENT; no afirmar igual historial/effective-date que Fortia |
| EmployeeImportMetadata | No existe clase Eloquent con ese nombre en ninguno de los dos. Tabla employee_import_metadata y migración idénticas; legacy accede por DB | ALREADY_PRESENT tabla; no crear modelo duplicado sin necesidad |
| Employees/EmployeeExcelImportService.php | Legacy casi idéntico, pero Vending añade flag legacy_enabled y protección de source; conserva detalle, metadata, bajas/reactivación | ALREADY_PRESENT protegido; REJECT reactivación indiscriminada |
| Employees/EmployeeImportService.php + EmployeeImportFileReader.php | CSV/XLSX mínimos, staging actor-bound, preview paginado, hash/confirmación, stale guard, ownership e idempotencia | ALREADY_PRESENT; no sustituir por import legacy |
| EmployeeExcelCatalogResolutionService.php | Idéntico a Fortia; catálogo laboral amplio | ALREADY_PRESENT; no necesario para personal de soporte |

FortiaClient no filtra por empleados que hayan checado, por attendance_allowed ni por existencia
de User. Por tanto técnicos, instaladores y zonales pueden pertenecer al pool sin generar
asistencia ni asignaciones. No hay motivo demostrado para copiar un segundo importador.

Alta: crea Employee de la fuente correspondiente. Actualización: conserva identidad/fuente.
Baja: status B explícito; ausencia en respuesta parcial NO da de baja.
Reactivación: status A de la misma fuente/identidad; no crea otra persona.
El import CSV/XLSX nuevo es MANUAL, no una forma de afirmar origen FORTIA ni de sobrescribirlo.
El sync mock produce DEMO. Ninguna de esas identidades debe convertirse automáticamente en
empleado corporativo Fortia al vincular una cuenta.

### Gaps reales, sin ampliar el alcance

- Contrato Fortia real/pool corporativo completo no certificado por una llamada upstream en
  esta fase. La documentación existente conserva WAITING_FOR_FORTIA_API.
- Proyección mínima no trae puesto, departamento, empresa/sucursal ni datos de nómina. Es
  minimización deliberada; soporte necesita identidad/estado, no todos los detalles laborales.
- Límite actual5000 registros y contrato no paginado: si el pool supera el límite, falla cerrado.
  Se necesita contrato paginado aprobado antes de ampliarlo, no truncar silenciosamente.
- Numeración/uniqueness actual de Vending es global; repetir número por empresa requiere
  resolver contrato corporativo antes de importar. No fusionar identidades por nombre/email.
- No existen roles Fortia que se traduzcan automáticamente a RBAC de User; no se deben inferir.
- No hay historial laboral efectivo completo en la proyección mínima; no presentarlo como
  motor de nómina, laboral o asistencia.

FORTIA IMPORT: ALREADY_PRESENT para la proyección mínima necesaria.
No restauración, nuevas columnas de importación ni cambio de contrato demostrado como necesario.

## Identidad: Employee != User

User es cuenta autenticable con RBAC propio; Employee es identidad laboral. Antes del cambio,
User.php no contenía employee_id/relación employee; Employee.php no contenía relación user.
Tampoco había vinculación persistida en las migraciones revisadas. No usar email_company,
nombre, device_serial ni credencial HMAC como sustituto de identidad laboral.

Vending no implementa el tenant SaaS del otro DSSIA: permisos users/employees son de la
instalación. No introducir company_id confiado al cliente ni fingir scope multiempresa
inexistente. Una futura ampliación multiempresa requerirá autorización por objeto real.

### Diseño de gestión propuesto en discovery (no autorizado en esta entrega)

- users.employee_id nullable, FK a employees.id, unique; permite muchos Employee sin User,
  y como máximo una cuenta vinculada por Employee. Restringir eliminación de empleado
  vinculado; no cascade que destruya historial.
- Relaciones User.employee belongsTo / Employee.user hasOne.
- No añadir employee_id a fillable ni a formularios genéricos/perfil/registro.
- Servicio OperationalEmployeeIdentityService propuesto: link(targetUser, employee) deriva
  actor de autenticación; current() resuelve sólo al usuario autenticado.
- Vinculación explícita con users.update Y employees.view existentes, sin atajo settings.manage.
  Releer cuenta/roles/status de DB: no aceptar privilegios cacheados enviados por cliente.
- TargetUser activo y Employee activo con source FORTIA e identidad externa válida.
  DEMO/MANUAL/LEGACY no se elevan a FORTIA; un futuro modo demo requiere diseño explícito.
- Transacción, locks y unique como segunda defensa; mismo vínculo idempotente.
  Reasignación/conflicto rechaza409. No relink silencioso ni auto-link por coincidencia de correo.
- Auditoría obligatoria con actor, target y anterior/nuevo employee_id, fecha y razón estable;
  un fallo de auditoría revierte el vínculo. El AuditLogger general omite fallos: no basar
  esa garantía en un logger best-effort sin verificación.
- Resolver el usuario actual desde DB con estado vigente; si empleado baja/cuenta se desactiva,
  no devolver identidad operativa utilizable. Conservar vínculo e historia.
- El vínculo NO asigna roles, permisos, máquinas, biometría ni capacidad de asistencia.
- No endpoint/UI ni vinculación de cuentas reales en13.6A. Primero servicio y tests aislados;
  futura exposición administrativa requerirá auth, permiso estricto y validación explícita.

Este diseño de servicio gestor NO se implementa bajo la autorización limitada posterior.
La entrega se limita a la columna, relaciones y resolución read-only descritas a continuación.

### Implementación autorizada y cardinalidad

Revisión anterior a elegir cardinalidad: cinco Users y cinco Employees, todos los empleados
DEMO; cero duplicados de employee_number; sin users.employee_id ni employees.user_id.
Schema existente: users.email UNIQUE; employees.employee_number UNIQUE y
(source, source_external_id) UNIQUE. Autenticación y acciones atribuyen una cuenta individual,
sin actor multipersona ni selector de identidades laborales. No hay pivot User/Employee
ni referencias que exijan varias cuentas por empleado.
Esto fundamenta adoptar un vínculo opcional1:1 en el modelo actual; NO prueba por sí solo
una correspondencia entre los cinco Users/Employees demo ni una regla universal de Fortia.
No se infirieron pares por nombre/email ni se asociaron registros existentes.

Migration creada: database/migrations/2026_09_08_140000_add_employee_link_to_users_table.php.
users.employee_id es unsigned bigint nullable, UNIQUE y FK a employees.id con ON DELETE RESTRICT.
Un User tiene0..1 Employee; un Employee tiene0..1 User. Varias cuentas sin vínculo son válidas.
La FK apunta desde users: eliminar/desactivar User no elimina ni cambia Employee.
Eliminar Employee vinculado falla, sin cascade a la cuenta. Employee no usa SoftDeletes y
no existe deleted_at; no se añadió un sistema de bajas lógicas paralelo. La baja laboral usa status.
down rechaza rollback si hay vínculos; sin vínculos, retirar/restaurar columna preserva filas.
Sólo se ejecutó up/down en SQLite :memory:. Nunca se migró la base real.

Relaciones implementadas: User.employee() belongsTo y Employee.user() hasOne.
User::authenticatedEmployee() toma exclusivamente auth()->user(), exige User persistido,
reconsulta cuenta activa en DB y luego Employee activo de source FORTIA con source_external_id
no vacío. Devuelve Employee o null, sin escrituras ni autorización de actividad.
Invitado, cuenta borrada/inactiva, vínculo ausente, empleado inactivo y fuente
DEMO/MANUAL/LEGACY o identidad externa incompleta devuelven null.
No se aceptan employee_id ni relación cacheada/mutada en memoria como autoridad.
Los scopes Eloquent del Employee se conservan; si algún día se añade SoftDeletes, será necesario
añadir su prueba de regresión explícita. No se afirma cobertura de un esquema inexistente.

employee_id no se añadió a fillable, requests, endpoints ni perfiles. employee_id y employee
se ocultan en la serialización automática de User para no ampliar props Inertia ni exponer PII.
Los fixtures usan employee()->associate() de forma explícita en DB aislada. Ese mecanismo
Eloquent es API interna de persistencia, NO un endpoint administrativo autorizado.
Una futura gestión de vínculos deberá implementar validación, RBAC, auditoría, conflictos
y revalidación transaccional; aquí no se habilita ninguna operación pública de asociación.
Resolver identidad tampoco otorga permiso support ni attendance; el consumidor deberá exigir
acción y scope por objeto, y revalidar estados al escribir la futura SupportActivity.

## Autorización y maintenance_allowed

RBAC existente: User::hasPermission(module, action), role_user y permission_role.
SupportAccess comprueba acción y objeto; SupportContext exige cuenta activa o device operativo.
Los permisos de soporte son view/view_all/report/comment/assign/resolve/verify/configure/manage.
No modificar roles piloto ni ejecutar seeders por esta auditoría.

EmployeeMachineAssignment tiene tres booleanos separados, vigencia, status y revoked_at.
MachineAuthorizationService expone canAttend / canEnroll / canMaintain como consultas separadas.
AttendanceAuthorizationEvaluationService comprueba attendance_allowed; mantenimiento no lo
sustituye. Para snapshot CURRENT, false produce DENIED/ATTENDANCE_NOT_ALLOWED.
Para historia no verificable puede devolver UNVERIFIABLE, nunca AUTHORIZED por mantenimiento.

IMPORTANTE: el protocolo de asistencia conserva recepción append-only de intentos con evaluación
DENIED; recibir un evento no significa autorizarlo. No cambiar ingestión para eliminar un
intento sólo por denegación. El gate es ausencia de permiso, no borrar evidencia de asistencia.

EmployeeManifestTest ya incluye attendance_allowed=false / maintenance_allowed=true.
UserEmployeeIdentityTest añade la prueba combinada tras vincular User y aserciones de cero
eventos por resolución, soporte e importación: PASS en SQLite en memoria.

maintenance_allowed conserva significado de mantenimiento. No implica INSTALLATION,
CONFIGURATION, DIAGNOSTIC, SOFTWARE_UPDATE ni permisos web/RBAC. assignment_type TECHNICIAN,
SUPERVISOR o ROUTE es clasificación, no concesión de acciones por sí misma.

## Zonal y Operational Scope

Zonal es Employee Fortia; User opcional. Revisar actividades no genera asistencia.
Puede tener assignments vigentes a máquinas con attendance/enrollment false, pero:
withRelevantPermission exige alguno de los tres flags para distribución por manifest.
No activar maintenance_allowed sólo para lograr visibilidad de un supervisor.

SupportAccess.scope actual ofrece own reports o view_all para usuarios; device se limita a
su máquina y tickets; integraciones tienen lista de máquinas. NO existe scope zonal humano
por conjunto de máquinas y operación independiente. view_all sería demasiado amplio para
un zonal limitado.

Conclusión: assignments aportan relación temporal Employee/máquina, pero no bastan como
autorización de todas las actividades ni revisión zonal. Se documenta Operational Scope
humano, no se implementa arquitectura compleja ni columnas por cada tipo de actividad.
Futura resolución: User autenticado → Employee vinculado → capability RBAC → máquinas
autorizadas vigentes → objeto concreto. Default deny, incluso por URL/UUID conocido.
No usar sucursal Fortia, nombre de puesto o navegación oculta como alcance.

Propuesta RBAC futura bajo el catálogo existente, sin seeder ejecutado:
módulo support_activities con view/create/assign/execute/review/cancel/manage, más scope.
Es preferible a conceder implícitamente ejecución de actividad mediante support.resolve
de tickets. Registrar esos permisos sólo cuando existan operaciones que los consuman.

## Diseño de VendingSupportActivity (NO implementado)

Flujo conceptual:

FORTIA → Employee ← vínculo opcional → User autenticado
→ autorización de acción + Operational Scope
→ VendingSupportActivity → VendingMachine
→ observación GPS/geofence → notas/evidencias → completion

Modelo propuesto siguiendo bigint interno + UUID público y enums string del proyecto:
tabla vending_support_activities, PK id, UUID unique, vending_machine_id obligatorio,
support_ticket_id nullable, assigned_employee_id, created_by_user_id,
performed_by_employee_id/performed_by_user_id determinados por servidor, tipo/estado,
assigned_at/started_at/completed_at/cancelled_at UTC, notas acotadas y razón de cancelación.
Distinguir responsable planificado de quien ejecuta; guardar ambas identidades de ejecución
para que cambiar posteriormente el vínculo User/Employee no reescriba autoría histórica.
No inventar un segundo directorio de técnicos ni usar Device como identidad del trabajador.

Tipos previstos:
INSTALLATION, CONFIGURATION, MAINTENANCE, DIAGNOSTIC, REPAIR,
COMPONENT_REPLACEMENT, CONNECTIVITY, SOFTWARE_UPDATE, OTHER.
Estados: ASSIGNED, IN_PROGRESS, COMPLETED, CANCELLED.
Transiciones propuestas ASSIGNED→IN_PROGRESS→COMPLETED y
ASSIGNED/IN_PROGRESS→CANCELLED; terminales inmutables salvo corrección aditiva autorizada.
Completion es finalización de trabajo técnico; nunca CHECK_IN/CHECK_OUT.
No usar SupportVerification (health-check de equipo) como comprobación biométrica del autor.

GPS/geofence es evidencia contextual, no regla laboral: capturado UTC, precisión y versión
de geofence junto a resultado/motivo disponible/no disponible. Reutilizar cálculo existente
sin modificarlo; no exigir INSIDE automáticamente para toda actividad.
Una política explícita posterior puede definir requisitos por actividad, sin convertirlos
en asistencia. Acceso a coordenadas/fotos sólo a actores autorizados y con retención definida.

Futuras notas/evidencias: sanitización, almacenamiento privado y autorización por objeto
de soporte existentes como patrones; validar pertenencia a actividad/máquina/ticket.
No ampliar ahora SupportEvidence con relaciones ficticias ni copiar fotos.
El diseño posterior requiere operaciones idempotentes y completion atómico, auditoría aditiva
y pruebas de reintentos; la cola móvil y su UI pertenecen13.6B o fases posteriores.

## Relación con tickets, sin duplicar Phase13

SupportTicket1 → N VendingSupportActivity; cada actividad tiene ticket opcional.
Actividad planificada sin ticket permitida por actor autorizado. Si hay ticket, su máquina
debe coincidir con la actividad y el actor debe tener acceso a ambos objetos.
Cerrar actividad no cierra automáticamente ticket; cerrar ticket no inventa ejecución.
Ticket mantiene folio, comentarios, asignación de responsable de incidente, SLA y eventos
ya existentes. Actividad representa trabajo efectuado/planificado, no otro ticket.
No automatización, SLA nuevo, timeline completo, push ni webhooks implementados.

## Mobile: conservar implementación Vending

Vending ya tiene LocationService.ts, support/SupportCaptureService.ts, PrivateEvidenceFiles.ts,
SqliteSupportStore.ts, SupportSyncService.ts, SupportApiClient.ts y UI/tipos de soporte.
Reutilizar posteriormente esos componentes bajo su contrato, sin duplicar outbox de asistencia
ni mezclar ACK/event UUID de ambos dominios. Device autenticado no demuestra quién es el empleado;
el futuro flujo de personal requiere autenticación/identidad vinculada.

No fue necesario inspeccionar de nuevo el shell biométrico DSSIA ni copiar código.
MOBILE DSSIA REUSE: REFERENCE_ONLY, sin incorporación. mob4b continúa fuera de selección.
Nada de YuNet/SFace/embeddings/liveness/templates/DataLake entra en13.6A.
Los seis documentos14/14A/14B permanecen protegidos; BiometricProvider intacto.

## Validación y cierre de implementación

Discovery previo:271 PASS/1886 aserciones. El preflight de aquel intento falló por
heartbeat2691s, manifests STALE, red última ONLINE, outbox0, HIGH1/MEDIUM1.
Es evidencia histórica, no una medición nueva. No se generó actividad artificial.

Test inicial rojo: User.employee() inexistente. Después de implementar:
UserEmployeeIdentityTest,17 PASS/147 aserciones, repetido después de fijar el nombre final
de migración. Se corrigió el fixture para comparar snapshots antes/después ambos leídos
de DB, sin relajar la igualdad de valores ni modificar lógica de autorización.

Regresión ejecutada:
php artisan test tests/Feature/Vending tests/Feature/Support
tests/Feature/Api/V1/VendingAttendanceAuthorizationTest.php tests/Feature/Auth
tests/Feature/Permissions tests/Feature/ProfileTest.php

Resultado:322 PASS/2205 aserciones,28.83s, exit0, incluidos los17 nuevos.
APP_ENV=testing, DB_CONNECTION=sqlite y DB_DATABASE=:memory: se fijaron sólo en el
proceso de pruebas; no se editó .env. Sin pruebas contra MySQL ni conexiones Fortia reales.
Pint scoped PASS: User.php, Employee.php, migration y test. Diff check incluye untracked.
Sin suite completa adicional ni build frontend: no hubo cambios frontend y no lo exige este gate.

| Gate | Prueba nueva |
|---|---|
| A Employee sin User | Empleado sin cuenta; múltiples cuentas con FK null |
| B Vínculo correcto | Relaciones inversas y resolución del usuario autenticado; no identidad cacheada |
| C No ambigüedad | UNIQUE rechaza segundo User; FK rechaza Employee inexistente |
| D attendance_allowed intacto | Snapshot completo del assignment y versión manifest idénticos |
| E Mantenimiento no es asistencia | canMaintain true, canAttend/canEnroll false, evaluación DENIED/ATTENDANCE_NOT_ALLOWED |
| F Resolver no crea eventos | Cero eventos Vending y tablas heredadas |
| G Import no crea cuentas | Alta/actualización/baja/reactivación Fortia sin Users ni assignments |
| H User no elimina Employee | Eliminación/desactivación de cuenta conserva empleado; sesión obsoleta no resuelve |
| Estados | Invitado, no vínculo, empleado inactivo, fuente incorrecta o incompleta devuelven null |
| Seguridad | No mass assignment ni exposición automática; vínculo no concede permisos y HTTP soporte rechaza |
| Soporte | Ticket/asignación/comentario/transición sintéticos sin asistencia |
| Migración | Round-trip SQLite preserva filas; rollback con vínculos falla; DDL MySQL compila sin PDO |

MySQL: unsigned bigint consistente con employees.id; UNIQUE nullable y FK RESTRICT
generados por Laravel MySQL grammar, sin SQL específico SQLite. MYSQL COMPATIBILITY PARTIAL
significa que no se ejecutó DDL en un MySQL aislado; no se encontró incompatibilidad.
No se certifica locking ni migración física MySQL mediante sólo una compilación.

Cierre real read-only:5 Users/5 Employees DEMO conservados; users.employee_id todavía NO existe
en DB real, confirmando que la migración no se aplicó. Eventos Vending19 antes/después;
attendance_logs, attendance_dailies y attendances_raw0 antes/después.
SYBI7 RESERVED/DRAFT,0 geofences/devices/assignments; ASISTENCIAS_FORTIA clean.
Hashes de los seis documentos14/14A/14B idénticos. Escaneo de patrones de secretos sin hallazgos.

Creado: migration y tests/Feature/Vending/UserEmployeeIdentityTest.php.
Modificado: User.php, Employee.php y este documento preexistente untracked.
Sin services, controladores, rutas, factories, seeders ni permisos nuevos.
Sin datos reales, empleados Fortia, .env, attendance, HMAC, manifests, geofences,
tickets existentes, mobile ni biometría modificados. Sin commit/tag/push/deploy.
La migración real y la gestión administrativa de vínculos siguen sin autorizar.
NEXT: READY_FOR_PHASE_13_6B bajo autorización posterior explícita; no activa el flujo real.
