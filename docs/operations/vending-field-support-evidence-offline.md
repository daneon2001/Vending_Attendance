# Phase 13.6E — notas, evidencias y operación offline

Estado: PARTIAL. Flujo físico nota/foto offline, restart, recepción, web, COMPLETE
y outbox final cero validados. Notificaciones de actividades sin ticket y textos
UX acotados permanecen pendientes (véase cierre).
Sin commit, tag, push ni deploy. La actividad 1 COMPLETED se conserva.

## Reutilización y aislamiento

- `SupportActivityEvidence` extiende `SupportEvidence`: misma inmutabilidad de
  evidencia confirmada y metadata privada, tabla específica porque la original
  exige ticket y actor terminal. No se crean tickets para almacenar archivos.
- `SupportImageSanitizer` existente: verifica fileinfo/GD, dimensiones, píxeles,
  orientación, rechaza animación y recodifica sin EXIF. Miniatura del mismo flujo.
- `SupportEvidenceService::streamAuthorizedEvidence`: streaming ya existente,
  SHA-256 comprobado antes de emitir bytes, no-store, nosniff y CSP sandbox.
  El controlador de actividades autoriza el objeto antes de usar esta primitiva.
- Android reutiliza Camera, opciones de captura, `PrivateEvidenceFiles`, SHA-256
  y la conexión/mutex/tabla `support_operations` de `SqliteSupportStore`.
  Las entradas FIELD usan un ámbito propio por origen HTTPS y FIELD_MOBILE.
  No usa la cola ni la base de asistencia ni credenciales HMAC de terminal.
- Tablas locales adicionales: contexto, caché de actividades y borradores de
  captura; instalación aditiva, sin borrar o reemplazar bases existentes.
- El contexto offline está ligado al hash de la sesión humana vigente. No se
  almacena el token en SQLite. Logout, otra sesión o expiración bloquean acceso
  offline hasta volver a confirmar ownership online.

## Persistencia y confirmaciones

Operaciones: SUPPORT_NOTE_CREATE, SUPPORT_EVIDENCE_UPLOAD,
SUPPORT_ACTIVITY_COMPLETE. Cada intención tiene UUID persistente antes de mostrar
guardado local. Sus dependencias impiden completar antes de confirmar las notas
y fotos anteriores. Reutilizan claim, backoff y recuperación de SENDING al abrir
la base. Cada reintento solicita un challenge nuevo y firma con la key existente;
no persiste firmas ni challenges para reutilizarlos offline.

START sigue online con GPS nuevo y geocerca EDGE/SERVER. COMPLETE conserva
START_ONLY_V1: sólo registra intención offline, y nunca muestra COMPLETED antes
del receipt real. COMPLETE_LOCATION_REQUIRED_V2 sigue siendo candidato futuro.

Las fotografías pasan por vista previa y confirmación antes de entrar a outbox.
Existe copia privada temporal para preview/recuperación. Descartar retira el
borrador del flujo y no envía la foto; actualmente conserva el archivo privado
huérfano, sin limpieza automática. No se purgan fotos pendientes ni confirmadas
en este gate: una política de retención/limpieza posterior debe ser explícita.

El receipt de foto confirma UUID, tipo, hash original y hash sanitizado. Ambos
hashes pueden diferir por la eliminación de EXIF/recodificación: no se confunden.
Base64 sólo cruza transitoriamente el bridge HTTP nativo; nunca DB ni auditoría.
Un fallo incierto entre filesystem y commit puede dejar un objeto privado
huérfano de intento único; se conserva antes que borrar evidencia de un commit
incierto. No se publica ni aparece en el detalle sin metadata confirmada.

## Límites y formatos

Se reutilizan `support.evidence`: 5 evidencias, 5 MiB por archivo, 12 millones de
píxeles, dimensión máxima 6000, miniatura 320. JPEG/PNG/WebP raster únicamente.
PHOTO y DOCUMENT están tipados; DOCUMENT admite esos formatos raster, no PDF,
Office ni HTML. La UX física de esta fase captura PHOTO JPEG; no ofrece importador
de documentos ni audio/video. Sin nueva dependencia.

Notas: `support.activity_notes.max_length=4000`, alineado al máximo de descripción
de actividad, y `max_count=100` para acotar detalle/cola por actividad. Texto plano,
sin HTML. La UI escapa siempre el contenido. Captured_at se verifica contra el
inicio confirmado y no puede estar más de 30 s en el futuro; received_at es servidor.
Los eventos note_added/evidence_added se proyectan de filas inmutables, evitando
alterar el UNIQUE(activity_id,kind) de transiciones existentes.

## Notificaciones

PARTIAL: si hay ticket relacionado, se agrega un evento de relación sin duplicar
el archivo y se reutiliza la proyección in-app existente. No se cierra el ticket.
Actividades independientes aún no generan notificación in-app propia; no se
configuró push remoto. Su detalle y timeline sí muestran las contribuciones.

## Migración

`2026_09_10_190000_create_support_activity_contributions.php` crea únicamente
support_activity_notes y support_activity_evidence. Ocho FK RESTRICT, UUID únicos
y dos índices compuestos por actividad/id. Sin ALTER sobre tablas anteriores,
backfill, UPDATE ni DELETE de negocio. Down sólo acepta tablas vacías.

Autorización recibida para aplicar únicamente en vending_attendance_dev después
de backup y pruebas. Aplicada: batch 12, delta de migraciones 1, ambas tablas vacías
y ocho FK RESTRICT comprobadas en information_schema. No cambia filas existentes.
MySQL aislado PASS: tipos FK reales, creación, índices, unicidad, rechazo de FK
inválida y borrado del padre, rollback vacío y rechazo con historia. El esquema
sintético creado para la prueba fue eliminado; ninguna escritura en la DB real.

## Hallazgo previo al gate físico — 10 septiembre 2026

attendance_logs=0, vending_attendance_events=21, support activities=1.
Las primeras 19 filas conservan SHA-256
`ce0a8d9f5d9df41ba38a8cc8ad468f3c3e1d2a26a51d12d0262e3cdf6d642efb`.

Eventos adicionales en VM-DEMO-001:

| ID | Tipo | Empleado DEMO | Captura CDMX | Recepción CDMX |
|---|---|---|---|---|
| 20 | Entrada | 990001001 | 10/09/2026 11:00:21 | 11:00:27 |
| 21 | Entrada | 990001004 | 10/09/2026 11:00:46 | 11:00:50 |

Ambos STORED/AUTHORIZED, biometría NOT_USED. El usuario confirmó expresamente que
fueron pruebas manuales autorizadas y autorizó adoptar 21 como baseline protegido.
No se eliminaron ni modificaron.
Actividad 1 y sus cuatro eventos conservan fingerprints anteriores.
SYBI 7 DRAFT, sin geocerca/Device, assignment 7 intacto. Fortia limpio.

Backup completo SQL privado, ACL sólo cuenta ejecutora y SYSTEM, ignorado por Git:
`storage/framework/local-mysql/phase-13-6e/before-20260910T173232Z-f4c4cef2.sql`.
4,233,555 bytes; SHA-256
`9b720cb028d33d7b482efa368c1fd2d0aa76b6ad0b4c1f1e15c3af335c0a98ea`.
Exit 0 y marcador de finalización comprobados; no se efectuó restore del backup.
No compartir el dump: contiene información de la base local.

APK DEBUG instalada con adb install -r, SHA-256
`5141c028812cb1f9a19c0b3646f32466e58ab53a84250cf4faf3758409310576`.
Sin uninstall, clear, reprovisionamiento ni limpieza de SQLite/Keystore.
Actividad 2 creada por workflow normal con actor Pilot Support/Employee 5,
VM-DEMO-001, MAINTENANCE, sin ticket, título «Mantenimiento DEMO offline con evidencias».
UUID `2ce1c0b5-bd68-49d7-b5f4-1ecb15fce786`, estado ASSIGNED. No iniciada.

## Bloqueo físico de conectividad

Preflight previo PASS (manifests 4/4 y 6/6; outbox 0; HIGH 0).
HONOR por ADB operativo. Ping Android→PC: 2/2, sin pérdida.
Listener 192.168.101.15:8443 escuchando. Desde PC: TLS 1.3, certificado/CA/SAN
verificados correctamente por OpenSSL sin desactivar validación.
Desde la APK DEBUG, GET público /up sin credenciales agota 10 s al conectar desde
192.168.101.55 hacia 192.168.101.15:8443. No llega a handshake ni respuesta HTTP.
Windows Wi-Fi figura Public. Inspección de reglas de puerto: Access denied.
Firewall/perfil es hipótesis, no causa demostrada. No se cambió firewall ni TLS.
Se requiere revisión con privilegios administrativos antes de continuar físico.
Observador temporal y forward retirados; forward/reverse vacíos. No se cortó red.
Notas/evidencias reales 0/0; actividades 2 (la anterior COMPLETED y nueva ASSIGNED).

Se detectó además FcgidMaxRequestLen=1 MiB en el listener dedicado, incompatible
con anunciar hasta 5 MiB en JSON nativo. Fuente ajustada a 7,056,044 bytes (base64
de 5 MiB + 64 KiB). Apache -t PASS. Sólo tamaño de petición: TLS intacto.
No se reinició el listener; aplicación de este límite pendiente junto con
restablecer acceso físico. No afirmar carga de 5 MiB validada en el listener activo.

## Gate físico pendiente

Backup, migración, instalación y creación de actividad 2 ya realizados. No repetir
esas operaciones ni crear otra actividad. Resolver primero la conectividad HTTPS
del HONOR; después continuar únicamente con la actividad 2 existente.

IN_PROGRESS → Wi-Fi/datos móviles OFF → nota/foto neutra → pendientes →
force-stop/reabrir → persistencia → red ON → confirmación automática → outbox 0 →
COMPLETE → web/timeline. Sin adb forward/reverse durante pérdida de red. Verificar
inaccesibilidad real del servidor, no sólo icono de red. Detener captura si hay
personas/documentos/pantallas sensibles. Review web/Android externa obligatoria.

No Face ID, modelos, liveness, nuevas asistencias ni reprovisionamiento. Mantener
FIELD_MOBILE existente y PHONE LOCAL_SIMULATED / phoneVerified=false.

## Validación técnica y cierre parcial

- Support + FieldIdentity + Vending: 396 pruebas PASS, 3546 assertions.
- Regresión final FieldSupportActivity/Evidence/Notifications: 44 PASS, 393 assertions.
- Mobile: 299 PASS, 34 archivos; incluye 8 pruebas nuevas de cola, fotos y SQLite
  aislado persistido/cerrado/reabierto. No equivalen al force-stop físico del HONOR.
- Frontend: 129 PASS. Prueba dirigida final de actividades: 12 PASS.
- Android native: 7 PASS. Build web/mobile, cap sync y assembleDebug PASS.
- Pint scoped: 13 archivos PASS. git diff --check PASS.
- Backup y APK ignorados por Git. No commit, tag, push ni deploy.

Lectura final: employees 2507, users 5, assignments 8, attendance_logs 0,
vending_attendance_events 21 (baseline autorizado intacto), tickets 2,
activities 2, notes 0, evidence 0, FIELD_MOBILE único ACTIVE. Actividad 1 y sus
cuatro eventos conservan hashes previos; actividad 2 ASSIGNED. SYBI 7 y assignment
7 conservados, geocercas y RBAC sin cambios. ASISTENCIAS_FORTIA limpio.

Pendiente UX observado: si no hay actividades descargadas y no existe red, el
mensaje vacío debe distinguir ausencia de caché de ausencia de asignaciones.
No certificar visualmente ese estado como PASS. Notificaciones de actividades
sin ticket siguen PARTIAL. El bloqueo de red no convierte esos pendientes en PASS.

FINAL: BLOCKED:HONOR_HTTPS_8443_UNREACHABLE. La revisión de reglas Windows requiere
privilegios administrativos; no se autoriza implícitamente abrir puertos, cambiar
el perfil de red, desactivar firewall ni modificar confianza TLS.

## Actualización física y corrección FastCGI — 10 septiembre 2026

Esta actualización sustituye el bloqueo de conectividad anterior; Fase 13.6E
sigue PARTIAL hasta terminar las comprobaciones web y COMPLETE.

- La regla existente de Apache ya permitía TCP para su ejecutable en perfil
  Público. No se agregó ninguna regla ni se modificó firewall/TLS.
- El operador cargó actividad 2 en Android e inició: IN_PROGRESS, Dentro,
  inicio confirmado 12:08:22 CDMX. No se creó otra actividad.
- El operador confirmó Wi-Fi/datos apagados, nota/foto con 2 pendientes y
  force-stop/reapertura: nota, foto, 2 pendientes y estado persistentes.
  ADB forward/reverse comprobados vacíos antes de esa prueba.
- Al reconectar llegó 1 nota; la foto quedó pendiente. El servidor registró
  `mod_fcgid: write timeout to pipe`. Reiniciar/cargar el límite máximo de
  peticiones no resolvió el fallo.

### Causa y prueba aislada

Fixture PHP sin Laravel/DB, HTTPS sólo loopback 127.0.0.1:8444. Se reprodujo con
PHP 8.4 y también 8.3 sin php.ini: 1,049 bytes correctos; 16,409 bytes podían
devolver 200 con cuerpo descartado; 65,561/131,097 bytes causaban timeout/500.
La respuesta del fixture mostró `Unable to create temporary file` y
`POST data can't be buffered; all data discarded`. Directorio temporal efectivo
C:\Windows, no escribible. El entorno mínimo FastCGI no suministraba TEMP/TMP.
Cambiar FcgidMaxRequestInMem a un valor grande o a cero no corrigió el problema;
esos experimentos NO se aplicaron al listener DEMO.

Corrección mínima en tools/local-field-https/httpd.conf: FcgidInitialEnv TEMP
y TMP apuntan a storage/framework/local-https/php-tmp. prepare.ps1 crea ese
directorio después de establecer el ACL privado del padre. Sin cambios en
php.ini, TEMP/TMP globales, protocolos/cifrados/CA/SAN, API, APK, firma u outbox.
ACL heredado: sólo cuenta ejecutora y SYSTEM; ignorado por Git, fuera de public.
No se aumentaron timeouts ni se habilitó acceso a C:\Windows.

Backup privado de configuración: storage/framework/local-https/
httpd-before-temp-fix-20260910.conf, SHA256
44010a23ee3aa7137b0b13291e4932651a52a1b9de2c4c939586b12555df7d5f.
Se reinició sólo DEMO (PID 47820). Apache HTTP/443 conservó PID 12088.

### Validaciones de la corrección

- Prueba A/B y repeticiones: 1,049; 16,409; 65,561; 131,097; 1,048,601;
  7,056,025 bytes: 200, tamaño íntegro y SHA256 coincidente en fixture.
- Por encima del límite (7,056,069 bytes) Apache rechazó con 500; no afirmar
  413 para ese rechazo upstream. Límite y validaciones de aplicación intactos.
- tests/Support/local_https_transport_probe.php --run --isolated: 6 PASS
  con integridad. Requiere levantar expresamente el fixture privado de prueba;
  no lo inicia por sí solo. El listener de prueba quedó detenido al finalizar.
- php tests/Support/local_https_transport_probe.php --run: 6 PASS de transporte
  /up en DEMO, GET sintético sin credenciales/operación de negocio. /up no
  devuelve hash; estas seis pruebas no certifican integridad de un archivo.
- TLS validado con CA y peer_name explícito, sin trust-all ni verificación omitida.
- Apache syntax, PHP lint, Pint scoped del probe y git diff --check PASS.
  No se repitieron builds/suites de aplicación porque esta corrección sólo
  cambia el entorno del listener y herramientas de diagnóstico.

### Recepción física posterior

Después de corregir el listener se recibió la misma foto pendiente: actividad 2
con 1 nota y 1 evidencia CONFIRMED (18:47:30 UTC). Archivo privado de 219,475 bytes,
SHA256 almacenado/archivo coincidente, hash original de carga presente.
No se solicitó otra foto ni se modificó la cola local manualmente.
Actividad 2 sigue IN_PROGRESS; attendance_logs=0, vending_attendance_events=21.
Falta confirmación visual de pendientes 0 en HONOR, revisión web de nota/foto y
finalización controlada. No declarar cierre de Fase 13.6E todavía.

Referencia: https://httpd.apache.org/mod_fcgid/en/mod/mod_fcgid.html#fcgidinitialenv
describe el entorno específico de procesos FastCGI; causa demostrada mediante
el fixture local, no inferida únicamente de la documentación.

## Cierre solicitado — evidencia y límites, 10 septiembre 2026

Alcance exclusivo 13.6E. Sin revisión visual global, branding, beta, APK para
compañeros ni Phase 14. No nuevos registros generados durante estas consultas.

Activity 2 COMPLETED. Backend contiene una transición completed a 18:58:09 UTC
(12:58:09 CDMX), coherente con captura Android y confirmación externa web.
El operador confirma nota/foto neutra accesibles, Técnico Demo, VM-DEMO-001,
fechas de captura/recepción y ausencia de duplicados visibles. Timeline externo
mostró creación, asignación, inicio, nota y evidencia; backend confirma cierre.

| Registro único | Capturado UTC | Recibido UTC |
| --- | --- | --- |
| Nota | 18:12:06 | 18:22:56 |
| Foto CONFIRMED | 18:12:11 | 18:47:30 |
| COMPLETE | No exige GPS final | 18:58:09 |

Una nota, una evidencia, dos archivos físicos (imagen y miniatura), cero claves
principal/operation_uuid duplicadas. Receipts únicos de creación, START, nota,
evidencia y COMPLETE. Hash original del receipt coincide con upload_sha256;
hash sanitizado del receipt coincide con metadata y archivo privado. No exigir
igualdad original/sanitizado: la recodificación elimina EXIF. Archivo 219,475 bytes.
Existieron reintentos de transporte fallidos; no son duplicados de negocio.
No se reprodujeron mutaciones para probar otra vez idempotencia: pruebas
automatizadas negativas y receipts persistidos aportan esa evidencia.

Disco support_private: fuera de public, visibility configurada private, serve=false,
descarga con autorización por objeto y tests de IDOR/integridad PASS. En Windows,
getVisibility() derivada de bits POSIX devuelve public; no equivale a URL pública.
El ACL NTFS hereda permisos locales de Laragon para usuarios autenticados: no
afirmar almacenamiento con ACL exclusivo ni certificación de hardening productivo.
No se alteraron permisos NTFS, discos ni archivos durante este cierre.

Restart offline de nota/foto y 2 pendientes: confirmado previamente por operador.
Tras corregir FastCGI, receipt de foto llegó sin nuevo reintento manual solicitado;
operador confirmó 0 pendientes e información sincronizada antes de COMPLETE.
Hubo reintentos manuales fallidos anteriores: no presentar todo el recorrido como
libre de intervención. COMPLETE visible confirmado. No se forzó otro restart:
no es necesario repetir la prueba ya aprobada ni generar nuevos challenges.
Captura externa final del HONOR a las 13:34: ambas actividades Completadas,
«Pendientes de trabajo de campo: 0» e información sincronizada y confirmada por
el servidor. OUTBOX FINAL FIELD SUPPORT = 0 / PASS. El contador suma todas las
operaciones FIELD no ACKNOWLEDGED del ámbito actual; incluye nota, evidencia y
COMPLETE. Por tanto sus pendientes son cero en ese ámbito. No representa la cola
independiente de tickets del terminal: las capturas de tickets/notificaciones no
certifican ese contador. No se extrajo SQLite, Secure Storage ni sesión.

FIELD_MOBILE: mismo UUID y fingerprint, único, ACTIVE. Receipts START/COMPLETE
registran phoneVerified=false; phone_verification_method=LOCAL_SIMULATED. Existe
phone_verified_at del OTP DEMO, que NO es prueba de verificación telefónica real.
BIOMETRY NOT_IMPLEMENTED en este flujo. START_ONLY_V1 intacta.

Baseline final leído: attendance_logs=0, vending_attendance_events=21,
support activities=2, empleados=2507, users=5, assignments=8, tickets=2.
El pedido de cierre vuelve a mencionar 19, pero las 21 filas conservan el hash
autorizado 1e39d37782dc3c789a9de3f1bc15561124965d02da8e4278437aa43185734378.
Las primeras 19 también conservan su hash anterior. No modificar/eliminar las
dos entradas manuales autorizadas. Activity 1 y sus cuatro eventos intactos.
SYBI 7 DRAFT/sin geocerca/sin Device, assignment 7 con mismo hash; geocercas,
usuarios, empleados, asignaciones y RBAC con hashes previos. ASISTENCIAS_FORTIA
clean. Ninguna modificación Phase 14 en esta ejecución.

Pruebas finales: Support 145 PASS (1614 assertions), Mobile 299 PASS (34 archivos),
Frontend Support/Activities 34 PASS, Pint --test scoped 14 archivos PASS,
git diff --check PASS. Android native 7 PASS de la build ya validada: no repetido
porque no hubo cambio nativo ni nueva APK. No repetir suite completa/builds.

Notificaciones: no se ejecutó proyector ni se crearon avisos. El mecanismo actual
reutiliza eventos de tickets relacionados; Activity 2 no tiene ticket y no genera
notificación in-app autónoma. Limitación PARTIAL, no confundir con timeline PASS.
Persisten pendientes UX acotados ya documentados (mensaje de caché vacía y texto
web heredado que anuncia ejecución móvil futura). No se corrigieron como parte
de estas comprobaciones de cierre ni se inició auditoría global 13.7.

Estado de cierre: PARTIAL; sin commit, tag, push o deploy. No afirmar todavía
READY_FOR_PHASE_13_7 sin resolver/aceptar los pendientes de alcance indicados.
La confirmación de outbox FIELD ya no es un bloqueo; no volver a solicitarla.

## FINAL CLOSEOUT — tres pendientes resueltos, 10 septiembre 2026

Este apartado sustituye el estado PARTIAL anterior, sin alterar la evidencia
histórica ni iniciar Phase 13.7. PHASE 13.6E FINAL: PASS, alcance DEMO/local y
validación técnica indicada a continuación; no certificación productiva.

### Notificaciones independientes

Auditoría previa: `SupportNotificationService` consumía exclusivamente
`support_ticket_events`, con cursor/recipiente persistidos, UUID determinista,
tabla `notifications`, consulta autorizada y marcador de lectura idempotente.
`SupportTimeline` ejecutaba el proyector acotado después del commit;
`support:process-events` permite recuperación. Se conserva esa infraestructura.
El terminal usa su identidad y novedades de tickets; no es la identidad humana
FIELD_MOBILE ni puede consultar sus avisos.

Se añadió una fuente de eventos al MISMO servicio/proyector y tabla:
`vending_support_activity_events`, tipo `support.activity.event`. Sin nueva
migración, proveedor, push, broadcast ni tickets artificiales. El vínculo con
ticket sigue siendo opcional y no otorga permisos sobre el ticket.

| Evento de actividad | Decisión |
| --- | --- |
| Asignada | Aviso al destinatario y coordinación con acceso vigente, excluido el actor |
| Completada / cancelada | Aviso a participantes y coordinación autorizada, excluido el actor |
| Creada / iniciada | Historial; no aviso adicional redundante |
| Nota / evidencia independiente | Historial; no multiplicar avisos por captura o reintento |
| Nota / evidencia de actividad vinculada | Se conserva la proyección de ticket existente y su autorización |

Candidatos: cuenta vinculada al empleado asignado, creador/asignador y cuentas
con permisos existentes de coordinación/soporte. Cada candidato debe además
pasar el alcance REAL de actividad web o FIELD; `view_all` de tickets no concede
alcance global de actividades. Se excluyen cuentas deshabilitadas y el actor.
Consulta, contador y marcado vuelven a resolver permisos, vínculo, empleado y
asignaciones vigentes. FIELD exige el mismo dispositivo ACTIVE y mantiene la
excepción DEMO exacta exclusivamente local/testing. Resolver global intacto.

Anti-storm: UUID por evento/destinatario + PK de notifications; insertOrIgnore
no restablece read_at. Cursores separados dentro de support_runtime_cursors,
presupuesto total compartido de eventos/recipientes/tiempo y prioridad alternada
entre fuentes. La asignación del ID de evento se serializa con el lock existente
del proyector, para impedir saltos por commits concurrentes fuera de orden.
Sólo se registra el hook de entrega y la coordinación del proyector: transiciones,
GPS, ActorContext, HMAC, recibos de actividad y START_ONLY_V1 no cambian.
Fallo de entrega no revierte la actividad; cursor persistido permite recuperación.

Web: mismo SupportNotificationFeed, contador autorizado, enlace a actividad o
ticket según tipo, sin mostrar códigos técnicos. Móvil: avisos plegados en Mis
actividades, con componentes Ionic existentes. La lista firmada entrega hasta
20 avisos y su contador; el marcado usa challenge/firma existente, es idempotente
y no entra en la outbox ni se presenta como confirmado antes del servidor.
No hay otra base de notificaciones ni mezcla con la identidad del terminal.
Los avisos no se cachean offline; no se muestran contadores inventados.
Timestamps de avisos de actividad salen en ISO UTC y se presentan en CDMX.

No se ejecutó el proyector contra datos reales durante este cierre, ni se crearon
avisos físicos de prueba. La generación, autorización y lectura de los avisos
nuevos se validó en pruebas aisladas; las consultas de feed también ejecutaron
correctamente en MySQL local, sólo lectura. Eventos históricos podrán proyectarse
por el mecanismo existente cuando se ejecute normalmente, sin duplicación.

### Dos ajustes UX, sin revisión global

`FieldActivitiesPage.vue` usa el mensaje «No hay actividades disponibles en este
dispositivo. Conéctate a la red para actualizar tus actividades.» para caché vacía
sin conexión. También se cubre el contexto nunca descargado cuando falla la red;
401/403 siguen mostrando error de sesión/autorización, no una lista vacía falsa.
`Support/Activities/Show.vue` explica ejecución desde móvil autorizado, validación
de ubicación al iniciar y ausencia de nueva ubicación al finalizar. La web no
ofrece START/COMPLETE. La skill design-web-frontends limitó estos cambios a los
componentes y patrones actuales; no se cambiaron branding ni diseño global.

### Validación final y datos

- Support: **152 PASS / 1731 assertions**, SQLite en memoria. Incluye seis nuevas
  pruebas de avisos autónomos y una del transporte firmado/lectura nativa.
- Mobile: **303 PASS / 34 archivos**; caché vacía, sin contexto, sesión denegada,
  avisos firmados y contador confirmado, además de regresiones offline existentes.
- Frontend Support/Activities: **35 PASS**, render de enlaces, texto y permisos.
- Web build y Mobile build (vue-tsc + Vite): **PASS**. Avisos de Browserslist,
  CSS/Ionic/Tailwind y tamaño de chunks no impiden las compilaciones; sin cambios
  de dependencias para silenciarlos.
- Pint scoped: **7 archivos PASS**. git diff --check: **PASS**.
- Sin cambios nativos: no repetir Android native, cap sync, assembleDebug,
  instalación ni E2E físico. Los avisos nuevos todavía no se instalaron en HONOR.
  No se certifica revisión visual nueva de ellos ni Beta Readiness.

Consultas finales sin mutaciones: ambas actividades COMPLETED; Activity 2 tiene
una única transición completed, una nota y una foto CONFIRMED. SHA256 de imagen,
miniatura y recibos coinciden; captura/recepción continúan diferenciadas. Cero
operation_uuid duplicados por principal. Archivo privado de 219,475 bytes.
Los límites de ACL NTFS locales previamente documentados permanecen explícitos.

Baseline final: attendance_logs=0; vending_attendance_events=21; activities=2;
employees=2507; users=5; assignments=8. Hashes protegidos de las 21 asistencias,
actividades/eventos, empleados, usuarios, asignaciones, geocercas y RBAC coinciden
con el inicio del cierre. FIELD_MOBILE único, mismo UUID/fingerprint y ACTIVE;
PHONE LOCAL_SIMULATED y phoneVerified=false en los cuatro recibos de ejecución.
SYBI 7 DRAFT, sin geocerca/Device y assignment 7 con mismo hash. ASISTENCIAS_FORTIA
clean. Phase 14 no modificada. BIOMETRY NOT_IMPLEMENTED; START_ONLY_V1 intacta.
Outbox FIELD=0, reconexión y persistencia: evidencia física externa ya aceptada,
sin repetir capturas ni acceder a SQLite/Secure Storage/Keystore.

Archivos de este cierre: SupportNotificationService, SupportActivityWebQueries,
FieldSupportActivityAccess, SupportActivityService, FieldSupportActivities;
SupportNotificationFeed.vue, presentation/support.js, Activities/Show.vue;
FieldActivityFlow.ts, FieldActivitiesPage.vue y FieldActivityFlow.test.ts;
nuevo tests/Feature/Support/SupportActivityNotificationsTest.php;
FieldSupportActivityTest.php, Frontend/support.test.js y supportActivities.test.js;
este documento. No se modificaron otros archivos de fases anteriores.

FINAL: READY_FOR_PHASE_13_7. No implementada Phase 13.7. Sin commit, tag, push,
deploy, nueva APK distribuible, nuevos registros reales ni cambios de secretos.
