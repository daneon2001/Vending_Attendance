# Fase 13.6B — dominio de actividades operativas de campo

Fecha: 2026-09-08. Rama: phase/14-biometric-engine-selection.
Baseline observado: 702b641ef803793d025903435b81a26fc1f48a0c.
Implementación independiente; no continúa Fase 14.

SUPPORT ACTIVITY != ATTENDANCE != TICKET != BIOMETRIC VERIFICATION.

## Estado y límites de evidencia

Actualización 13.6C: listado, filtros, creación/asignación, detalle, snapshot histórico,
timeline y cancelación web implementados; resúmenes acotados en ticket y máquina.
La API JSON anterior se conserva por negociación de contenido; las rutas humanas
de inicio/completado y el servicio de ejecución no cambian. La UI no ofrece esas acciones.
Ver [gate web y limitaciones](../operations/vending-field-support-web-review.md).
El folio visible ACT-{id con mínimo seis dígitos} es sólo presentación del ID existente.
El nuevo baseline autorizado se documenta en [resolución](vending-baseline-resolution.md).
Los estados DEFERRED de UI/folio y los contadores siguientes corresponden al reporte
histórico 13.6B, no al cierre 13.6C. No se han aplicado migraciones a la DB real.

Actualización13.6B.1: migraciones, rollback y concurrencia MySQL aislada ya validados.
Ver [evidencia y endurecimiento MySQL](vending-support-activity-mysql-validation.md).
Las menciones PARTIAL/PENDING siguientes conservan el resultado histórico inicial13.6B.

Dominio, API JSON de sesión humana y pruebas aisladas implementados.
Fase PARTIAL hasta validar migraciones y concurrencia MySQL aisladas; no bloquea
la entrega del núcleo probado en SQLite. No se certifica operación física ni producción.
No se aplicaron migraciones a la base real, no se crearon actividades reales.

Preflight inicial de sólo lectura: FAIL. Heartbeat 9662 s (umbral <180 s);
configuration server 3/applied 2 STALE; employee server 5/applied 5 STALE;
HIGH alerts 1. No se alteró Android ni se intentó subsanar mediante datos ficticios.
Esto no invalida la evidencia física histórica y no impide pruebas aisladas.

## Discovery y reutilización

No existía agregado SupportActivity/WorkOrder/MaintenanceActivity/FieldActivity/Intervention
en app, database o routes. CREATE del agregado VendingSupportActivity.
SupportTicket ya tiene asignado en assignee_id (no modelo SupportTicketAssignment);
SupportEvidence y SupportTicketEvent son las implementaciones reales de evidencia e historial.
No se duplicaron ni se modificaron sus workflows. Relación nueva SupportTicket.activities().

Se reutilizan User.authenticatedEmployee() de13.6A, EmployeeMachineAssignment,
MachineAuthorizationService.canMaintain(), GeofenceValidationService,
MachineGeofence.effectiveAt(), SupportAccess para el ticket opcional y reserva SYBI,
AuditLogger, sesión web, CSRF y throttle:support-write.

NotificationService consume un cursor de SupportTicketEvent y una lista de eventos de tickets.
Integrar actividades allí requeriría ampliar ese contrato: notificaciones de actividades DEFERRED.
No push, SLA, fotos, UI, filesystem, offline ni automatización nueva.

## Esquema

Migración 2026_09_08_150000_create_vending_support_activities.php:

- vending_support_activities: PK bigint + UUID unique; machine, employee, ticket nullable;
  creator/assigner y actores de inicio/finalización/cancelación; tipo/estado;
  título160, descripción4000 validada; planificación y tiempos;
  política de presencia versionada; snapshot de ubicación al iniciar;
  razón de cancelación validada1000.
- vending_support_activity_events: historial transaccional compacto, actor User + Employee,
  fecha UTC y kind. UNIQUE(activity_id, kind): creado, asignado e hitos no se duplican.
  No metadata libre, texto libre ni coordenadas en eventos.

FK restrictOnDelete en referencias históricas, incluidos usuarios. Una cuenta que ya actuó
debe desactivarse: no puede eliminarse borrando la trazabilidad. No borra su Employee.
No cascade-delete. Model protegido contra actualización/borrado accidental; sólo el servicio
ejecuta cambios condicionales. No se ofrece DELETE ni reasignación en esta fase.
down() rechaza eliminar historia no vacía. No se afirma inmutabilidad frente a un DBA.

Índices employee/status/id y machine/status/id; ticket y demás FK indexadas por el motor.
Lectura paginada fija de25 sin relaciones cargadas por fila; sin N+1 ni colecciones completas.
Folio legible: DEFERRED. No MAX(id)+1.

## Identidad, RBAC y ámbito

User activo → vínculo persistido explícito → Employee FORTIA activo con identificador
externo no vacío. Se conserva íntegro el resolvedor13.6A y la cardinalidad opcional1:1.
No vinculación por email, cuentas masivas, tokens nuevos ni selección de actor por cliente.

Todas las operaciones exigen identidad válida. Se relee User para RBAC, sin confiar en
roles ya cargados en la sesión. Machine debe ser ACTIVE para ejecutar o planificar,
no reservada, con EmployeeMachineAssignment ACTIVE, no revocada y vigente.
No se introduce Branch/Location scope ni support_allowed.

Política de capacidades por tipo, explícita para13.6B:

| Tipo | Permiso support | maintenance_allowed |
|---|---|---|
| MAINTENANCE, REPAIR, COMPONENT_REPLACEMENT | resolve | requerido |
| DIAGNOSTIC | verify | no inferido; assignment vigente obligatorio |
| INSTALLATION, CONFIGURATION, CONNECTIVITY, SOFTWARE_UPDATE | configure | no inferido; assignment vigente obligatorio |
| OTHER | manage | no habilitación implícita |

COMPONENT_REPLACEMENT se restringe como reparación. OTHER requiere manage por
falta de una capacidad específica aprobada. Los flags attendance/enrollment no son
autoridad para actividades; maintenance no concede los permisos configure/verify/manage.
Los permisos existentes no se siembran ni modifican ni se asignan a usuarios reales.

Crear/asignar exige support.assign y ámbito de máquina del creador; target Employee
debe cumplir su asignación/capacidad. Puede planificarse para Employee sin User;
ejecutar exige su cuenta vinculada real. No hay asignación automática de máquinas.

Inicio/finalización exigen ser el Employee asignado y tener permiso por tipo.
Finalización exige además la cuenta que inició. Cancelación: ejecutor autorizado o
planificador con support.assign y asignación vigente a esa máquina. Siempre con motivo.
Lectura exige support.view y assignment propio vigente; sin support.assign sólo actividades
del propio Employee. support.view_all no elimina ese filtro. Un planificador con assign ve
actividades en sus máquinas, no en todo el catálogo. Se permite leer historia de máquina
inactiva si conserva ámbito; eso no autoriza iniciar/completar/cancelar.

ZONAL_SCOPE = DEFERRED_TO_OPERATIONAL_SCOPE.
El futuro ámbito zonal podrá limitar machine_id; no cambia la separación laboral.

## Geocerca y presencia

FIELD_PHYSICAL_V1: los nueve tipos requieren presencia física. Desde13.6B.1 la decisión
vive en SupportActivityPresencePolicy y no es una propiedad eterna del enum.
CONFIGURATION y SOFTWARE_UPDATE permanecen físicos porque todavía no existe una
política remota aprobada. OTHER tampoco permite un bypass. Futuro modo remoto requiere
una política versionada del servidor, nunca un booleano arbitrario del cliente.

GeofenceValidationResult existente (INSIDE/OUTSIDE/UNCERTAIN) reutilizado sin modificarlo.
NOT_EVALUATED es un resultado de evaluación no disponible en la respuesta, no un nuevo
resultado geométrico persistido ni enum de asistencia. Snapshot es null antes del inicio.

START toma la geocerca ACTIVE vigente en tiempo del servidor, no la existente al planificar
ni la versión sugerida por cliente. Bloquea la máquina con el mismo mutex de fila que usa
MachineGeofenceService para activar/reemplazar/desactivar. No copia Haversine.

GPS requerido: latitud/longitud válidas y finitas, no0,0; accuracy0..100000;
captured_at requerido. Política técnica online v1: antigüedad máxima5min y futuro máximo30s.
No es regla laboral ni altera ventanas de attendance. GPS es evidencia declarada por cliente,
no prueba criptográfica ni protección contra falsificación de ubicación.

- INSIDE: permite y persiste coordenadas, accuracy, captura, geofence ID/version,
  distancia/effective_distance, resultado y evaluación UTC.
- OUTSIDE:422 específico y mensaje amigable; no inicia.
- UNCERTAIN:422 específico y mensaje amigable; no inicia.
- Sin geocerca activa/vigente:422 NOT_EVALUATED; no inicia.
- GPS inválido:422 de validación.

Denegaciones dejan ASSIGNED sin snapshot parcial; la respuesta permite reintentar con nueva
captura. No crean denegaciones/eventos de Attendance. Complete no requiere GPS final.
Las coordenadas no se exponen en JSON normal ni en AuditLogger. Permanecen en el agregado.
Un cambio posterior de geocerca no recalcula ni sobrescribe el snapshot del inicio.

## Workflow, transacciones e idempotencia

ASSIGNED → IN_PROGRESS → COMPLETED.
ASSIGNED o IN_PROGRESS → CANCELLED.
COMPLETED y CANCELLED terminales.

Cada mutación usa DB::transaction con reintentos de deadlock, bloqueo de actividad/máquina
y actualización WHERE id AND status anterior. Sólo un ganador genera el evento.
Repetir START o COMPLETE devuelve DOMAIN_CONFLICT409 sin segundo timestamp/evento/auditoría.
Autorización se verifica antes de revelar conflicto. Cancelación no elimina el registro.
Inicialmente crear tenía sólo UUID generado por servidor. Desde13.6B.1 exige además
client_operation_uuid y reutiliza SupportOperations para replay persistente por User,
con hash canónico e intención propia. No implementa una cola offline ni UI.

Eventos mínimos en AuditLogger: support_activity.created, assigned, started, completed, cancelled.
AuditLogger existente captura fallos y no garantiza atomicidad por sí solo; por ello el nuevo
registro compacto de eventos se inserta en la misma transacción y su fallo revierte el cambio.
No se agrega un timeline de tickets ni UI de timeline. La auditoría general sólo lleva IDs/estado.

Prueba de CAS obsoleto y reintentos: PASS en SQLite.
MYSQL_CONCURRENCY = PENDING: no se ejecutó una carrera real de dos procesos MySQL.
La prueba SQLite NO se presenta como evidencia de bloqueo MySQL.

## Ticket y contrato humano

Ticket nullable permite planificación sin incidente. Ticket1:N activities.
Al vincular, SupportAccess comprueba lectura del ticket y coincidencia de máquina.
Completar/cancelar actividad no altera ticket, SLA, assignment de ticket ni sus eventos.
No incorpora automáticamente evidencias del ticket a respuestas de actividad.

Endpoints JSON bajo sesión web autenticada/verificada:

| Método | Ruta | Operación |
|---|---|---|
| GET | /support/activities | lista paginada y acotada |
| POST | /support/activities | crear/asignar validando target y máquina |
| GET | /support/activities/{uuid} | detalle autorizado |
| POST | /support/activities/{uuid}/start | GPS; actor/máquina derivados del recurso |
| POST | /support/activities/{uuid}/complete | finalizar |
| POST | /support/activities/{uuid}/cancel | motivo obligatorio |

CSRF y límite de escritura existentes. Se rechazan overrides de identidad, estado y política
en ejecución. La selección employee_id/machine al crear es una entrada de planificación
validada en servidor, no una credencial. Sin endpoints masivos ni API administrativa extensa.

WEB ADMIN: núcleo JSON de listado/creación/asignación/detalle; UI visual DEFERRED.
No se añade navegación ni se afirma revisión visual.

Device HMAC identifica un terminal, no al técnico. Estas rutas no aceptan esa firma como
identidad humana. Mobile seguirá su contrato actual; futura integración requiere login humano
aprobado y, si se exige contexto Device adicional, comprobar ambos y su máquina sin inferir
que poseer la credencial Device identifica Employee. Contrato mobile/login DEFERRED.

## Migraciones y seguridad de datos

13.6A y13.6B se ejecutaron sólo mediante tests con SQLite :memory:.
PHPUnit declara SQLite en memoria; no se identificó una base MySQL independiente autorizada
para este dominio. El harness MySQL anterior crea una base nueva: no se ejecutó ni se adaptó
automáticamente. Nunca usar vending_attendance_dev para pruebas de migración.

MYSQL COMPATIBILITY = PARTIAL.
DDL de MySQL compilado sin PDO: unsigned FK, nullable, unique, restrict y índices verificados.
MYSQL MIGRATION EXECUTION = PENDING. No certificar equivalencia sólo por compilar.
REAL DB MIGRATED = NO.

Datos reales antes/después: attendance_logs0; vending_attendance_events19.
SYBI7 permanece DRAFT, sin geofence, Device ni assignments: RESERVED.
ASISTENCIAS_FORTIA clean; archivos13.6A y documentos biométricos protegidos por hash.
No .env, credenciales, APK, archivos binarios, datos reales, mobile ni contratos modificados.

## Validación

27 pruebas nuevas de dominio/API: PASS (352 assertions).
17 pruebas13.6A: PASS (147 assertions).
Regresión final Vending + Support + attendance authorization + Auth + Permissions + Profile:
349 PASS, 2557 assertions, 33.90s, incluidas las dos pruebas de ámbito/CSRF.
Pint scoped y diff check: PASS.
Revisión estática de13 archivos: sin patrones de secretos encontrados.
Hashes finales de identidad13.6A y todos los documentos biometric-*: idénticos al inicio.
Contadores finales reales:0/19; User.employee_id sigue ausente en la base real.
Sin frontend/mobile modificado: build y tests frontend/mobile no aplican.
No se repite suite completa ni se afirma PASS sobre tests no ejecutados.

## Archivos de13.6B

Creados:

- app/Enums/Support/SupportActivityType.php
- app/Enums/Support/SupportActivityStatus.php
- app/Models/VendingSupportActivity.php
- app/Services/Support/SupportActivityAccess.php
- app/Services/Support/SupportActivityService.php
- app/Http/Controllers/Support/SupportActivityController.php
- app/Http/Requests/Support/SupportActivityRequest.php
- database/migrations/2026_09_08_150000_create_vending_support_activities.php
- tests/Feature/Support/SupportActivityTest.php
- docs/architecture/vending-support-activity-domain.md

Modificados:

- app/Models/SupportTicket.php: sólo relación activities.
- routes/support-web.php: seis rutas adicionales; contratos de tickets intactos.
- docs/architecture/vending-field-support.md: enlace y estado de continuación.

No se agregaron archivos al staging. Los cambios previos de13.6A/14 permanecen separados.

Sin commit, tag, push, deploy ni migración real.
