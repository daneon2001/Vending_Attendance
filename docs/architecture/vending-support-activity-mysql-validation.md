# Fase13.6B.1 — validación MySQL y endurecimiento del dominio

Fecha: 2026-09-08. Proyecto exclusivo vending-attendance.
Baseline Git: 702b641ef803793d025903435b81a26fc1f48a0c.
Rama conservada: phase/14-biometric-engine-selection.
Se conservan los cambios previos de13.6A/13.6B y los documentos14/14A/14B.

## Resultado y alcance

CHECKPOINT TÉCNICO: PASS. BASELINE RESOLUTION: PASS.
El operador confirmó independientemente el archivo corporativo y la asignación manual,
y autorizó el nuevo baseline documental:2507 empleados; SYBI7 DRAFT, sin geofence/Device,
con assignment7 conservado. Ver [resolución del baseline](vending-baseline-resolution.md).
Los hallazgos de cierre originales siguientes se conservan como evidencia histórica.
NEXT: READY_FOR_PHASE_13_6C, sin implementar esa fase ni modificar datos reales.

MySQL real aislado: PASS para migraciones, rollback vacío/re-run, constraints,
concurrencia, historial y consultas documentadas. No es un benchmark/SLO productivo.
No migración real ni UI ni mobile ni biometría. No nuevos datos operativos reales.

SUPPORT ACTIVITY != ATTENDANCE != TICKET != BIOMETRIC VERIFICATION.

## Infraestructura de aislamiento

Harness: tests/Support/field_support_mysql.php.
Comando explícito: php tests/Support/field_support_mysql.php.

Adaptación del patrón existente tests/Support/support_mysql_concurrency.php:
sólo APP_ENV local y conexión MySQL; nombre aleatorio con prefijo reservado;
CREATE DATABASE sin IF NOT EXISTS garantiza que el esquema es nuevo y propio.
Prohibido seleccionar vending_attendance_dev u otra base persistente.

Todas las conexiones Laravel, incluyendo aliases Fortia, se redirigen al esquema
nuevo; se eliminan URL/read/write alternativos. Cada conexión usada verifica SELECT DATABASE().
Workers usan el mismo esquema y un marcador de pertenencia generado por el harness.
No se pasa ninguna credencial por CLI ni se imprime configuración/SQL/bindings.
HTTP saliente bloqueado; cache/sesión array, queue sync y logger null en ese proceso.
No cambia .env. No se crean archivos binarios ni evidencia física.

El padre sincroniza procesos independientes con una barrera persistida en el esquema
aislado; no son llamadas secuenciales simuladas. Timeout acotado y cierre de procesos/pipes.
Finalmente DROP DATABASE sólo para el nombre validado cuya creación obtuvo éxito.
Los fixtures son sintéticos, regenerables; la eliminación no afecta otra base.

Bases utilizadas, todas eliminadas:

| Base exacta | Propósito / resultado |
|---|---|
| field_support_136b1_test_5a70372e82852af3 | baseline: CREATE duplicado y EXPLAIN previo |
| field_support_136b1_test_a99854490d553f08 | primera corrección: worker inesperado, diagnóstico ampliado |
| field_support_136b1_test_f32c38eb79e0c1c5 | reproducción: ModelNotFoundException concurrente |
| field_support_136b1_test_fb8a8d05a8f2b6a3 | validación final: todos los gates MySQL PASS |

## Hallazgos demostrados y cambios mínimos

### CREATE no tenía idempotencia

13.6B generaba UUID de actividad por servidor pero no aceptaba client_operation_uuid.
La prueba HTTP nueva devolvió422 porque el campo estaba prohibido.
La carrera inicial de dos servicios/procesos creó dos actividades.

Se exige client_operation_uuid válido sólo en creación y se reutiliza SupportOperations
sin modificarlo. La autoridad persistente es support_operations:
UNIQUE(principal_key, operation_uuid), upsert que obtiene bloqueo exclusivo,
hash canónico e intención support_activity.create.
El recibo sólo contiene activity_uuid, no GPS ni payload completo.

La identidad, permiso assign, ámbito de máquina y target se revalidan antes de devolver
un recibo. El hash incluye actor Employee y atributos normalizados:
IDs obtenidos de DB, programación normalizada UTC y valores opcionales normalizados.
Orden JSON, UUID en mayúsculas/minúsculas y fechas equivalentes no duplican actividad.
Misma operación con contenido distinto o reutilización con otra intención:409.
La clave está acotada al User; no se usa como credencial ni prueba de propiedad.

Este endurecimiento cambia el contrato JSON de creación antes de tener consumidores
web/mobile de actividades: futuras solicitudes deben enviar la clave estable.
Repetir CREATE devuelve el mismo UUID y no duplica eventos/audit.
START/COMPLETE conservan DOMAIN_CONFLICT409 para repetición; no se cambia su contrato.

### Lectura MVCC al recuperar un recibo concurrente

La corrida corregida inicialmente produjo ModelNotFoundException. El segundo proceso
ya tenía una vista REPEATABLE-READ iniciada al resolver identidad. Podía obtener el
recibo con lectura bloqueante después del commit ganador, pero la lectura normal de la
actividad seguía usando la vista antigua y no la encontraba.

El harness demuestra con dos conexiones al mismo esquema:
lectura normal = ausencia; SELECT FOR UPDATE = presencia del mismo registro nuevo.
Servidor observado: REPEATABLE-READ, sin alteración de aislamiento.

Cambio mínimo: recuperar la actividad del recibo mediante lockForUpdate dentro de
la transacción. No se cambian SupportOperations ni las semánticas de tickets.

### Índices con evidencia

La primera carga sintética de1000 máquinas /10007 actividades mostró:
estado, abiertas y fecha: type ALL, key null, estimación9991 filas, filesort.
Máquina, Employee y ticket ya tenían índices útiles: no se duplicaron.

Migración nueva 2026_09_08_160000_index_support_activity_queries.php:

- support_activity_status_date (status, created_at, id).
- support_activity_date (created_at, id).

EXPLAIN final, sin FORCE INDEX:

| Consulta | Tipo | Índice elegido | Filas estimadas |
|---|---|---|---:|
| machine + status | ref | support_activity_machine_status | 3 |
| employee + status | ref | support_activity_employee_status | 502 |
| status + fecha descendente | ref | support_activity_status_date | 502 |
| abiertas ASSIGNED/IN_PROGRESS | range | support_activity_status_date | 1002 |
| ticket | ref | FK support_ticket_id | 202 |
| fecha reciente | range | support_activity_date | 65 |

Abiertas conserva filesort sobre candidatos del índice por orden mixto; no se afirma
eliminar toda ordenación. Las seis consultas retornan como máximo25 filas.
El dataset es sintético; no representa cardinalidades ni latencias productivas.

## Migraciones y constraints MySQL

Ejecución real de todas las migraciones en el esquema aislado, incluyendo:

- 2026_09_08_140000_add_employee_link_to_users_table.php.
- 2026_09_08_150000_create_vending_support_activities.php.
- 2026_09_08_160000_index_support_activity_queries.php.

Rollback vacío en orden inverso, desaparición del vínculo/tablas y re-run: PASS.
Rollback con vínculo User-Employee existente: rechazado sin pérdida.
Rollback con historial de actividad: rechazado sin pérdida.

Información de columnas/índices en MySQL valida:

- FK unsigned compatibles; User.employee_id nullable; múltiples cuentas sin vínculo.
- Employee sin User; vínculo válido; duplicado e ID inexistente rechazados por DB.
- UUID único de actividad; clave única persistente de recibo.
- Strings para tipo/estado; datetime para hitos.
- GPS latitud escala7, accuracy escala3, distancias escala2.
- Snapshot conserva ID/version geofence y valores después de reemplazarla.
- Todas las FK históricas de las dos tablas de actividades usan RESTRICT.

Intentos DELETE de User, Employee, VendingMachine, SupportTicket, MachineGeofence y
actividad con eventos: rechazados por constraints. No cascadas históricas.
Cierre real del ticket vía SupportTicketService conserva sus actividades.
Completar actividad no altera ni cierra ticket. Ticket1:N y nullable comprobados.

## Concurrencia real

| Escenario | Evidencia final |
|---|---|
| CREATE misma client_operation_uuid | 2 procesos liberados por barrera;1 actividad lógica |
| Replay CREATE | mismo ID; sin nuevos eventos/audit |
| CREATE distinto contenido misma clave |409 |
| START simultáneo |1 éxito +1 conflicto;1 evento started |
| COMPLETE simultáneo |1 éxito +1 conflicto;1 evento completed |
| COMPLETE vs CANCEL |3 carreras;1 ganador +1 conflicto cada una |

El estado terminal coincide con el ganador; no se pretende que el planificador del SO
escoja siempre la misma solicitud. Consistencia determinista del dominio:
un único terminal y exclusión completed_at XOR cancelled_at, un único evento terminal.
Se mantienen transacción, bloqueo y CAS de13.6B; no se añadió un segundo workflow.

## Política inicial y autorización

FIELD_PHYSICAL_V1 permanece íntegra. La política ahora reside en
SupportActivityPresencePolicy, no como propiedad eterna del enum.
Los registros conservan la versión y el flag derivado del servidor.
Versiones desconocidas o snapshot false no permiten inicio.
REMOTE no implementado: requerirá versión y ruta de evaluación aprobadas.

| Tipo | RBAC support | Machine scope | Capability de assignment | Geofence |
|---|---|---|---|---|
| MAINTENANCE, REPAIR, COMPONENT_REPLACEMENT | resolve | máquina ACTIVE + assignment vigente | maintenance_allowed | INSIDE |
| DIAGNOSTIC | verify | máquina ACTIVE + assignment vigente | no se infiere de maintenance | INSIDE |
| INSTALLATION, CONFIGURATION, CONNECTIVITY, SOFTWARE_UPDATE | configure | máquina ACTIVE + assignment vigente | no se infiere de maintenance | INSIDE |
| OTHER | manage | máquina ACTIVE + assignment vigente | sin bypass implícito | INSIDE |

Crear/asignar exige assign y ámbito del creador; ejecutar exige Employee asignado.
Lectura exige view y ámbito; view_all no amplía automáticamente el alcance.
maintenance_allowed no concede attendance, enrollment ni configure/verify.
No permisos sembrados ni roles modificados.

INSIDE permite; OUTSIDE, UNCERTAIN y NOT_EVALUATED deniegan bajo esta política.
Sin supervisor bypass. GeofenceValidationService y resolvedor13.6A intactos.
Actor = User autenticado → Employee activo Fortia; nunca Device HMAC ni ID de payload.

## API y pruebas

Se conservan seis rutas JSON bajo sesión humana:
list/create/show/start/complete/cancel.
Autenticación, CSRF fuera del bypass de testing, throttle real, IDOR, scope de lectura,
mass assignment, identidad/máquina falsas, transición inválida y claves de operación
se verifican en pruebas. No token nuevo, login mobile ni UI.

Tests de actividad actualizados:32 PASS,403 assertions.
Regresión dirigida antes de la última lectura bloqueante:354 PASS,2608 assertions.
Suite completa posterior a la última corrección:692 PASS /1 FAIL,5426 assertions,165.07s.
Única falla: OnPremDiagnosticsCommandTest, assertExitCode(0) recibe1 en línea54;
es la falla ya documentada en el baseline. No se modificó ese test/comando.
No surgieron otras fallas en la suite.
Pint scoped final y git diff --check: PASS.

## Protección y cierre

Attendance real antes: attendance_logs0, vending_attendance_events19.
Después: attendance_logs0, vending_attendance_events19; aislamiento de asistencia PASS.
En el esquema MySQL aislado las dos tablas permanecieron en0 después de todas las pruebas.
SYBI7 y ASISTENCIAS_FORTIA no se usan como fixtures.
Documentos14/14A/14B y archivos protegidos13.6A: hashes idénticos al inicio.
ASISTENCIAS_FORTIA: clean. User.employee_id continúa ausente en la DB real.

### Cambio concurrente de baseline detectado

Antes:5 empleados DEMO; SYBI7 DRAFT con0 geofences,0 Devices y0 assignments.
Al cierre:2507 empleados (5 DEMO +2502 MANUAL); SYBI7 sigue DRAFT y sin geofence/Device,
pero tiene1 assignment. No puede informarse como RESERVED/intacto conforme al gate original.

Evidencia de sólo lectura en la DB real (timestamps tal como están persistidos):

- Los2502 MANUAL se crearon entre2026-09-08 19:53:07 y19:53:31.
- Audit2699: employee.import.completed, actor_user_id2, employee_import_runs2.
- Audit2700: assignment.created, actor_user_id2, assignment7,19:55:14.
- Assignment7: Employee interno210, source MANUAL, ACTIVE, created_by2;
  attendance/enrollment/maintenance_allowed true. No se imprimen datos personales.
- También aparece device.provisioning_token.created con actor2; no se consultó ni imprimió token.

Se consultó al operador si fueron operaciones manuales de otra sesión. La auditoría
identifica actor y operaciones, pero no basta por sí sola para atribuir autoría humana
o intención. No se borró, revocó ni restauró dato alguno.
El harness sólo generó Employee FORTIA sintético y bases con esquema nuevo aislado;
su query final de information_schema confirma0 esquemas field_support_136b1_test_* restantes.
La prueba2502 de importación en PHPUnit verifica preview sin escrituras de empleados.

La suite ya había terminado cuando se intentó detenerla al detectar el cambio.
En ese momento el cierre quedó pendiente de aclaración, sin fingir que SYBI7 tenía0 assignments.
La investigación posterior autorizada resolvió ambos orígenes y añadió sólo una prueba
aislada del importador:37 tests dirigidos PASS. No se repitieron los gates MySQL costosos.

Archivos nuevos:

- app/Services/Support/SupportActivityPresencePolicy.php
- database/migrations/2026_09_08_160000_index_support_activity_queries.php
- tests/Support/field_support_mysql.php
- docs/architecture/vending-support-activity-mysql-validation.md

Archivos modificados respecto del inicio13.6B.1:

- app/Enums/Support/SupportActivityType.php
- app/Services/Support/SupportActivityService.php
- app/Http/Requests/Support/SupportActivityRequest.php
- tests/Feature/Support/SupportActivityTest.php
- docs/architecture/vending-support-activity-domain.md

No commits, tags, push, deploy, cambios de entorno ni migración sobre datos reales.
