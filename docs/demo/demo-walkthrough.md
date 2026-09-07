# Demo Vending Attendance — recorrido controlado de 14 minutos

## Presentación Fase 12 — revisión final completada

La preparación funcional de Fase 11 se conserva. El cierre UI/UX de Fase 12
cuenta con revisión externa web y validación física final de Android;
ver [ui-ux-final-review.md](ui-ux-final-review.md). No equivale a aprobación productiva.
No repetir un reset para mejorar contadores: ejecutar primero
`php artisan vending:demo-preflight`, que es de sólo lectura y valida la demo local.
`vending:pilot-preflight` conserva sus requisitos productivos y no lo sustituye.

En Android, presentar el flujo así:

1. Revisar «En línea» y el número real de asistencias pendientes. La red por sí
   sola no demuestra recepción por el servidor.
2. «Selecciona tu nombre»: usar nombre o número; «Limpiar» restablece la lista.
   Las versiones y la máquina se consultan en «Información de la terminal»,
   colapsada inicialmente. No son el paso principal del trabajador.
3. Confirmar empleado y pulsar «Registrar entrada» o «Registrar salida».
   Durante GPS, esperar; no repetir la captura mientras los botones estén deshabilitados.
4. «Asistencia guardada» significa almacenamiento local, todavía sin confirmación.
   «Asistencia registrada» aparece únicamente al leer un recibo SYNCED real.
   Entrada/Salida y hora local se muestran sin UUID, coordenadas ni precisión GPS.
5. «Fuera de la zona permitida» es una advertencia independiente de la recepción.
   No mover la geocerca ni presentar la recepción como ubicación válida.

En web, usar OPERACIÓN / INTEGRACIONES / ADMINISTRACIÓN. No se agregaron rutas
ni permisos. Dispositivos muestra sincronización agregada y pendientes; «Ver
detalle» conserva versiones y estados de configuración/empleados. Fortia mock
se identifica como «Fuente de prueba». El wizard de importación y su confirmación
siguen intactos; no importar archivos reales para esta revisión visual.

Ensayo histórico inicial del 7 de septiembre de 2026: dos eventos DEMO adicionales, ids
12/13, conservando los once anteriores. Entrada online recibida en 3 s; Salida
offline recibida después de recuperar Wi-Fi (71 s desde captura). Estos tiempos
son evidencia de ese ensayo, no una promesa de latencia ni de estado futuro.
El ensayo final de cierre añadió una única Salida expresamente autorizada,
id 19, observada pendiente sin conexión y confirmada al recuperarla. Los 18
registros anteriores quedaron intactos; Wi-Fi y datos móviles se restauraron.
Las secciones históricas siguientes corresponden al cierre funcional de Fase 11.

Baseline UX: vending-phase-10-pass. Fase 11: PASS.
Validación funcional: PASS / READY_FOR_LIVE_DEMO.
Completar [demo-checklist.md](demo-checklist.md) antes de presentar.
No iniciar si falta conexión física, manifests SYNCED, outbox 0 o existen HIGH.
Estado vigente y pruebas medidas: [final-demo-validation.md](final-demo-validation.md).
Ensayos offline y online de la APK final comprobados; reset repetido sin cambios
de historial, credenciales o datos ajenos. La autorización directa del usuario
resolvió el bloqueo de captura/reset y posteriormente autorizó el commit/tag
de Fase 11, sin push. Producción permanece NOT_APPROVED.

## Preparación fuera del cronómetro

El responsable técnico ejecuta `php artisan vending:demo-reset`
sólo en local/testing. Conserva VM-DEMO-001, el Android existente, geocerca
y todo el historial. No usar seeder/cleanup antiguo ni resetear passwords.
Anotar conteo inicial, fecha CDMX/UTC y los UUID de cada evento del ensayo.

Presentador: **pilot.operator@example.test**, Pilot Operator. Introducir su
contraseña fuera de proyección. No conceder permisos adicionales. Pilot Admin
sólo prepara cambios DEMO expresamente autorizados; Support/Viewer son lectura.

Teléfono: HONOR 400 / ANDROID-DEMO-001, app existente. Comprobar Sincronizar,
VM-DEMO-001, empleados y retorno sin activación tras cerrar/reabrir.
Actualizar únicamente con install -r cuando se autorice; nunca desinstalar,
borrar almacenamiento seguro ni reprovisionar.
Biometría: **NOT_USED**; no presentarla como verificación de identidad biométrica.

## Recorrido y recuperación

Los intervalos son un presupuesto operativo de 14 minutos, **no tiempos físicos
ya medidos**. Los gates físicos están comprobados en el informe; el recorrido
completo no se cronometró como una presentación continua. Registrar los tiempos
de cada nueva demo. Si recuperar un paso supera su intervalo, pausar,
registrar el incidente y no afirmar que el recorrido concluyó en 14 minutos.

| Tiempo previsto | Abrir / ejecutar | Explicación y resultado esperado | Recuperación si falla |
| --- | --- | --- | --- |
| 0:00–1:00 | Login Operator; /vending | Navegación por tareas y estado real inicial. Un dispositivo habilitado no necesariamente está en línea. | Confirmar cuenta/URL correctas sin resetear passwords; detener si falla acceso. |
| 1:00–2:00 | /vending-machines, buscar VM-DEMO-001 y abrir detalle | Máquina ACTIVE, geocerca DEMO y dos asignados: Empleado Demo Uno (990001001) y Supervisor Demo (990001004). | Si no coincide, no asignar improvisadamente. Volver al preflight; sólo configuración DEMO autorizada. |
| 2:00–3:00 | /vending/employees | Cinco sintéticos source DEMO, números estables, no CURP/RFC/NSS reales. Fortia identificado como modo de prueba. | Si aparecen datos reales, quitar proyección y usar filtros; no modificarlos. No activar Fortia real. |
| 3:00–4:00 | /vending-machines, pestaña Catálogo SYBI | Separar catálogo fuente y operación. Mostrar sólo datos autorizados; no sincronizar, promover ni editar origen. | Si hay mojibake, explicar SOURCE_ENCODING_ISSUE documentado. No recodificarlo. |
| 4:00–5:00 | Android: inicio → Sincronizar | Identidad persistida, VM-DEMO-001, configuración y empleados vigentes, pendientes 0. Mostrar los dos empleados autorizados. | Si solicita activación, detener y diagnosticar identidad; no reprovisionar. Error de red: verificar Wi-Fi/IP/puerto y app en primer plano. |
| 5:00–6:30 | Android: Empleado Demo Uno → Entrada | Captura CHECK_IN con GPS real y mensaje de espera. “Asistencia registrada correctamente” sólo tras recibo confirmado. UUID únicamente en la hoja técnica privada. | Si tarda GPS, esperar el plazo real; no fake GPS. Conservar “Fuera de la zona permitida” si corresponde. Una vez guardado, no volver a pulsar Entrada para intentar enviarlo. |
| 6:30–7:30 | Android → Salida online; comprobar ambos recibos | CHECK_OUT con GPS nuevo; pending 0 tras envío, una fila por UUID con STORED y NOT_USED. | Si queda pendiente, Sincronizar y esperar reintento; no duplicar capturas ni asumir recepción por la red “En línea”. |
| 7:30–8:30 | Cortar Wi-Fi y datos móviles; volver al mismo empleado → Entrada | CHECK_IN guardado OFFLINE; anotar su UUID sólo en privado. Pending local aumenta a 1; todavía no hay fila servidor. | Confirmar que no quedó otra red activa. Si el evento llegó al servidor, no presentarlo como prueba offline; planificar otro ensayo explícito. |
| 8:30–9:30 | Cerrar y reabrir la app sin red | Misma identidad, empleados disponibles y pending=1 persistente. No volver a registrar Entrada. | Si desaparece la cola o exige activación, detener; conservar almacenamiento/evidencia para diagnóstico, nunca borrar datos. |
| 9:30–11:00 | Restaurar red; Android en primer plano → Sincronizar | Enviar el mismo UUID; pending=0 y sync_outbox=SYNCED con remote_id. Una fila servidor, sin duplicado. | Esperar el backoff real (puede alcanzar 5 minutos); medir demora, no bajar intervalos ni marcar SYNCED por SQL. |
| 11:00–12:00 | Consulta técnica de los UUID; prueba de reenvío previamente autorizada | Una fila por UUID. Reenviar el mismo payload con autenticación y nonce nuevos devuelve DUPLICATE. Pulsar Sincronizar con cola vacía no demuestra reenvío. | No editar SQLite, payload ni UUID. Sin arnés autorizado, citar la prueba documentada y no afirmar una nueva ejecución. |
| 12:00–13:00 | /vending y /vending/devices, recargar | Recibidas hoy aumentan respecto al inicio, conexión reciente, pendientes actuales 0, manifests sincronizados y alertas reales. | Un pending antiguo en servidor durante el corte es normal: contrastar timestamp y outbox local. No falsificar salud ni ocultar HIGH. |
| 13:00–14:00 | Auditoría y cierre | El responsable autorizado muestra evidencia sanitizada; los cuatro pilotos no tienen acceso a /settings/audit y reciben 403. Resumir trazabilidad y límites: biometría NOT_USED, Fortia mock y geocerca observada. | No ampliar RBAC ni abrir asistencia legado como si fuera Vending. Sin acceso autorizado, usar el informe de evidencia; no simular una pantalla accesible al Operator. |

El paso OFFLINE es posterior al estado saludable inicial. No es obligatorio
esperar diez minutos para que el servidor clasifique OFFLINE: se demuestra
ausencia real de red y persistencia local. Explicar la demora natural del
indicador de salud sin cambiar cálculos o umbrales.

La UI Android oculta UUID, coordenadas y precisión, y presenta distancia humana,
geocerca en español y confirmación individual leída del outbox. PENDING no es
SYNCED. El responsable técnico contrasta el UUID en privado mediante lectura
local/servidor; el dashboard no sustituye esta prueba.
Si la app no ofrece visor de cola, inspeccionar únicamente event_uuid, status y
remote_id de sync_outbox mediante diagnóstico local autorizado. Si se requiere
una copia temporal controlada de SQLite DEMO, obtener autorización específica
para datos/destino y mantenerla privada fuera de Git; no es un paso obligatorio.
nunca descargar Secure Storage, mostrar credenciales o publicar esa copia.

## Geocerca y asistencia

Mantener la geocerca DEMO existente. Si el teléfono está fuera, presentar OUTSIDE
correctamente (opción A), sin afirmar INSIDE a partir del estado STORED.
Una geocerca local distinta (B) requiere autorización expresa de Pilot Admin,
ubicación real comprobada y una nueva versión auditada exclusivamente DEMO;
prepararla antes del ensayo, esperar ACK y conservar historial. El reset no la
crea ni modifica las coordenadas SYBI. El ensayo documentado obtuvo OUTSIDE
en edge y servidor; no certifica estar dentro de la zona.

No se promete una lista nueva de asistencias Vending al Operator ni se abre
el módulo de asistencia legado como si fuera equivalente. Usar el KPI existente
y consulta técnica autorizada para corroborar las filas de Vending.

## Plantilla para próximos ensayos

No rellenar con tiempos estimados ni reutilizar la asistencia histórica como
prueba nueva. No guardar passwords, tokens, credenciales o capturas personales.

| Evidencia | Inicio real CDMX/UTC | Fin real / duración | UUID / resultado / responsable |
| --- | --- | --- | --- |
| Preflight: ONLINE, SYNCED, outbox 0, HIGH 0 | Pendiente | Pendiente | Pendiente |
| CHECK_IN online y recibo servidor | Pendiente | Pendiente | Pendiente |
| CHECK_IN offline, pending=1 | Pendiente | Pendiente | Pendiente |
| Reapertura offline y persistencia | Pendiente | Pendiente | Pendiente |
| Reconexión, SYNCED y fila única | Pendiente | Pendiente | Pendiente |
| Dashboard tras los eventos | Pendiente | Pendiente | Pendiente |
| Total y recuperación de fallas | Pendiente | Pendiente | Pendiente |

## Repetir la demo

Sin eventos pendientes, cerrar el ensayo anotando sus UUID y conteos. El reset
recupera la configuración permitida pero no elimina evidencia, cambia UUID de
device ni vacía outbox. Conservar conteos acumulados y preparar una nueva pareja
de eventos físicos. Un escenario con asignaciones revocadas, device suspendido
o geocerca vencida necesita revisión administrativa; no se “repara” el historial.

Import CSV/XLSX sigue disponible y aprobado en Fase 10, pero no es obligatorio
en este recorrido end-to-end de 14 minutos. Si se demuestra aparte, usar sólo
archivo sintético, preview y confirmación explícita; nunca el dataset real de
2,502 empleados. No conectar Fortia real ni alterar contratos para presentarlo.
