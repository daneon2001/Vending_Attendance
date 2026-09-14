# Fase 13.6C — revisión web de actividades de campo

Actualización 13.6C.1: revisión aislada en Chromium completada y dos defectos de
presentación corregidos. Ver [entorno visual](vending-field-support-visual-environment.md).
El registro siguiente conserva la evidencia histórica de C; sus pendientes visuales
se actualizan en ese documento. La DB real continúa sin habilitación ni migraciones.

Fecha: 2026-09-08. Proyecto exclusivo vending-attendance.
Rama conservada: phase/14-biometric-engine-selection.
HEAD de inicio: 702b641ef803793d025903435b81a26fc1f48a0c.

PHASE 13.6C: PARTIAL. Implementación técnica para revisión externa.
VISUAL REVIEW: PENDING_EXTERNAL. No se declara PASS visual ni producción.
Código: READY_FOR_VISUAL_REVIEW en un entorno autorizado con esquema e identidad
habilitados. La instalación real actual no está habilitada para ejecutar ese recorrido.

## Gate de inicio y protección

Se revisaron status, rama, HEAD y diff check antes de editar. Se conservaron todos los
cambios previos de 13.6A/B/B.1 y los documentos de Phase 14/14A/14B. Sin reset/stash/clean.

Baseline real de sólo lectura al inicio: 5 usuarios, 2507 empleados (2502 MANUAL + 5 DEMO),
attendance_logs 0, vending_attendance_events 19. SYBI identificador 7: una fuente,
proyección DRAFT, 0 geofences, 0 Devices y assignment 7 autorizado. No se utilizó para pruebas.
ASISTENCIAS_FORTIA clean. Ninguna reclasificación MANUAL → FORTIA.

demo-preflight de inicio: FAIL. Heartbeat 8661 s / <180 s, network último ONLINE,
configuration STALE server 3 / applied 3, employees STALE server 5 / applied 5,
outbox 0, HIGH 1, MEDIUM 1 informativa. No se generó heartbeat ni actividad artificial.
La falta de frescura no invalida evidencia física histórica aprobada.

La DB real sigue sin users.employee_id y sin las tablas de actividades. No se ejecuta
migrate/migrate:fresh/migrate:refresh contra datos persistentes. Los tests usan SQLite
en memoria y fixtures sintéticos; ninguna actividad/ticket/cuenta real se crea.

## Discovery y diseño reutilizado

Se siguió design-web-frontends sobre el diseño real de Vending, no sobre una arquitectura
visual SaaS distinta. Se reutilizan AuthenticatedLayout, card/text-app/text-soft/border-app,
btn-primary/SecondaryButton, AdvancedFilters, TechnicalDetails, EmptyState y RecordPagination.
Tailwind usa los breakpoints existentes; no se altera sidebar, tokens, tipografía ni layout.

SupportActivityList se reutiliza en listado y resúmenes. Cuatro columnas agrupadas:
actividad/folio/máquina/tipo, técnico, estado/geocerca/ticket, fechas (última visible desde xl).
La fecha de creación aparece bajo técnico en anchos inferiores a xl. Detalle conserva
inicio, fin, dirección, descripción y snapshot. No se eliminan datos del dominio.

SupportActivityPicker aplica el patrón existente de búsqueda explícita + select nativo,
añadiendo paginación de servidor. No se reutiliza un filtro sólo local que obligaría a
enviar 2507 empleados. Búsqueda por botón/Enter, labels, loading/error/empty, cancelación
de solicitudes obsoletas, retención de selección y páginas de 20. No nuevas dependencias.

Los colores de badges están presentes en el CSS construido, incluidos temas claro/oscuro.
La cancelación usa confirmación nativa del navegador, como el flujo de tickets existente;
no se introduce un modal paralelo sin gestión de foco.

## Rutas y contratos

Todas las rutas pertenecen al grupo support existente: auth, verified, SupportContext:user.
CSRF y throttle:support-write conservados en mutaciones.

| Método | Ruta | Uso web |
|---|---|---|
| GET | /support/activities | Listado Inertia; Accept JSON conserva respuesta anterior |
| GET | /support/activities/create | Formulario administrativo |
| GET | /support/activities/options | Búsqueda paginada de máquinas/empleados/tickets |
| GET | /support/activities/summary | Hasta 5 actividades visibles por ticket/máquina |
| POST | /support/activities | Crear/asignar con receipt idempotente existente |
| GET | /support/activities/{uuid} | Detalle Inertia o contrato JSON anterior |
| POST | /support/activities/{uuid}/cancel | Cancelación por servicio existente |

Opciones/resumen tienen Cache-Control private,no-store. No se cambia API de devices,
HMAC, asistencia, manifests, soporte externo ni los endpoints de ejecución física.

Filtros: búsqueda de folio/título/máquina/empleado, estado, tipo, máquina, empleado,
fecha de creación desde/hasta y relación con ticket visible. Fecha CDMX traducida a
predicados UTC [inicio, día siguiente). No timestamps ISO en el texto principal.
El filtro ticket_uuid se preserva desde un resumen y Limpiar lo elimina.

El folio ACT-000001 deriva del ID: no secuencia nueva, cambios de UUID ni tablas adicionales.
El folio INC del ticket usa su accessor existente (created_at + id), no una columna inventada.

## Identidad, permisos y scope

- Listado/detalle exigen support.view y la identidad activa resuelta por 13.6A.
- El ámbito sigue siendo EmployeeMachineAssignment vigente; sin support.assign,
  sólo actividades propias. support.view_all no concede acceso global a actividades.
- Crear/asignar exige support.assign, máquina ACTIVE no reservada, asignación del actor
  y elegibilidad del empleado destino según SupportActivityAccess/servicio existentes.
- Selector de empleados para crear: activo, FORTIA, source_external_id no vacío,
  assignment vigente en la máquina; maintenance_allowed cuando el tipo lo exige.
- Employee sin User puede seleccionarse/asignarse. No se crea cuenta ni vínculo.
  La UI distingue sin cuenta, cuenta desactivada y cuenta habilitada; esto no concede ejecución.
- Los 2502 importados MANUAL siguen conservados pero no se convierten silenciosamente
  en identidades FORTIA elegibles. Esa reconciliación requiere decisión posterior.
- Los tickets tienen scope independiente de soporte. No se exponen folio, UUID ni link
  si no son visibles para el actor, aunque la actividad sí sea visible. El filtro sí/no
  se denomina explícitamente ticket relacionado visible.
- Cancelar exige las condiciones actuales del servicio y motivo obligatorio ya existente.
  No se inventó una regla nueva. Estado terminal/conflicto no crea segundo evento.
- UI: CTA y cancelación reciben capacidades del backend. Navegación Actividades requiere
  permiso support explícito, sin bypass settings.manage. La ruta sin identidad muestra
  un aviso seguro, no un listado global ni un estado vacío engañoso.

ZONAL WEB: PARTIAL. Puede usar el scope de assignments si tiene identidad y permisos
existentes. Falta Operational Scope zonal explícito; no se infiere por nombre de rol,
empresa, sucursal o view_all, ni se amplía acceso para la demo.

## Workflow, ubicación y auditoría

Crear y asignar son una operación existente, sin GPS. El UUID de operación se mantiene
al reintentar el mismo contenido y cambia al cambiar contenido. Backend mantiene
hash canónico, autorización viva, receipt persistente y transacción.

Reasignación: DEFERRED. No hay transición de dominio para ello; no se edita employee_id.
START/COMPLETE: no botones web administrativos ni coordenadas fabricadas. Se reserva
la experiencia física móvil para otra fase. El endpoint previo sigue intacto: start exige
GPS/geofence; complete exige la identidad que inició. No se introduce GPS final inexistente.

Snapshot: se presenta resultado, distancia, accuracy, fecha y versión almacenados en actividad.
No se consulta geocerca actual ni se recalcula. No se carga GeofenceMap: la actividad no
contiene centro/radio completos congelados para representarlos con fidelidad. Recuperar
la geometría actual daría una representación histórica engañosa. No peticiones OSM.
No se exponen coordenadas precisas, ni source_external_id, credenciales o PII adicional.

Timeline consulta vending_support_activity_events, ordenado occurred_at/id, con actor
y fecha CDMX. Eventos existentes: created, assigned, started, completed, cancelled.
La validación de ubicación se explica dentro del evento started cuando existe snapshot;
no se inventa otro evento persistido. Auditoría general no se duplica en el timeline.
Detalle técnico colapsado: referencia UUID, policy y versión. Sin JSON crudo principal.

Ticket 1:N: resumen enlazado y limitado; completar/cancelar actividad no cierra ticket,
cerrar ticket no elimina historia (invariantes de dominio preservadas).
Máquina: resumen de últimas actividades y link al historial filtrado, separado de asistencia.
Empleado: NOT_IMPLEMENTED; no hay página individual apropiada en el catálogo Vending,
y no se recarga cada fila con consultas/resúmenes ni se modifica el drawer de asistencia.

## Performance y pruebas

Listado fijo 25, eager loading y allow-lists. Misma consulta con 1 y 25 actividades:
9 consultas en ambos casos; prueba impide crecimiento N+1. Resúmenes máximo 5.
Opciones simplePaginate 20 (consulta acotada), sin Employee::all ni Machine::all.
Escala sintética comprobada: 2507 empleados y 1000 máquinas; páginas finales y búsqueda
de últimos registros, sin enviar catálogo completo ni crear cuentas.
Índices existentes status/date, machine/status, employee/status y ticket se conservan.
Búsqueda contains no se anuncia como index-only ni como SLO de MySQL productivo.
No se repiten pruebas de concurrencia MySQL del servicio inalterado.

Pruebas nuevas: SupportActivityWebTest (13 PASS / 448 assertions) y
tests/Frontend/supportActivities.test.js (10 PASS).
Frontend completo: 123 PASS. Build: PASS, sólo aviso de antigüedad de browserslist.
Regresión Laravel dirigida (Support, Vending, autorización de asistencia, Auth, Permissions,
Profile): 368 PASS / 3073 assertions. Suite completa: 706 PASS / 1 FAIL / 5891 assertions;
única falla OnPremDiagnosticsCommandTest en línea 54, ya documentada en baseline.
NEW REGRESSIONS: 0. No se corrigió ni relajó esa prueba heredada.
Pint scoped y diff check se ejecutan sobre archivos de esta fase, preservando anteriores.

## Gate visual externo pendiente

No se abre navegador ni se certifica diseño por SSR. Tests de render son evidencia técnica,
no evidencia visual. Solicitar revisión manual en 1920×1080 y 1366×768 de:

1. /support/activities: filtros inicialmente colapsados, tabla legible, vacíos y paginación.
2. Nueva actividad: búsquedas, cambio de máquina/tipo limpia selección dependiente,
   cuenta ausente visible; no solicitar GPS al crear.
3. Detalle: snapshot, nombres, fechas CDMX, historial, cancelación autorizada y detalle cerrado.
4. Ticket con varias actividades: links sin revelar tickets fuera de scope.
5. Máquina con resumen: máximo 5, link al historial y ausencia de mezcla con asistencia.
6. Navegación por teclado, foco, estados loading/error, sin overflow/clipping y tema oscuro.

CAPTURAS: PENDING_EXTERNAL, sin nombres/datos personales reales innecesarios.
El entorno real carece de esquema e identidad habilitados: allí el listado muestra el
aviso de habilitación pendiente. No afirmar que se puede recorrer detalle real ahora.
Para revisar páginas pobladas usar entorno de testing aislado con fixtures autorizados,
o solicitar autorización separada antes de migrar/vincular/crear una actividad DEMO real.
No usar SYBI 7; no registrar asistencias; no crear datos por conveniencia visual.

## Fuera de alcance y cierre

No migraciones nuevas, migraciones reales, datos reales, Android, Face ID, biometría,
push, offline, contratos SYBI/Fortia ni modificaciones a ASISTENCIAS_FORTIA.
No commit/tag/push/deploy. Phase 14 y los cambios previos se preservan.

Comprobación final de sólo lectura: employees 2507, users 5, attendance_logs 0,
vending_attendance_events 19. SYBI 7 DRAFT sin geofence/Device; assignment 7 permanece
ACTIVE con sus tres capacidades y sin cambio de updated_at. ASISTENCIAS_FORTIA clean.
Hashes idénticos para User/Employee, SupportActivityAccess/Service, tres migraciones
13.6A/B/B.1 y todos los documentos biometric-*. Sin migración real ni tabla de actividades
creada en la DB local. Escaneo de patrones de secretos sin hallazgos en los archivos de esta fase.
