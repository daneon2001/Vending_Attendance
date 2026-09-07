# Fase 13 — revisión del checkpoint local (2026-09-07)

Implementación local y pruebas automatizadas cerradas; gate físico de soporte PASS
tras autorización explícita de dos reportes DEMO y una verificación. Revisión web
externa confirmada por el operador. Gates de código/seguridad aprobados; el checkpoint
sigue bloqueado porque el HONOR volvió a reposo antes del commit y el heartbeat caducó.
Las capacidades diferidas siguen identificadas por separado. No certifica producción ni
capacidad de 1,000 equipos. No se creó commit/tag/rama ni se hizo push, remote o despliegue.

## Resultado

| Campo | Resultado / evidencia |
| --- | --- |
| FASE 13 CHECKPOINT | BLOCKED:DEMO_PREFLIGHT_NOT_CURRENT; gates de código/seguridad PASS |
| START GATE | PASS: rama phase/13-support-incidents, HEAD da5e6a2d2edc35d7b0fe9fc7d430f62543e55d2a, tag vending-phase-12-ux-pass, árbol limpio al inicio |
| DISCOVERY | PASS: inventario REUSE/EXTEND/CREATE y conflictos documentados |
| ARCHITECTURE DECISIONS | ADR-VEND-019; un dominio, filesystem privado, SQLite soporte independiente, receipts, correlación, notificaciones y SLA versionado |
| DOMAIN MODEL | SupportTicket canónico con folio, actores, relación máquina/Device, historial aditivo, workflow y evidencia |
| MIGRATIONS | PASS: dos aditivas aplicadas localmente, sólo soporte/notifications |
| TICKETS | PASS: INC-2026-000001 online e INC-2026-000002 offline, sin duplicados |
| FOLIO CONCURRENCY | PASS: seis workers MySQL aislados, tres repeticiones completas tras fix de locks |
| STATUS WORKFLOW | PASS |
| EVIDENCE | PASS: dos fotos neutras físicas confirmadas y consultadas |
| PRIVATE STORAGE | PASS: disco Laravel privado y Data Android; copia local conservada hasta ACK |
| MIME SECURITY | PASS |
| EVIDENCE HASH | PASS: dos hashes origen/sanitizado, verificación previa a servir |
| LOCATION | PASS: GPS real online/offline; precisión 15.721 m y 14.18 m, contexto OUTSIDE |
| OFFLINE SUPPORT | PASS: reporte/foto locales, reinicio, reconexión automática y ACK reales |
| RESTART PERSISTENCE | PASS: reporte y foto sobreviven force-stop/reapertura sin red |
| RECONNECT | PASS: Wi-Fi/datos restaurados; ticket y evidencia confirmados automáticamente |
| IDEMPOTENCY | PASS: tests/MySQL y dos tickets únicos tras reconexión, actualizaciones y reinicios |
| DEVICE VERIFICATION | PASS ejecución/persistencia física y consulta web; resultado técnico NOT_AVAILABLE, no certificado de salud total |
| AUTO TICKETS | PARTIAL: implementación/tests PASS, deshabilitado localmente, ninguna política real activada |
| CORRELATION | PASS |
| ANTI-STORM | PASS: 100 repeticiones y carreras MySQL |
| RECOVERY | PASS: UNKNOWN no recupera y recuperación real no cierra ticket |
| WEB SUPPORT | PASS: fotos, estado, asignación, comentario y verificación confirmados externamente |
| RBAC | PASS: módulo aditivo estricto, matriz de roles y objeto probados |
| AUDIT | PASS: reutilizado, metadata segura; semántica existente intacta |
| SLA | PARTIAL: preparado/versionado/probado; sin SLA Medical Life ni scheduler productivo configurados |
| WEB NOTIFICATIONS | PASS: feed real, dos leídas por Pilot Support, contador actualizado según revisión externa y DB |
| MOBILE NOTIFICATIONS | PASS: cambios web reflejados; contador 7 -> 6 al leer, persistente tras reinicio |
| REMOTE PUSH | DEFERRED_CONFIGURATION |
| SECURITY | PASS en alcance local probado; no certifica gates de producción |
| PERFORMANCE | PARTIAL: acotamiento/índices/EXPLAIN/carreras PASS; no prueba de carga 1,000 Devices |
| ANDROID PHYSICAL | PASS: 17 pasos físicos comprobados, alcance DEMO autorizado |
| ONLINE TICKET | PASS: INC-2026-000001 |
| ONLINE EVIDENCE | PASS: una foto neutra CONFIRMED |
| OFFLINE TICKET | PASS: INC-2026-000002 |
| OFFLINE EVIDENCE | PASS: una foto preservada offline, subida tras ACK del ticket |
| PHYSICAL LOCATION | PASS: ubicación fresca adjunta a ambos reportes; coordenadas omitidas |
| DEMO PREFLIGHT BEFORE | PASS al inicio y nuevamente antes de instalar |
| DEMO PREFLIGHT AFTER | PASS después de los dos reportes y verificación: ONLINE, manifests2/2 y5/5, pending0, HIGH0 |
| DEVICE SUPPORT API | PASS automatizado: HMAC existente, nonce/timestamp, máquina derivada, límites y scopes de contexto |
| EXTERNAL SUPPORT API | PASS automatizado local: dominio común, scopes y allowlist; no integración externa real configurada |
| SERVICE AUTH | Principal independiente, tokens expirables/revocables/rotables; ningún token real emitido |
| API SCOPES | Nueve scopes exactos, sin wildcard ni jerarquía implícita |
| EXTERNAL IDEMPOTENCY | PASS por principal+operación y referencia externa/fingerprint |
| WEBHOOKS | DEFERRED_CONFIGURATION: adapter sin entrega; feed incremental disponible |
| OPENAPI | PARTIAL: contrato y validación estructural/route parity PASS, sin certificación completa de dialecto |

## Pruebas y compilación

| Comando / gate | Resultado |
| --- | --- |
| Laravel Support dirigido | 84 PASS / 563 aserciones |
| Laravel Vending dirigido | 172 PASS / 1185 aserciones |
| Frontend Node tests | 93 PASS |
| Mobile npm test | 251 PASS / 29 archivos (63 nuevas soporte) |
| Android native | 4 PASS, ejecución forzada y repetida tras cap sync |
| Full Laravel final | 632 PASS / 1 FAIL heredado OnPremDiagnosticsCommandTest, 4775 aserciones |
| Full baseline protegido | 548 PASS / misma falla heredada; 84 nuevas soporte PASS |
| MySQL concurrency | Tres repeticiones completas PASS tras deadlock fix; repetición adicional PASS tras revalidación Device |
| Web npm run build | PASS, 870 módulos |
| Mobile npm run build | PASS, incluido TypeScript |
| npx cap sync android | PASS, Camera7.0.5 y Filesystem7.1.8 enlazados, ocho plugins |
| Android :app:assembleDebug | PASS |
| Pint scoped | PASS; 62 PHP revisados, estilo corregido sólo dentro de Fase13 |
| git diff --check | PASS |

Estos resultados automatizados corresponden a la implementación final previa al gate
físico. Durante la continuación física no se modificó código ejecutable: sólo este
informe. No se repitieron las suites/builds sin cambios de código; sí se repitieron
demo-preflight, controles de integridad/datos/Git y validaciones físicas al terminar.

El intento inicial de Gradle offline no tenía dependencias de Filesystem en cache;
la descarga normal las resolvió. El intento de merged manifest release fue rechazado
por verifyPilotReleaseConfiguration al usar configuración local: comportamiento fail-closed
conservado, no un release construido. La compilación debug independiente pasó. Manifest main
mantiene cleartext=false, debug override=true, Capacitor allowMixedContent=false y MainActivity
sólo habilita mixed content para FLAG_DEBUGGABLE. Ninguno de esos guards fue relajado.

Advertencias no bloqueantes de build: Browserslist, Tailwind/Ionic, tamaño de chunk,
flatDir y warnings propios del plugin Filesystem. No se añadieron plugins no autorizados.

## Base de datos y protección

Once tablas nuevas: support_integrations, support_integration_machines, support_policy_versions,
support_tickets, support_runtime_cursors, support_ticket_events, support_operations,
support_evidence, support_verifications, support_correlations, notifications.
La segunda migración agrega fingerprint, warning timestamps/indexes y link verification/ticket
a las tablas nuevas; no altera tablas/protocolos de asistencia. Historial con FKs RESTRICT.

Seeder local agrega sólo nueve permisos support y vínculos aditivos a roles piloto existentes;
no crea usuarios/roles ni cambia passwords o permisos anteriores. No hay políticas activadas,
integraciones/tokens reales. Con autorización posterior se conservaron dos tickets DEMO,
dos fotografías confirmadas y una verificación física; ninguna asistencia nueva.

Antes/después idénticos por SHA-256 sobre filas ordenadas: vending_machines4,
machine_geofences3, employee_machine_assignments6, vending_attendance_events19,
device_provisioning_tokens6. No se imprimieron datos sensibles de esas filas.
Heartbeat/telemetría/nonce continúan cambiando por el funcionamiento normal de la APK.

SYBI vending identifier7 corresponde a source id683 y proyección DRAFT; se comprobó
0 geofences, 0 Devices, 0 assignments. El guard nuevo de soporte ya usa identifier,
no confunde la clave de fuente con el código de máquina. Dos negativos RED -> PASS.

## Gate físico completado — 7 de septiembre de 2026 (CDMX)

La pausa inicial por falta de autorización terminó con aprobación explícita del usuario.
Se utilizaron exclusivamente VM-DEMO-001 y ANDROID-DEMO-001, sin desinstalar, borrar datos,
reprovisionar ni registrar asistencias. No fue necesaria otra instalación de APK.
La cámara se reencuadró a objetos neutros antes de cada disparo. Las capturas de validación
se conservaron fuera de Git; las fotografías de los tickets permanecen en storage privado.

| Paso físico | Resultado / evidencia real |
| --- | --- |
| 1. Abrir Soporte | PASS: pantalla nativa observada en HONOR 400 |
| 2. Reportar online | PASS: Demo Fase 13 online, una intención |
| 3. Tomar foto | PASS: cámara nativa, objeto neutro, copia Data privada |
| 4. Capturar ubicación | PASS: GPS real, precisión 15.721 m |
| 5. Enviar | PASS: pendiente -> confirmado, INC-2026-000001 |
| 6. Ticket web | PASS: operador confirmó foto y reporte en navegador real |
| 7. Comentar/asignar web | PASS: Pilot Admin asignó a Pilot Support, estado WAITING, comentario exacto Validación web DEMO Fase 13; no cierre |
| 8. Reflejar web en Android | PASS: En espera, comentario visible y novedades recibidas |
| 9. Crear offline | PASS: Wi-Fi=0/datos=0; reporte/foto pendientes, servidor aún con un ticket |
| 10. Cerrar/reabrir | PASS: force-stop y arranque reales, manteniendo ambas redes apagadas |
| 11. Persistencia | PASS: título, descripción, ubicación, referencia y archivo de foto conservados |
| 12. Reconnect | PASS: Wi-Fi=1/datos=1 restaurados; envío automático en primer plano |
| 13. Ticket único | PASS: INC-2026-000002; total dos antes/después de refrescar y reiniciar |
| 14. Evidencia subida | PASS: CONFIRMED y miniatura descargada/visualizada en Android |
| 15. Outbox limpio | PASS: SQLite soporte con cero operaciones no confirmadas |
| 16. Device Verification | PASS: una sesión a las 13:49, confirmada y auditada |
| 17. Verificación web | PASS: Pilot Support confirmó equipo/fecha/comprobaciones en navegador real |

El segundo reporte se guardó a las 13:37 y se confirmó hacia las 13:45, después del
reinicio offline. GPS con precisión 14.18 m. Ambos usan OUTSIDE sólo como contexto;
no bloqueó soporte ni cambió el algoritmo. No se publican coordenadas completas.

Evidencias: dos JPEG gd-raster-v1, tamaños sanitizados 270909 y 238256 bytes.
Hash del archivo almacenado y miniatura recomputados correctamente para ambas.
La foto offline privada de 172747 bytes sobrevivió al reinicio y su SHA-256 coincidió
con upload_sha256 recibido. No se purgó antes de confirmación; luego ambas copias
canónicas locales fueron retiradas por la app, conservando metadata y archivos servidor.

Lectura diagnóstica de SQLite soporte, con app detenida y sin WAL pendiente:
integrity_check=ok; tickets ACKNOWLEDGED=2; CREATE_TICKET=2, RESERVE_EVIDENCE=2,
UPLOAD_EVIDENCE=2, VERIFICATION=1, todas ACKNOWLEDGED; no confirmadas=0.
Evidence CONFIRMED=2/purged=2; feed=7/leídas=1. Se reabrió la app y persistió el
contador de seis sin leer. La copia diagnóstica temporal fue eliminada inmediatamente;
no se copió ni consultó el contenido de SQLite de asistencia.

La verificación contiene 19 checks: 16 PASS y 3 NOT_AVAILABLE (APP_VERSION y STORAGE
en snapshot servidor; CAMERA_AVAILABILITY en cliente). Resultado agregado NOT_AVAILABLE,
no PASS artificial. Los permisos de cámara/GPS y captura de ubicación pasaron; la
disponibilidad física de cámara se demostró por las fotos, no por inferencia del permiso.
No se generó un tercer ticket ni se activó automatización.

Auditoría: ocho eventos support (dos creaciones, dos evidencias, cambio de estado,
asignación, comentario y verificación), siete entradas de timeline de tickets.
Las escrituras web se atribuyen correctamente a Pilot Admin, no a Pilot Support.
El usuario confirmó después consulta de foto/comentario/acciones con Pilot Support,
así como segundo ticket y verificación. RBAC negativo y matriz de roles quedan
respaldados por los tests automatizados, no por mutaciones adicionales en datos reales.
Notificaciones web: Pilot Support marcó dos como leídas y confirmó que el contador
bajó inmediatamente; DB confirma cinco notificaciones de ese usuario, dos leídas.
El feed móvil es independiente: siete novedades (incluye evidencia), una leída.

Las consultas de protección posteriores coinciden exactamente con los cinco hashes
baseline de tablas protegidas. Continúan 19 asistencias, sin modificaciones; SYBI 7
permanece DRAFT con cero geocercas, Devices y assignments. Ambos servicios de red
quedaron habilitados como al inicio.
Preflight final: heartbeat 46 s (<180), red ONLINE, configuración 2/2 y empleados 5/5
SYNCED, pendientes 0, HIGH 0 y MEDIUM 1 informativa. ASISTENCIAS_FORTIA sigue clean.

## Riesgos y decisiones abiertas

- HIGH: ninguno demostrado y sin corregir dentro del alcance probado.
- MEDIUM: Camera7 decodifica original antes de resize; dos capturas HONOR pasaron, sin certificar todos los modelos;
  retención/cuotas/storage productivo y reconciliación de archivos huérfanos requieren operación aprobada.
- LOW: validación completa OpenAPI y benchmark1000 Devices no realizados; warnings de dependencias/build.
- SLA productivo, proveedor push/webhook, scheduler supervisado, S3 privado y proceso de emisión
  de credenciales permanecen decisiones explícitas; no usar defaults DEMO como política productiva.
- La skill design-web-frontends guió reutilización de componentes/tokens, permisos estrictos
  y detalle técnico colapsado, sin rediseñar la dirección visual aprobada.

## Estado protegido

REAL SYBI7: RESERVED. REAL_SYBI_INSIDE_TEST: DEFERRED_SECOND_DEVICE.
PHASE13.5: NOT_STARTED. PHASE14: NOT_STARTED.
ATTENDANCE: UNCHANGED. GEOFENCE VALIDATION: UNCHANGED.
HMAC, Device identity/provisioning, manifests, lifecycle/health y contratos SYBI/Fortia: UNCHANGED.
ASISTENCIAS_FORTIA: clean. NEW REGRESSIONS: 0 automatizadas.
Estado al terminar el gate físico, antes del checkpoint: WORKING TREE not clean,
intencionalmente sin commit/tag. El cierre Git posterior se verifica por sus referencias.

Revisión estática de los 121 archivos candidatos: sin archivos prohibidos, binarios ni
coincidencias de patrones de secretos. Sólo código/config/documentación/tests autorizados.
Los APK/builds, node_modules, DB/logs/capturas y temporales no están entre los candidatos Git.
Esto complementa la revisión de código; no constituye una garantía universal de detección.

Resultado al terminar el gate físico: READY_FOR_PHASE_13_REVIEW; no equivale a producción.

## Revisión final para checkpoint — posterior al gate físico

PHASE 13 CHECKPOINT: BLOCKED:DEMO_PREFLIGHT_NOT_CURRENT al verificar justo antes del commit.
La primera revisión no creó commit/tag/rama porque el HONOR no estaba disponible.
La continuación autorizada obtuvo inicialmente preflight PASS y ADB device, pero el
heartbeat volvió a caducar durante la revisión. El gate físico previo permanece PASS;
no se generaron nuevas operaciones DEMO. La cronología exacta se conserva al final.

### Inventario y seguridad

Se revisó el rango vending-phase-12-ux-pass / da5e6a2d2edc35d7b0fe9fc7d430f62543e55d2a
hasta el árbol de trabajo de phase/13-support-incidents. Los 106 nuevos y 15 modificados
coinciden con el inventario aprobado. La clasificación primaria de cada archivo aparece
en las listas exactas inferiores; las categorías no son dominios de ejecución separados.

| Categoría | Archivos |
| --- | ---: |
| DOMAIN | 15 |
| BACKEND | 21 |
| MIGRATION | 2 |
| MOBILE | 26 |
| WEB | 8 |
| TEST | 21 |
| DOC | 13 |
| CONFIG | 4 |
| SECURITY | 11 |
| UNEXPECTED | 0 |
| TOTAL | 121 |

FILE AUDIT: PASS. SECURITY: PASS para el alcance local revisado.
Escaneo de los 121 candidatos: cero archivos prohibidos/binarios y cero coincidencias
con secretos de configuración no triviales examinados en memoria, sin imprimir valores.
También se revisaron patrones de llaves privadas, credenciales y archivos runtime.
No se incluyen fotos DEMO, SQLite, dumps SQL, logs, APK, keystores, node_modules ni builds.
No se modificó .env. El escaneo no constituye una garantía universal de detección.

### Arquitectura, API y contratos

DOMAIN: PASS. Web, Device y servicio externo usan SupportTicket, SupportTicketService,
SupportAccess, SupportOperations, SupportEvidenceService y el mismo timeline/workflow.
No existen tablas/modelos mobile_tickets, external_tickets o erp_tickets. SQLite móvil
es intención de envío/caché autorizada, no otro dominio canónico.

DEVICE SUPPORT API: PASS; reutiliza firma, nonce y timestamp existentes sin cambios.
EXTERNAL SUPPORT API: PASS en endpoints implementados; integración runtime no configurada.
Principal SupportIntegration independiente con token Sanctum hash-only, expiración,
revocación/rotación por principal estable, rechazo de cookies y tokens humanos, nueve
scopes exactos y allowlist de máquinas. No hereda Device HMAC ni autenticación humana.
external_system deriva de system_key; external_reference es único por integración,
no PK. Receipts y fingerprint canónico rechazan contenido cambiado. Paginación hasta
100 e incremental after_sequence; no se inventa un filtro updated_since inexistente.
Upload externo está denegado explícitamente; lectura/descarga exigen scopes separados.

OpenAPI 3.0.3: 10 paths, 11 operaciones (10 soportadas y una denegada explícitamente),
8 schemas, 69 referencias locales resueltas, IDs únicos y paridad con rutas Laravel PASS.
La revisión es estructural y de contrato/fuente; validación completa de dialecto y cliente
generado siguen PARTIAL. No se instaló tooling ni se emitieron tokens.

WEBHOOKS: DEFERRED_CONFIGURATION. Existe contrato y adapter que no envía ni afirma entrega.
Firma/replay/retry/SSRF y persistencia de delivery real quedan para integración autorizada.

### Evidencias, offline, workflow y auditoría

EVIDENCE SECURITY: PASS. Disco privado Laravel sin serve/URL pública; Data privado Android.
Metadata en DB, no binarios/base64 persistido; MIME declarado/detectado/decodificado,
límites de bytes/píxeles/memoria, hashes origen/sanitizado/miniatura, paths UUID propios,
autorización de ticket/evidencia y revalidación de Device bajo lock. EXIF se elimina
y GPS oficial no deriva de la foto. El historial físico y comprobación de hashes están
registrados arriba; no se tomaron nuevas fotos ni se repitieron reportes en este cierre.

OFFLINE SUPPORT: PASS. Cola separada justificada por outbox existente específico de
asistencia; ticket ACK -> reserva -> upload -> CONFIRMED -> purga canónica local.
Operación estable, nonce nuevo, backoff persistente y dependencias atómicas; reinicio,
reconexión y cola final cero demostrados físicamente. No se releyó SQLite de asistencia.

STATUS/AUDIT: PASS. Transiciones y estados terminales exigidos en backend, resolución
obligatoria, historial de responsable y comentarios aditivos. Guardas de modelo impiden
editar/borrar timeline y evidencia confirmada; no se afirma resistencia frente a un DBA.
Audit conserva referencias/actor sin cuerpos de comentarios, binarios, coordenadas
completas ni credenciales. Los ocho eventos físicos y siete entradas de timeline permanecen.

### Device verification: clasificación exacta

DEVICE VERIFICATION: PASS en ejecución/recepción/persistencia/consulta; cobertura de salud
PARTIAL. Se conserva resumen NOT_AVAILABLE: 16 PASS y los siguientes tres sin información.

| Check | Clasificación | Evidencia real y decisión |
| --- | --- | --- |
| APP_VERSION | CONFIGURATION_PENDING | La app reporta versión; no existe MobileReleasePolicy aplicable a su plataforma/canal PRODUCTION. El evaluador existente devuelve UNKNOWN. No crear política para forzar PASS. |
| STORAGE | PLUGIN_LIMITATION | storage_free_mb es null; el DeviceInfo del plugin Device 7.0.5 instalado no expone espacio libre en disco. No sustituir por RAM ni inventar MB. |
| CAMERA_AVAILABILITY | EXPECTED_LIMITATION | La verificación consulta permiso, no dispara otra foto ni prueba hardware. Se mantiene NOT_AVAILABLE; las dos fotos del gate sí prueban captura física por separado. |

No se demostró un BUG en estos tres resultados. No se añadieron plugins o telemetría.

### Automatización, RBAC y notificaciones

AUTO TICKETS: PASS de implementación/tests; PARTIAL operativa, flag apagado y cero
políticas runtime. ANTI-STORM: PASS, correlación única/locks, reutilización de ticket
abierto, persistencia, cooldown y recuperación explícita. Prueba de 100 señales y
carreras MySQL con seis workers documentadas; no se ejecutó scan runtime ni se generó
ticket por heartbeat exitoso. La recuperación no cierra tickets humanos.

RBAC: PASS. Matriz real de permisos support comprobada en DB, sin ampliarla:

| Rol | Permisos support persistidos |
| --- | --- |
| Pilot Admin | manage (incluye capacidades del módulo por semántica existente) |
| Pilot Operator | view, report, comment, verify; tickets web propios |
| Pilot Support | view, view_all, comment, assign, resolve, verify |
| Pilot Viewer | view, view_all; sólo lectura |

WEB NOTIFICATIONS: PASS físico/técnico. MOBILE IN-APP: PASS físico/técnico.
MOBILE LOCAL: DEFERRED_CONFIGURATION; no plugin/adaptador nativo de notificación instalado.
REMOTE PUSH: DEFERRED_CONFIGURATION; no FCM/APNs configurado.
No se confunde el feed dentro de la app con una notificación del sistema operativo.

SLA: IMPLEMENTED, políticas versionadas/snapshot y DEMO documentada; cero políticas
runtime, sin SLA Medical Life productivo ni scheduler configurado. No se activó ninguno.
PERFORMANCE: PARTIAL. Índices de cola por estado/máquina/responsable, SLA y correlación
revisados; EXPLAIN MySQL previo documenta índices seleccionados y correlation const.
Listados paginados y relaciones eager-loaded, sin carga binaria; autorizaciones por objeto
pueden añadir consultas acotadas. Locks globales de secuencia y coste a 1,000 Devices
requieren medición de carga; no se interpreta EXPLAIN como certificación de capacidad.

Referencias: ADR-VEND-019, support-ticket-domain, support-evidence-security,
support-offline-sync, device-verification, support-notifications y runbooks de políticas
e integración. Los apartados iniciales de subtareas conservan sus fechas/evidencia;
el resultado consolidado físico vigente es el de este informe.

### Validación de cierre y recurrencia del bloqueo operativo

Los SHA-256 de los 121 candidatos se compararon con el inventario revisado: sólo este
informe cambió entre revisiones; no hay archivos inesperados. No se cambió código
ejecutable desde la corrida final aceptada. Se conservan los
resultados Support84, Vending172, frontend93, mobile251, native4 y Laravel632/1FAIL
heredado OnPremDiagnosticsCommandTest. No se repiten tests/builds por cambios sólo
documentales. Pint scoped --test: PASS, 62 PHP. git diff --check: PASS.
NEW REGRESSIONS: 0 demostradas.

Se comprobó nuevamente COUNT(*) de asistencia: 19. La lectura completa para recalcular
su hash fue bloqueada por el control de seguridad y no se ejecutó; no se eludió.
Se conserva como evidencia de contenido la igualdad de hashes del gate físico anterior,
junto con ausencia de cambios en código/protocolos protegidos y ninguna escritura
de asistencia realizada por esta revisión. No se afirma un hash completo recién calculado.
Los conteos actuales mantienen 4 máquinas, 3 geocercas, 6 assignments y 6 registros de
provisioning; se conservaron los dos tickets, dos evidencias y la única verificación.

SYBI 7: RESERVED; source id683, proyección DRAFT, cero geocercas/Devices/assignments.
ASISTENCIAS_FORTIA: clean.
ATTENDANCE/HMAC/MANIFEST/GEOFENCE/LIFECYCLE/HEALTH/SYBI/FORTIA: UNCHANGED en el diff.
No se ejecutaron migraciones, reset, cleanup, instalación APK ni nuevas operaciones DEMO.

Antecedente resuelto: la primera revisión falló por heartbeat 305 s y luego 781 s,
manifests STALE y HIGH1; ADB no encontraba el HONOR. No se creó checkpoint entonces.
El operador restauró conectividad con API local http://192.168.101.15 y confirmó
reachability Android -> PC (2/2 pings, 0% loss). No se modificó esa configuración
durante el cierre. ADB get-state del dispositivo autorizado devuelve device.

Ejecución read-only inicial de vending:demo-preflight en esta sesión: PASS.
Estos valores son evidencia puntual inicial, no el resultado de la última comprobación.

| Control al recuperar inicialmente la conexión | Resultado |
| --- | --- |
| Heartbeat reciente | PASS, 10 s / <180 s |
| Última red reportada | PASS, ONLINE |
| Configuration manifest | PASS, SYNCED, server2 / applied2 |
| Employee manifest | PASS, SYNCED, server5 / applied5 |
| Outbox last reported | PASS, 0 |
| HIGH alerts | PASS, 0 |
| MEDIUM alerts | PASS, 1 informativa, no bloqueante |
| Máquina, geocerca, empleados y assignments DEMO | PASS |

Se volvieron a comprobar las dos evidencias CONFIRMED en disco privado: hashes de
imagen y miniatura coinciden. Permanecen exactamente dos tickets y una verificación,
con 16 PASS y los tres NOT_AVAILABLE clasificados arriba. No se crearon más registros.

La repetición previa al staging también pasó, con heartbeat de 66 s. Se agregaron
explícitamente los 121 archivos inventariados, se mostró git diff --cached --name-status
y se escanearon los blobs staged: cero hallazgos de secretos/archivos prohibidos.
El guard inmediatamente anterior a git commit volvió a ejecutar demo-preflight y falló:
heartbeat 232 s / <180 s. Detuvo el comando antes de invocar git commit.

ADB siguió en device, pero dumpsys power indicó mWakefulness=Dozing. La comprobación
posterior mantuvo el fallo: heartbeat 455 s; red last-reported ONLINE, manifests SYNCED
2/2 y 5/5, outbox0, HIGH0, MEDIUM2. No se cambió ningún umbral ni se falseó telemetría.
Se pidió al operador mantener el HONOR desbloqueado con la app en primer plano,
sin crear nuevos registros. Queda pendiente recuperar heartbeat real y repetir el gate.

CHECKPOINT: BLOCKED:DEMO_PREFLIGHT_NOT_CURRENT.
COMMIT: NOT_CREATED; HEAD permanece da5e6a2d2edc35d7b0fe9fc7d430f62543e55d2a.
TAG vending-phase-13-support-pass: NOT_CREATED.
NEXT BRANCH phase/13-5-geofence-editor: NOT_CREATED.
WORKING TREE: not clean, 121 archivos revisados staged para el checkpoint autorizado.
El commit pendiente es feat(support): add offline vending incident management; sólo con
preflight vigente PASS se podrá crear tag y rama exactamente desde ese tag.
No git add -A, commit, tag, push, configuración de remote ni deploy; tags anteriores preservados.
PHASE13.5/PHASE14: NOT_STARTED.

## Inventario exacto

Ver listas siguientes; no incluyen artefactos ignorados ni capturas fuera del repositorio.


### FILES CREATED (106)

- [BACKEND] `app/Console/Commands/SupportCheckSlaCommand.php`
- [BACKEND] `app/Console/Commands/SupportProcessEventsCommand.php`
- [BACKEND] `app/Console/Commands/SupportScanFleetCommand.php`
- [BACKEND] `app/Contracts/ExternalSupportEventDelivery.php`
- [BACKEND] `app/Contracts/PushNotificationProvider.php`
- [BACKEND] `app/Http/Controllers/Support/SupportEvidenceController.php`
- [BACKEND] `app/Http/Controllers/Support/SupportNotificationController.php`
- [BACKEND] `app/Http/Controllers/Support/SupportPolicyController.php`
- [BACKEND] `app/Http/Controllers/Support/SupportTicketController.php`
- [BACKEND] `app/Http/Controllers/Support/SupportVerificationController.php`
- [BACKEND] `app/Http/Controllers/Support/SupportWebController.php`
- [SECURITY] `app/Http/Middleware/LimitSupportUpload.php`
- [SECURITY] `app/Http/Middleware/SupportContext.php`
- [SECURITY] `app/Http/Middleware/SupportIntegrationAuthentication.php`
- [DOMAIN] `app/Models/SupportCorrelation.php`
- [DOMAIN] `app/Models/SupportEvidence.php`
- [DOMAIN] `app/Models/SupportIntegration.php`
- [DOMAIN] `app/Models/SupportOperation.php`
- [DOMAIN] `app/Models/SupportPolicyVersion.php`
- [DOMAIN] `app/Models/SupportTicket.php`
- [DOMAIN] `app/Models/SupportTicketEvent.php`
- [DOMAIN] `app/Models/SupportVerification.php`
- [BACKEND] `app/Providers/SupportServiceProvider.php`
- [BACKEND] `app/Services/Support/DeferredExternalSupportEventDelivery.php`
- [BACKEND] `app/Services/Support/DeferredPushNotificationProvider.php`
- [SECURITY] `app/Services/Support/SupportAccess.php`
- [SECURITY] `app/Services/Support/SupportActor.php`
- [DOMAIN] `app/Services/Support/SupportAutomationService.php`
- [SECURITY] `app/Services/Support/SupportEvidenceService.php`
- [SECURITY] `app/Services/Support/SupportImageSanitizer.php`
- [SECURITY] `app/Services/Support/SupportIntegrationTokens.php`
- [BACKEND] `app/Services/Support/SupportNotificationService.php`
- [DOMAIN] `app/Services/Support/SupportOperations.php`
- [DOMAIN] `app/Services/Support/SupportPolicyService.php`
- [BACKEND] `app/Services/Support/SupportPresenter.php`
- [BACKEND] `app/Services/Support/SupportQueries.php`
- [DOMAIN] `app/Services/Support/SupportSlaService.php`
- [DOMAIN] `app/Services/Support/SupportTicketService.php`
- [DOMAIN] `app/Services/Support/SupportTimeline.php`
- [DOMAIN] `app/Services/Support/SupportVerificationService.php`
- [CONFIG] `config/support.php`
- [MIGRATION] `database/migrations/2026_09_07_130000_create_support_domain.php`
- [MIGRATION] `database/migrations/2026_09_07_130100_link_support_verifications_and_policy_cursor.php`
- [SECURITY] `database/seeders/SupportPermissionsSeeder.php`
- [DOC] `docs/adr/ADR-VEND-019-support-domain-and-offline-evidence.md`
- [DOC] `docs/api/support.openapi.json`
- [DOC] `docs/architecture/device-verification.md`
- [DOC] `docs/architecture/support-evidence-security.md`
- [DOC] `docs/architecture/support-incident-discovery.md`
- [DOC] `docs/architecture/support-notifications.md`
- [DOC] `docs/architecture/support-offline-sync.md`
- [DOC] `docs/architecture/support-ticket-domain.md`
- [DOC] `docs/operations/device-verification-runbook.md`
- [DOC] `docs/operations/phase-13-support-review.md`
- [DOC] `docs/operations/support-integration-api.md`
- [DOC] `docs/operations/support-policy-runbook.md`
- [DOC] `docs/operations/support-runbook.md`
- [SECURITY] `mobile/src/support/PrivateEvidenceFiles.ts`
- [MOBILE] `mobile/src/support/SqliteSupportStore.ts`
- [MOBILE] `mobile/src/support/SupportApiClient.ts`
- [MOBILE] `mobile/src/support/SupportCaptureService.ts`
- [MOBILE] `mobile/src/support/SupportHomePage.vue`
- [MOBILE] `mobile/src/support/SupportLayout.vue`
- [MOBILE] `mobile/src/support/SupportReportPage.vue`
- [MOBILE] `mobile/src/support/SupportReportsPage.vue`
- [MOBILE] `mobile/src/support/SupportStore.ts`
- [MOBILE] `mobile/src/support/SupportSyncService.ts`
- [MOBILE] `mobile/src/support/SupportTicketPage.vue`
- [MOBILE] `mobile/src/support/SupportTicketSummary.vue`
- [MOBILE] `mobile/src/support/SupportVerificationPage.vue`
- [MOBILE] `mobile/src/support/SupportVerificationService.ts`
- [MOBILE] `mobile/src/support/index.ts`
- [MOBILE] `mobile/src/support/presentation.ts`
- [MOBILE] `mobile/src/support/services.ts`
- [MOBILE] `mobile/src/support/support.css`
- [MOBILE] `mobile/src/support/types.ts`
- [MOBILE] `mobile/src/support/useSupport.ts`
- [TEST] `mobile/tests/unit/support-api.spec.ts`
- [TEST] `mobile/tests/unit/support-capture.spec.ts`
- [TEST] `mobile/tests/unit/support-files.spec.ts`
- [TEST] `mobile/tests/unit/support-fixtures.ts`
- [TEST] `mobile/tests/unit/support-native-policy.spec.ts`
- [TEST] `mobile/tests/unit/support-store.spec.ts`
- [TEST] `mobile/tests/unit/support-sync.spec.ts`
- [TEST] `mobile/tests/unit/support-ui.spec.ts`
- [TEST] `mobile/tests/unit/support-verification.spec.ts`
- [WEB] `resources/js/Components/SupportNotificationFeed.vue`
- [WEB] `resources/js/Components/SupportSummary.vue`
- [WEB] `resources/js/Pages/Support/Index.vue`
- [WEB] `resources/js/Pages/Support/Show.vue`
- [WEB] `resources/js/Pages/Support/Verifications.vue`
- [WEB] `resources/js/presentation/support.js`
- [BACKEND] `routes/support-api.php`
- [BACKEND] `routes/support-web.php`
- [TEST] `tests/Feature/Support/SupportAutomationTest.php`
- [TEST] `tests/Feature/Support/SupportDeviceWriteContextTest.php`
- [TEST] `tests/Feature/Support/SupportDomainTest.php`
- [TEST] `tests/Feature/Support/SupportEvidenceTest.php`
- [TEST] `tests/Feature/Support/SupportIntegrationTest.php`
- [TEST] `tests/Feature/Support/SupportNotificationsTest.php`
- [TEST] `tests/Feature/Support/SupportSecurityRegressionTest.php`
- [TEST] `tests/Feature/Support/SupportSlaTest.php`
- [TEST] `tests/Feature/Support/SupportVerificationTest.php`
- [TEST] `tests/Feature/Support/SupportWebTest.php`
- [TEST] `tests/Frontend/support.test.js`
- [TEST] `tests/Support/support_mysql_concurrency.php`

### FILES MODIFIED (15)

- [CONFIG] `bootstrap/providers.php`
- [CONFIG] `config/filesystems.php`
- [CONFIG] `config/permissions.php`
- [MOBILE] `mobile/android/app/capacitor.build.gradle`
- [SECURITY] `mobile/android/app/src/main/res/xml/file_paths.xml`
- [MOBILE] `mobile/android/capacitor.settings.gradle`
- [MOBILE] `mobile/package-lock.json`
- [MOBILE] `mobile/package.json`
- [MOBILE] `mobile/src/main.ts`
- [MOBILE] `mobile/src/router/index.ts`
- [MOBILE] `mobile/src/views/HomePage.vue`
- [WEB] `resources/js/Pages/VendingFleet/Dashboard.vue`
- [WEB] `resources/js/presentation/navigation.js`
- [BACKEND] `routes/api.php`
- [BACKEND] `routes/web.php`
