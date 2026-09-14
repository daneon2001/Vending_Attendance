# Phase 13.7.1 — Internal beta release candidate closeout

Fecha: 2026-09-11. Auditoría local, sin operaciones nuevas de negocio.
**PHASE 13.7.1: PASS. BETA RELEASE CANDIDATE: READY (beta interna existente).**

La revisión funcional y visual de los módulos web/APK, resolución, offline,
restart y reconnect fue confirmada por el operador en esta conversación. No se
repitió. La lectura final resolvió el bloqueo provisional de telemetría.

## 1. Alertas: lectura de la proyección real

**Cierre 23:16:51 UTC: CURRENT HIGH ALERTS=0.** El operador confirmó que la APK
quedó cerrada/en segundo plano. La terminal envió heartbeat automáticamente a
las 23:16:37 UTC; ahora está DEGRADED por RECENT_NETWORK: NETWORK_TIMEOUT
registrado 23:09:24 UTC. Las dos alertas finales son DEVICE_DEGRADED MEDIUM
y DEVICE_RETIRED MEDIUM, ambas INFORMATIONAL para esta beta. Clasificación
del timeout: EXPECTED_DEMO_HISTORY en el contexto de cierre/reconexión confirmado;
no implica haber probado su causa exacta de red. La ventana de error dura
30 minutos, hasta 23:39:24 UTC si no aparecen nuevos errores. ONLINE requiere
además heartbeat fresco y los otros criterios; no se forzó ni esperó la expiración.
Si reaparece con uso continuo y LAN estable, reclasificar CURRENT_BETA_ISSUE.

Ficha final de DEVICE_DEGRADED: SOURCE devices → health; ENTITY Device 3 / VM-DEMO-001;
CREATED_AT no persistido (evento fuente 23:09:24 UTC); LAST_SEEN/heartbeat
23:16:37 UTC; CURRENT por ventana de error reciente; INFORMATIONAL; ROOT CAUSE
RECENT_NETWORK por NETWORK_TIMEOUT. No es STALE_PROJECTION: aplica correctamente
la ventana configurada. Device 1 conserva la ficha siguiente.

La tabla siguiente conserva el **hallazgo inicial, ya superado**, como trazabilidad:

Lectura inicial 23:08:28 UTC; ampliación aproximadamente 23:09:37 UTC.
`VendingFleetOperationsService::dashboard()` genera las alertas en memoria con
`alerts()`: no son filas de una tabla de alertas, no tienen fecha de creación,
fecha de reconocimiento ni persistencia propia. No se borraron ni reconocieron.

| Campo | Alerta 1 | Alerta 2 |
| --- | --- | --- |
| TYPE | DEVICE_OFFLINE | DEVICE_RETIRED |
| SEVERITY | HIGH | MEDIUM |
| SOURCE | devices → DeviceFleetHealthService → VendingFleetOperationsService | mismo servicio, estado de ciclo de vida |
| ENTITY | Device 3, VM-DEMO-001, UUID 80e9e97e-5f0a-4d13-9a1a-5328a1b714c1 | Device 1, VM-DEMO-001, UUID 30000000-0000-4000-8000-000000000001 |
| CREATED_AT | No persistido; device creado 2026-09-05 15:31:45 UTC, no fecha de alerta | No persistido; device creado 2026-09-04 19:49:44 UTC, no fecha de retiro |
| LAST_SEEN | last_seen_at 2026-09-11 23:09:35 UTC; heartbeat 21:15:57 UTC | last_seen_at/heartbeat 2026-09-04 19:48:01 UTC |
| CURRENT / STALE | CURRENT como condición evaluada; heartbeat antiguo | CURRENT como ciclo de vida, historial DEMO |
| BLOCKING / INFORMATIONAL | BLOCKING para cerrar: falta explicar por qué cesó el heartbeat | INFORMATIONAL |
| ROOT CAUSE | HEARTBEAT_EXPIRED: edad 6,820 s > umbral 600 s; causa del cese aún UNKNOWN | LIFECYCLE_RETIRED, terminal DEMO sustituida |
| Clasificación | UNKNOWN; no afirmar EXPECTED_DEMO_HISTORY sin evidencia | EXPECTED_DEMO_HISTORY |

**Al inicio hubo 1 HIGH, aún no explicada en aquella lectura.** `network_state=ONLINE`
es el último reporte almacenado. `last_seen_at` se actualiza en
`VerifyDeviceHmac` con solicitudes autenticadas; no acredita un heartbeat válido.
ADB reportó MainActivity como `topResumedActivity` durante esta auditoría. Hay
contacto reciente sin actualización del heartbeat: no basta atribuirlo a que el
operador desconectó el cable o a que el teléfono está offline.

Device 3 tiene `last_error_category=ATTENDANCE`, `last_error_code=UNKNOWN`,
fecha 21:06:23 UTC. Ya está fuera de la ventana de 30 minutos. `classifyError()`
usa ATTENDANCE como fallback para errores no tipificados: no prueba una nueva
asistencia fallida. NETWORK_TIMEOUT no es el código actualmente almacenado.
Los manifests del Device 3 están SYNCED (configuración 4/4, empleados 6/6).
La versión sin política publicada aparece UNKNOWN y no origina esta HIGH.
Soporte no origina ninguna de estas dos alertas.

`EdgeSyncService` agenda heartbeats y reintentos; una sincronización completa
puede fallar antes de enviarlos. Es una hipótesis a diagnosticar, no causa probada.
No se disparó sync, heartbeat, challenge ni flujo de negocio para ocultar la alerta.
La captura entregada no contiene el detalle de alertas: no permite reconstruir
con certeza cuáles eran sus tipos en el instante de la imagen.

## 2. Connected devices KPI

Traza: `routes/web.php` → `VendingFleetDashboardController` →
`VendingFleetOperationsService::dashboard()` → `Device` / tabla `devices`,
`whereNotNull(vending_machine_id)` → `DeviceFleetHealthService::evaluate()` →
conteo `health.status=ONLINE` → `resources/js/Pages/VendingFleet/Dashboard.vue`,
tarjeta `devices_online`, etiqueta «Dispositivos conectados».

- CONNECTED KPI DEFINITION: terminales vinculadas a máquina, lifecycle ACTIVE,
  heartbeat con edad menor de 180 s y ninguna causa de degradación.
- FIELD_MOBILE INCLUDED: **NO**, vive en `employee_devices`.
- VENDING_TERMINAL INCLUDED: **YES**, por asociación con máquina; no existe en
  esta query un filtro textual de tipo llamado VENDING_TERMINAL.
- HEARTBEAT WINDOW: normal <180 s; DEGRADED desde 180 hasta <600 s;
  OFFLINE sin heartbeat o desde 600 s. Intervalo solicitado por defecto 60 s,
  con jitter móvil. No es garantía de ejecución Android en segundo plano.
- Otros degradantes: drift >300 s, almacenamiento <256 MB, cola >=100,
  manifest PENDING/ERROR/STALE, versión requerida/no soportada, error reciente <=30 min.
- EXPECTED VALUE: **0**. ACTUAL VALUE: **0**. KPI CORRECT: **YES**.
- CONNECTED DEVICES KPI: **AMBIGUOUS** en etiqueta, correcto en cálculo.
- Inventario final: Device 1 RETIRED, Device 2 PENDING sin heartbeat, Device 3 ACTIVE/DEGRADED por RECENT_NETWORK. Al inicio era ACTIVE/OFFLINE.

ACTIVE significa habilitación, ONLINE salud calculada, RECENT HEARTBEAT recepción
temporal y CONNECTED aquí es el alias de ONLINE. No son equivalentes.
Propuesta sólo documental: «Terminales con conexión y salud operativa» y ayuda
«Terminales activas con heartbeat menor de 3 minutos y sin incidencias; no incluye
dispositivos personales». No se modificó etiqueta ni lógica.

## 3. Field mobile health

- Mismo EmployeeDevice 1 / UUID a7807121-1079-4223-9fff-abe34043be6a, **ACTIVE**.
- User 4 Pilot Support activo (estatus 1), vinculado a Employee 5 Técnico Demo,
  empleado activo (A); `active_employee_id=5` coincide.
- Activado/verificado 2026-09-10 15:35:29 UTC. Sin revoked_at ni ended_at.
- last_seen_at: 2026-09-11 17:15:09 UTC. Clave pública parseable y fingerprint
  SHA256 coincide con la clave registrada, key_version=1; no se exportó material.
- 52 challenges existentes, último creado/consumido 17:15:09 UTC; 1 registro OTP.
  El acta anterior tenía 46 challenges: evidencia posterior a aquella revisión,
  no generada por esta auditoría. No hay nueva prueba criptográfica ejecutada aquí.
- `app_version=1.0` en binding es metadata del registro original; la APK instalada
  se comprobó por separado como 1.0.1-beta.1 / 2.
- PHONE=LOCAL_SIMULATED; phoneVerified=false, incluso con fecha de OTP simulado.
- Web y diagnóstico traducen ACTIVE como «Activo», no ONLINE. La red de la app
  es un indicador separado y no prueba disponibilidad del servidor.

## 4. Inventario de versión y fuente

| Elemento | Valor |
| --- | --- |
| Git branch | phase/14-biometric-engine-selection |
| HEAD | 702b641ef803793d025903435b81a26fc1f48a0c |
| Working tree | DIRTY, modificaciones y archivos no rastreados de fases anteriores; 128 entradas antes de esta documentación |
| Web | sin versión propia en package.json; presenta metadata candidata compartida |
| Mobile producto / Android versionName | 1.0.1-beta.1 |
| Android versionCode | 2 |
| Mobile package.json | 0.0.1 (paquete de herramientas, no versión instalada) |
| Capacitor core/android/cli | 7.6.9 |
| Laravel instalado | 11.47.0 |
| Vue web instalado | 3.5.25 |
| Vue mobile instalado | 3.5.42 |
| PHP CLI | 8.4.15 |
| Android SDK min / target / compile | 23 / 35 / 35 |
| Gradle wrapper | 8.11.1 |

HEAD solo **no** reproduce la candidata: requiere el worktree no confirmado.
Se preservó un snapshot de 1,275 archivos versionables y manifest de SHA256,
fuera de Git. El snapshot es anterior a este cierre documental e incluye Phase 14.
No se cambiaron dependencias, fuente de aplicación ni configuración.

## 5. Evidencia de pruebas consolidada

Evidencia histórica: [validación 13.7](internal-beta-validation.md),
[prueba móvil](../operations/vending-field-support-mobile-demo.md),
[offline y evidencias](../operations/vending-field-support-evidence-offline.md).
No se presentan estas suites como ejecutadas otra vez ni como prueba binaria de
la recompilación posterior. XML nativo conservado: 9 tests, 0 failures/errors.

| Área | Tipo | Evidencia / límite |
| --- | --- | --- |
| Attendance | AUTOMATED + PHYSICAL | suites Vending; pruebas DEMO previas; 22 eventos preservados |
| GPS | AUTOMATED + PHYSICAL | pruebas móviles previas y confirmación global del operador |
| Geofence | AUTOMATED + PHYSICAL | START de actividades con versión 2; START_ONLY_V1 |
| Field Device Identity | AUTOMATED + PHYSICAL | registro/firma histórica, binding actual válido |
| HTTPS | AUTOMATED + PHYSICAL | LAN/CA, native trust tests y pruebas móviles previas |
| Support | AUTOMATED + PHYSICAL + VISUAL | suites Support y revisión de módulos reportada |
| Activities | AUTOMATED + PHYSICAL + VISUAL | dos COMPLETED, captura actual |
| Notes | AUTOMATED + PHYSICAL | una nota persistida, prueba histórica |
| Photos | AUTOMATED + PHYSICAL | evidencia persistida y archivos privados preservados |
| Offline | AUTOMATED + PHYSICAL | historial y confirmación explícita del operador |
| Restart | AUTOMATED + PHYSICAL | historial y cierre/reapertura confirmados |
| Reconnect | AUTOMATED + PHYSICAL | historial y desconexión/reconexión confirmadas |
| Idempotency | AUTOMATED + PHYSICAL | historial de reintentos sin duplicados, baseline actual |
| Web | AUTOMATED + VISUAL | 132 frontend PASS documentados, build PASS; revisión confirmada |
| Mobile | AUTOMATED + PHYSICAL + VISUAL | 317 PASS documentados, 9 nativos, build/sync/assemble PASS históricos |
| Segundo Android | NOT_TESTED | no instalación ni enrolamiento adicional |
| Carga 1,000 equipos | NOT_TESTED | no certificada |

Laravel acotado: **414 PASS / 3,776 assertions** documentados. No suites completas
repetidas. Sin cambios de UX/código, no corresponde nuevo build.

VISUAL REVIEW: **PASS para el alcance confirmado por el operador**. Capturas
revisadas directamente: Mis actividades (ambas completadas, 0 pendientes) y
Resumen de operación (3 operativas, 0 conectados, 4 empleados, 2 alertas).
Las demás pantallas/resoluciones se sustentan en su confirmación, no en una
inspección independiente nueva. No se inventa evidencia por resolución/rol ni
certificación WCAG. Las casillas históricas vacías no se convierten en ejecución nueva.

## 6 y 14. Limitaciones y readiness

| Limitación | Beta | Piloto / producción |
| --- | --- | --- |
| LOCAL_SIMULATED / phoneVerified=false | BETA_ACCEPTABLE en identidad DEMO existente | PILOT_BLOCKER: fuente y estrategia individual pendientes |
| BIOMETRY=NOT_IMPLEMENTED | BETA_ACCEPTABLE sin promesa biométrica | PILOT_BLOCKER si negocio la exige; PRODUCTION_BLOCKER bajo ese requisito |
| HTTPS LAN + DEMO CA | BETA_ACCEPTABLE supervisada | PILOT_BLOCKER para piloto real; confianza productiva pendiente |
| DEBUG APK y firma debug | BETA_ACCEPTABLE interna | PRODUCTION_BLOCKER, necesita release/firma/distribución aprobadas |
| COMPLETE LOCATION=START_ONLY_V1 | BETA_ACCEPTABLE explícita | REVIEW antes de piloto; blocker si se exige ubicación al completar |
| REMOTE PUSH diferido | BETA_ACCEPTABLE, lectura/polling | PRODUCTION_BLOCKER si se promete entrega remota/background |
| PERFORMANCE 1,000 no certificada | BETA_ACCEPTABLE a baja escala | PRODUCTION_BLOCKER para esa escala |
| Datos DEMO mezclados con importación autorizada | BETA_ACCEPTABLE controlada | PILOT_BLOCKER hasta estrategia de separación/retención |
| Heartbeat vencido inicial, recuperado espontáneamente; timeout reciente | BETA_ACCEPTABLE en cierre/reconexión DEMO | REVIEW si recurrente con LAN estable; sin HIGH al cierre |
| Recuperación de secretos/config TLS fuera del snapshot | requiere custodia existente | no certificar recuperación en host limpio sin ella |

INTERNAL BETA: **READY para checkpoint de la candidata existente**, con limitaciones explícitas. REAL PILOT: **NOT_READY**. PRODUCTION: **NOT_APPROVED**.

## 7. Datos DEMO y corporativos, sin cleanup

| Datos | Inventario | Clasificación / acción futura |
| --- | --- | --- |
| VM-DEMO-001/002/003 | IDs 1/2/3; 002/003 source LOCAL, siguen siendo DEMO por contexto | KEEP_FOR_INTERNAL_BETA; REMOVE_BEFORE_PILOT del entorno real |
| Cinco empleados DEMO | incluye Técnico Demo ID 5 | KEEP_FOR_INTERNAL_BETA; REMOVE_BEFORE_PILOT del entorno real |
| Usuarios DEMO/Pilot | 5; Admin, Operator, Support, Viewer y administrador DEMO | KEEP_FOR_INTERNAL_BETA; REMOVE_BEFORE_PILOT o reemplazo controlado |
| Assignment 8 | DEMO, Employee 5 → máquina 1, ACTIVE, maintenance_allowed | KEEP_FOR_INTERNAL_BETA; REMOVE_BEFORE_PILOT |
| Geofence DEMO v2 | ID 4, máquina 1, ACTIVE; v1 SUPERSEDED | KEEP_FOR_INTERNAL_BETA; REMOVE_BEFORE_PILOT |
| Activities 1 y 2 | ambas COMPLETED, Employee 5, geofence v2; 8 eventos | KEEP_FOR_INTERNAL_BETA; REVIEW_BEFORE_PILOT para archivo/retención |
| Notas/fotos/tickets/asistencias DEMO | 1 nota, 1 evidencia actividad, 2 tickets, 22 eventos vending | KEEP_FOR_INTERNAL_BETA; REVIEW_BEFORE_PILOT; no borrar historia por defecto |
| FIELD_MOBILE actual | 1 binding ACTIVE, sólo identidad DEMO | KEEP_FOR_INTERNAL_BETA; REVIEW_BEFORE_PILOT; no clonar/reasignar |
| LOCAL_SIMULATED | setting DEVICE_DEMO_PHONE en .env.local ignorado | KEEP_FOR_INTERNAL_BETA; REMOVE_BEFORE_PILOT de configuración real |
| 2,502 empleados MANUAL | importación corporativa autorizada, no son source FORTIA | PRODUCTION_REFERENCE; REVIEW_BEFORE_PILOT de alcance/permisos |
| SYBI 7 y 4 | máquinas IDs 4/5 DRAFT, source SYBI | PRODUCTION_REFERENCE; no tratarlas como DEMO eliminable |
| Assignment 7 | MANUAL autorizado, Employee 210 → máquina 4 (SYBI 7) | PRODUCTION_REFERENCE, preservar |

No hay Employees con source FORTIA en esta DB: 5 DEMO + 2,502 MANUAL = 2,507.
El archivo corporativo no fue reimportado ni reabierto. SYBI 7 permanece sin
Device ni geofence. ASISTENCIAS_FORTIA clean es el baseline documental recibido:
no se abrió ni modificó ese proyecto/DB externa en esta auditoría exclusivamente local.

## 8. Backup DB y archivos necesarios

DB BACKUP: **PASS**. Script existente `scripts/security/backup_mysql.ps1 -SkipOffsite`,
credenciales en archivo temporal local retirado por el script, sin contraseñas en
salida/argumentos. Sin offsite ni restore. Todas las 82 tablas son InnoDB.

- Path: `storage/app/backups/mysql/Vending_Attendance-vending_attendance_dev-20260911_230904-utc.zip`.
- Timestamp: **2026-09-11T23:09:04.3219665Z**.
- Size: **387,530 bytes**.
- SHA256: **6d090f4bbd3111755229c567d3a428e864fac63ac28e580cf6fd7f9f479fa5cd**.
- ZIP íntegro; dump contiene 82 CREATE TABLE y 39 INSERT statements. Esquema y
  datos completos de vending_attendance_dev; incluye opciones routines/events/triggers.
- `--single-transaction` conserva consistencia DB sin detener el teléfono. No
  se promete atomicidad con archivos externos; se preservaron aparte los existentes.

Complemento privado necesario para recuperar fotos:
`storage/app/private/internal-beta-1.0.1-beta.1-build-2/support-private-files.zip`,
760,130 bytes; SHA256 **f03cb57c2eec675eedcb75c20328de5f346455b9b59bf9f258b203ace51b98dd**.
ZIP verificado, 6 archivos locales (incluye metadata de directorio), sin publicación.
Un dump DB por sí solo no recupera las fotos del disco.

## 9. APK preservada e investigación de hash

La documentación previa describía 29,377,422 bytes, compilación 16:16:04 UTC,
hash `0aa559a881ac0a26b1cd35ea3759838ad9c90faa6b515fe2ac03ea7013f491b4`.
El archivo actual tiene **29,374,425 bytes**, timestamp **16:48:31 UTC** y hash
**1f57e7af956b2f4ea6be612e0bfb804feb4c910b850feff1fb8006d314631898**.

Investigación: `aapt` confirma debug, package com.medicalife.vendingattendance,
versionName 1.0.1-beta.1 / versionCode 2. Lecturas ADB de package y `sha256sum`
sobre `base.apk` instalado en HONOR confirman **exactamente el hash actual**.
lastUpdateTime 2026-09-11 10:49:56 local; firstInstallTime 2026-09-04 16:03:36.
La APK fue actualizada después del acta anterior. No se reescribe el hash histórico
como si hubiera sido el mismo binario; no se conservó aquí copia de aquel binario.

Los 100 archivos de mobile/dist coinciden byte por byte con assets/public de la
APK actual. No hay archivos de mobile/src o Android app/src posteriores al binario
actual según timestamps. Esto apoya correspondencia, no demuestra reconstrucción
bit a bit ni identifica por sí solo cada cambio de la recompilación.

Preservada sin recompilar:
`storage/app/private/internal-beta-1.0.1-beta.1-build-2/vending-attendance-1.0.1-beta.1-build-2-debug-observed-unapproved.apk`.
El sufijo unapproved conserva el nombre usado durante la investigación y significa
que aún no está autorizada su distribución ni el checkpoint, no que difiera de la APK instalada. No se extrajeron datos ni claves del teléfono.

## 10. Reproducibilidad y seguridad

Snapshot fuente previo al cierre:
`storage/app/private/internal-beta-1.0.1-beta.1-build-2/source-worktree.zip`,
4,642,668 bytes, SHA256 **f974bfffc6a761b0dd31546d3bb5359aa58dd04296cb096b9d2c2a52a7e6df66**.
Manifest `source-sha256.json` en el mismo directorio. No sustituye un commit.
Conservar composer.lock, ambos package-lock.json, Gradle wrapper y metadata beta.
Reproducibilidad estructural disponible con worktree y entorno; compilación limpia
y reproducción binaria exacta **NO ejecutadas**, conforme al mandato de no recompilar.

Configuración ignorada necesaria: `.env`, `.env.local`, `mobile/.env.local`,
`mobile/android/local.properties`, SDK/JDK y firma debug existente; TLS local y
claves bajo `storage/framework/local-https`. Este último directorio devolvió acceso
denegado a enumeración; no se cambiaron ACL ni copiaron secretos para sortearlo.
Conservar el APP_KEY original es necesario para descifrar campos del backup.

Variables por nombre, nunca valores: APP_ENV, APP_KEY, APP_URL, ASSET_URL,
APP_TIMEZONE, OPERATIONS_TIMEZONE, OPERATIONS_STORAGE_TIMEZONE; DB_CONNECTION,
DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD; DEVICE_DEMO_PHONE;
VITE_API_BASE_URL, VITE_APP_BASE_PATH; FORTIA_SYNC_DRIVER, FORTIA_SYNC_CONNECTION,
FORTIA_SYNC_TABLE, FORTIA_DB_*, FORTIA_MOCK_DB_*, SYBI_VENDING_API_*;
SESSION_*, CACHE_*, QUEUE_CONNECTION, FILESYSTEM_DISK; JAVA_HOME y ANDROID_HOME
o local.properties. No hace falta inventar valores ni usar conectores reales para
reconstruir la beta. Signing productivo VENDING_ANDROID_* no se configura aquí.

SECURITY: **PASS, scan acotado**, no pentest. 1,275 archivos versionables y
1,081 entradas APK escaneados; 11 valores sensibles configurados cotejados en
memoria, cero coincidencias. Único marcador PEM: cadena literal `redacted` en
test negativo DeviceIdentityTest.php:428, sin material privado. No se empaquetaron
.env, DB, OTP real, teléfono configurado ni claves privadas. ZIP/DB/fotos y snapshot
están ignorados por Git. El backup privado contiene datos de recuperación y se
mantiene local. No subirlo como evidencia pública.

Seguridad funcional preservada: HMAC/RBAC, identidad personal separada, HTTPS humano,
CA usuario sólo host DEMO en DEBUG; DEBUG conserva excepción HTTP para terminales
preexistente, RELEASE no recibe esa configuración. No ampliar confianza ni OTP.

## 11. Evidencia y baseline

Actualización consolidada en [internal-beta-validation.md](internal-beta-validation.md)
y este documento. Baseline SELECT en transacción READ ONLY:
attendance_logs=0; vending_attendance_events=22; activities=2 COMPLETED;
activity events=8; notes=1; activity evidence=1; employees=2507; users=5;
assignments=8; employee_devices=1 ACTIVE; OTP rows=1; tickets=2.
Hashes de lectura local están en `storage/app/private/beta-audit.json` y
`internal-beta-1.0.1-beta.1-build-2/baseline-before.json`. El algoritmo local ordena
filas JSON lexicográficamente: no comparar con hashes históricos de otro algoritmo.
Telemetría/sesiones pueden cambiar por la app abierta, sin escrituras de esta auditoría.
Phase 14 se conserva íntegra en el snapshot, sin edición de sus archivos.

## 12. Runbook de recuperación — NO ejecutado

1. Obtener autorización específica, aislar una instancia de recuperación dentro
   del workspace y detener escritores de esa instancia. No restaurar encima de la
   DB actual, Fortia ni SYBI. Registrar la versión y conservar el estado previo.
2. Verificar SHA256 de fuente, dump, archivos privados y APK de este documento.
   Hasta existir checkpoint autorizado, recuperar HEAD indicado más snapshot del
   worktree; HEAD solo no basta. Restaurar en destino vacío para evitar mezclas.
3. Instalar dependencias desde lockfiles con PHP 8.4/JDK/SDK/Gradle compatibles.
   Recuperar configuración privada del custodio, incluido APP_KEY original; no
   generar uno nuevo para intentar abrir los campos cifrados.
4. Seguir `docs/operations/backup-restore-runbook.md`: restaurar dump en una DB
   nueva vending_attendance_*restore*test*, nunca ejecutar migraciones destructivas
   ni el script aquí automáticamente. Validar schema, conteos y vínculos protegidos.
5. Restaurar archivos support-private conservando rutas relativas; comprobar hashes
   y accesibilidad autorizada. Configurar disco privado, nunca public/storage.
6. Mantener listener LAN HTTPS y TEMP/TMP/timeout/límite de carga de
   `tools/local-field-https/httpd.conf`; custodio conserva CA/clave del servidor.
   Transferir sólo CA pública verificada a equipo autorizado, no private keys.
7. Usar APK preservada si sólo se recupera candidata. No recompilar por rutina.
   Instalación futura autorizada mediante actualización compatible; jamás pm clear,
   uninstall, downgrade forzado o clonación Keystore/SQLite. El backup del servidor
   no recupera claves Android perdidas: ese caso requiere proceso de identidad aparte.
8. Validar baseline e identidad y hacer gates autorizados antes de liberar acceso.

## 13. Second Android prerequisites

SECOND ANDROID: **BLOCKED**, instalación adicional NOT_TESTED.

- Employee/User individuales, activos, enlace autorizado y permisos backend.
- Resolver acepta identidad Fortia válida o DEMO exacta existente; MANUAL import
  corporativo no es automáticamente identidad Fortia.
- RegisteredPhoneSource sólo resuelve el DEMO actual. Definir y autorizar estrategia
  local/teléfono para el segundo tester, sin compartir Pilot Support o excepción global.
- Android >=23, LAN, CA pública verificada y APK/hash aprobados para ese equipo.
- UUID/keypair Keystore y FIELD_MOBILE propios; registro, OTP simulado explícito y
  challenge sólo en gate posterior autorizado. No clonar binding del HONOR.
- Assignment/capacidades pertinentes si se asignará trabajo; no confundir FIELD_MOBILE
  con terminal o permiso de asistencia. No se creó ningún requisito en DB ahora.
- HIGH inicial resuelta; autorizar checkpoint y después distribución controlada.

## 15 y 16. Checks y checkpoint

- git diff --check: PASS al iniciar; comprobación final registrada al cerrar.
- Security scan acotado e integridad ZIP/APK: PASS.
- Baseline DB: lectura solamente, conteos esperados; comparación final de hashes
  protegidos en el informe local. No generar nuevas operaciones.
- Suites completas y pruebas físicas: no repetidas. Frontend/build: no aplica,
  no se cambió código ni la etiqueta propuesta.
- No git add, commit, tag, push ni deploy. El usuario exige autorización explícita
  antes de git add/commit/tag. El alcance propuesto es el worktree completo de
  las fases pendientes y documentación, previamente escaneado, excluyendo todo
  archivo ignorado/privado; no se ejecuta git add -f.

**FINAL: READY_FOR_CHECKPOINT_AUTHORIZATION.**
Siguiente acción sólo tras autorización explícita: revisar selección de archivos
versionables, git add, commit y tag; sin push/deploy. Propuesta de commit:
`chore(beta): close internal beta 1.0.1-beta.1 build 2`.
Propuesta de tag: `internal-beta/1.0.1-beta.1-build.2`. No se han creado.

Comparación final 23:16:51 UTC: 14 conjuntos protegidos permanecen idénticos,
incluidos empleados, usuarios, asignaciones, actividades, notas/fotos, identidad
y asistencias. Informe local: `baseline-final-comparison.json`. El backup
preserva ese baseline; telemetría viva posterior es esperable.
