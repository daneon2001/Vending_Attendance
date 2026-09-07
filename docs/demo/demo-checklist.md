# Checklist de demo controlada — Fase 11

## Revisión de presentación Fase 12

Estado: PASS para cierre UI/UX de demo, no aprobación productiva.
WEB: EXTERNAL_VISUAL_REVIEW_PREVIOUSLY_COMPLETED. Android final: HOME,
Limpiar con teclado y pendiente→confirmado verificados físicamente. La única
Salida autorizada de cierre añadió el id 19; los 18 eventos previos permanecen
intactos. Wi-Fi y datos móviles quedaron habilitados como al inicio.
Informe y límites: [ui-ux-final-review.md](ui-ux-final-review.md).
Preflight posterior PASS: red ONLINE, manifests 2/2 y 5/5, outbox 0, HIGH 0.
Repetir preflight antes de cada demo; esta evidencia no es telemetría en vivo.

### Histórico del primer pulido de Fase 12

Las casillas siguientes conservan el resultado de aquella ronda, no el estado
actual. Limpiar y la revisión externa se cerraron en el informe enlazado;
TalkBack integral y auditoría completa de accesibilidad siguen pendientes.

- [x] Preflight local antes/después del pulido: PASS, sin reset ni cambio de datos
  para aparentar salud. Preflight final: heartbeat 4 s, ONLINE, configuración 2/2,
  empleados 5/5, pending 0, HIGH 0, MEDIUM 1.
- [x] HONOR: tres actualizaciones `adb install -r`; sin desinstalar, borrar datos
  o reprovisionar. Dos empleados visibles; selección y Entrada/Salida accesibles.
- [x] Resultado GPS real, pendiente local y posterior recibo confirmado observados.
  Eventos DEMO nuevos 12/13; total acumulado 13. No borrar para reiniciar la demo.
- [x] Wi-Fi restaurada a habilitada; datos móviles siguen deshabilitados como antes.
- [x] Búsqueda por número, limpieza, teclado y regreso Android revisados físicamente.
- [ ] Revisión externa de «Limpiar»: con teclado se divide en dos líneas en el
  HONOR. Funciona, pero queda pendiente el ajuste visual; se respetó el máximo
  de tres iteraciones automáticas.
- [ ] Validación hablada con TalkBack. Hay etiquetas ARIA en español y el botón
  Limpiar aparece nombrado en uiautomator; el nombre del campo vacío no fue
  expuesto por esa herramienta. No declarar certificación de lector de pantalla.
- [ ] Revisar web en 1920×1080 y 1366×768 con Admin, Operator, Support y Viewer.
  Validar foco/teclado, filtros, detalle, tabla y login. Render SSR no certifica
  apariencia, contraste calculado en navegador ni ausencia de overflow real.
- [ ] Fortia debe mostrar «Fuente de prueba» cuando driver=mock; no ejecutar
  sincronización ni habilitar escrituras para mostrar la pantalla.
- [ ] Antes de cada demo, volver a ejecutar `php artisan vending:demo-preflight`
  y confirmar físicamente el Android. Demostrar OFFLINE sólo después de un
  arranque ONLINE saludable; nunca fabricar heartbeat, ACK, GPS ni contadores.

PRODUCT_DISPLAY_NAME sigue pendiente del propietario del producto. Se conserva
Vending Attendance; no se traduce arbitrariamente el nombre comercial.

Baseline: `vending-phase-10-pass` (`94275636386e666ebf251f976dff367e0ab66202`).
FASE 10 UX/DEMO: PASS por revisión externa aprobada por el usuario.
FASE 11 DEMO SCENARIO: PASS.
VALIDACIÓN FUNCIONAL: PASS. DEMO: READY. PRODUCTION: NOT_APPROVED.

## Estado vigente del cierre

Ver [final-demo-validation.md](final-demo-validation.md): ensayo físico offline,
SQLite, reinicio, reconexión y DUPLICATE comprobados. Tras autorización directa,
la APK final registró Entrada/Salida online (ids 10/11), con recibos individuales
confirmados. Dos resets locales a 05:16:56 UTC: PASS; las 63 tablas comparadas
quedaron idénticas antes/después de cada ejecución, preservando 11 asistencias.
Checkpoint autorizado: vending-phase-11-demo-pass. El usuario autorizó directamente
los 33 archivos revisados y el commit/tag, resolviendo el bloqueo de staging.
Sin push, modificación de tags anteriores ni deploy.
Las observaciones de preparación siguientes son históricas, no telemetría actual.

Preflight de cierre, 05:17:40 UTC: red y salud ONLINE, manifests SYNCED,
pending=0, 11 asistencias acumuladas y 5 recibidas desde el corte real del KPI.
Un dispositivo ONLINE, 0 HIGH y 1 MEDIUM. La incidencia de red anterior salió
naturalmente de su ventana de 30 minutos; no se borró ni se cambiaron umbrales.
Las casillas posteriores son una plantilla para repetir el preflight antes de
cada demostración; la evidencia cerrada consta en el informe enlazado.

## Alcance y estado observado

Preparación local comprobada el 6 de septiembre de 2026, 20:21 CDMX
(7 de septiembre, 02:21 UTC). No representa telemetría en vivo.

- VM-DEMO-001: ACTIVE, source DEMO; se reutilizó la identidad existente.
- ANDROID-DEMO-001 (HONOR 400): ACTIVE, credencial existente no revocada,
  pero OFFLINE. Último heartbeat: 5 de septiembre, 12:48:23 CDMX.
- Configuración v2 y empleados v5 coinciden con versiones aplicadas anteriores;
  el estado actual es STALE. No se fabricaron ACK ni heartbeats.
- Cinco empleados sintéticos adoptados del seeder antiguo como source DEMO,
  preservando IDs y números. Dos asignados a la máquina principal.
- Geocerca DEMO activa conservada: centro sintético 19.432608, -99.133209,
  radio 50 m. No demuestra que el teléfono esté dentro.
- Seis asistencias históricas en VM-DEMO-001: cinco sintéticas del antiguo
  terminal retirado y una de ANDROID-DEMO-001. La de Android está STORED,
  CHECK_IN, BIOMETRIC NOT_USED, geocerca OUTSIDE. No es evidencia de este ensayo.
- Pendientes reportados: 0, dato antiguo. Pendientes locales actuales: desconocidos.
  Dashboard: 0 recibidas hoy, 0 dispositivos ONLINE, 1 alerta HIGH visible.
- Fortia: mock; escrituras de empleados deshabilitadas. Sin llamadas a Fortia
  real ni SYBI durante esta preparación.
- ADB detectó el teléfono offline; identidad persistida, bootstrap y operación
  física actual todavía requieren comprobación en el equipo.
- El usuario reportó “En línea” pero Sincronizar no produjo un resultado visible.
  ConnectivityService utiliza la conexión de red de Android, no una prueba HTTP.
  La IP de VITE_API_BASE_URL en mobile/.env.local no coincide con ninguna IPv4
  actual del PC: posible endpoint de desarrollo desactualizado, todavía no
  confirmado contra la APK instalada. No se cambió ese archivo ni la APK/red.
  El servicio local Apache responde a /login, pero eso no prueba acceso desde
  el teléfono. Se necesita reconectar/autorizar USB o evidencia externa del endpoint
  instalado antes de cambiar su configuración. No reprovisionar.

El reset se ejecutó dos veces en la DB local: segunda ejecución idempotente,
sin diferencias en los registros comparados. La primera conservó íntegros
usuarios/RBAC, dispositivos/credenciales, estados de manifest, métricas, tokens,
asistencias, auditoría, geocercas, asignaciones, catálogo/runs SYBI, otras máquinas
y empleados ajenos. Sólo se normalizó el origen DEMO de la máquina principal
y los cinco empleados sintéticos existentes.

La validación Android del 5 de septiembre en
[e2e-validation.md](../mobile/e2e-validation.md) es evidencia previa, no
certificación física de este ensayo ni prueba de conectividad actual.

| Número existente | Nombre sintético | Estado | Asignación a VM-DEMO-001 |
| --- | --- | --- | --- |
| 990001001 | Empleado Demo Uno | A / DEMO | PRIMARY, vigente |
| 990001002 | Empleado Demo Dos | A / DEMO | No |
| 990001003 | Empleado Demo Tres | A / DEMO | No; histórico revocado preservado |
| 990001004 | Supervisor Demo | A / DEMO | SUPERVISOR, vigente |
| 990001005 | Técnico Demo | A / DEMO | No |

No renumerar como D001 sólo por estética. En una preparación donde falten
empleados, el reset crea DEMO1001–DEMO1005, sin identificadores Fortia nuevos.
No usa CURP, RFC, NSS ni archivos de personas reales.

## Reset seguro y límites

Desde C:\laragon\www\vending-attendance:

```powershell
php artisan vending:demo-reset
```

Es un reset de **configuración DEMO existente**, no una instalación desde cero,
no un reprovisionador y no un borrado de historial. Requiere la máquina, el
Android aprovisionado y una geocerca DEMO efectiva previamente autorizados.

Precondiciones que bloquean sin escrituras parciales si no se cumplen:

1. APP_ENV externo, configuración Laravel y entorno efectivo: local/testing.
   Production y staging prohibidos; no usar --env para saltarse el guard.
2. VM-DEMO-001 con UUID fijo del dataset VENDING_DEMO_V1, metadata.demo=true,
   source LOCAL/DEMO, sin sybi_id ni vínculo de catálogo SYBI y sin retiro histórico.
3. Exactamente un dispositivo ACTIVE en esa máquina: ANDROID-DEMO-001,
   is_active=true, credencial existente y no revocada. No se reactiva
   VM-DEMO-TERM-001 ni se cambia UUID, credencial, lifecycle o aprovisionamiento.
4. Pendientes reportados distintos de cero bloquean. Un valor UNKNOWN no
   certifica cero; el operador debe revisar el outbox local antes de presentar.
5. Geocerca efectiva source DEMO, centro/radio válidos; máquina con coordenadas
   verificadas y sin revisión pendiente. No se crea ni se mueve desde el reset.
6. Cada empleado reutilizado tiene la firma sintética completa del seeder:
   número/alias e ID externo esperados, nombre exacto y empresa ficticia.
   Sólo LEGACY demostrado o DEMO; sin datos personales, empresa/sucursal real,
   enrolamientos ni asignaciones históricas hacia máquinas ajenas al dataset.
   Cualquier colisión con FORTIA/MANUAL u origen ambiguo bloquea.
7. Se preservan las dos asignaciones canónicas vigentes e indefinidas; si faltan,
   se crean una vez con UUID estable y permiso de asistencia. Revocaciones,
   reasignaciones o vigencias inesperadas requieren revisión explícita.
   No se reviven asignaciones revocadas ni se eliminan extras; la selección
   efectiva debe quedar entre dos y tres empleados de este dataset.

Cambios autorizados del reset: clasificar la máquina como DEMO y dejarla ACTIVE;
crear/reutilizar los cinco empleados sintéticos activos; asegurar las dos
asignaciones; generar snapshots con servicios existentes. Los números ya
existentes se conservan y las versiones sólo avanzan por cambios reales.

El reset es transaccional y repetible. Conserva todas las asistencias, incluyendo
las DEMO, auditoría, usuarios, roles, permisos, coordenadas, identidad y métricas
del dispositivo. Por ello los contadores históricos **no vuelven a cero**.
Registrar los UUID y conteos iniciales de cada ensayo; comparar deltas.

No ejecutar VendingDemoSeeder ni vending:demo-cleanup sobre el teléfono de demo:
el seeder anterior simula telemetry/ACK/eventos y el cleanup elimina histórico
y dependencias. Se conservaron intactos, pero no forman parte de este recorrido.

Un exit 0 / DEMO RESET: PASS significa preparación terminada, **no DEMO READY**.
La salida exige preflight físico incluso cuando las versiones coinciden.
Si se bloquea, detenerse y revisar estas precondiciones; no cambiar sources,
credenciales, flags o telemetría para eludirlas. Nunca introducir passwords
en argumentos, capturas, documentación ni logs.

## Preflight exacto — antes de iniciar el cronómetro

- [ ] DB: instancia local correcta, sin producción. Conservación del historial
  comprobada; no importar el dataset real de 2,502 filas, dumps ni CSV/XLSX reales.
- [ ] APP: servidor vending-attendance accesible desde el navegador real;
  abrir /vending. No iniciar otra aplicación ni modificar .env por ensayo.
- [ ] Usuario: pilot.operator@example.test (Pilot Operator). Credenciales
  introducidas fuera de proyección. Admin sólo para preparación autorizada;
  no resetear passwords como parte de vending:demo-reset.
- [ ] Android: HONOR 400 desbloqueado, batería suficiente, app existente
  com.medicalife.vendingattendance. Sólo actualizar APK mediante install -r
  cuando esté autorizado; nunca desinstalar, borrar datos o reprovisionar.
- [ ] Wi-Fi: PC y Android en la red local prevista, sin aislamiento de clientes.
  Desactivar datos móviles sólo durante la prueba OFFLINE deliberada.
- [ ] IP/puerto: validar que el endpoint configurado en la app corresponde al
  servidor accesible por Android, no a 127.0.0.1 del teléfono. Registrar valores
  en la hoja privada del operador; no publicarlos ni modificar protocolos.
  Si ADB está offline, revisar cable, desbloqueo y autorización USB en el equipo.
- [ ] Identidad: al cerrar/reabrir la app no solicita activación y mantiene
  ANDROID-DEMO-001 / VM-DEMO-001. El código utiliza almacenamiento nativo
  Keychain/Keystore; comprobar persistencia por comportamiento y petición
  autenticada exitosa, nunca extrayendo o mostrando secretos.
- [ ] Bootstrap: abrir app y pulsar Sincronizar; debe mostrar VM-DEMO-001, ACTIVE,
  versiones y empleados correctos. Si falla, registrar el mensaje sin secretos.
- [ ] Device: servidor ONLINE con conexión reciente. ACTIVE sólo significa
  habilitado. No bajar umbrales (3 min degradado / 10 min offline por defecto).
- [ ] Manifests: configuración y empleados SYNCED tras comunicación real;
  versiones coinciden y ACK existentes son válidos. READY en CLI no es ACK.
- [ ] Employees: cinco DEMO activos, sin datos reales. En Android aparecen al
  menos Empleado Demo Uno y Supervisor Demo con sus números existentes.
- [ ] Assignments: dos vigentes en VM-DEMO-001, UUID conservados. No restaurar
  la revocada ni asignar a una máquina real para completar el conteo.
- [ ] Geofence: activa y vigente. GPS real, permiso y precisión suficientes;
  elegir y documentar A/B abajo. Ubicación física actual todavía por comprobar.
- [ ] Pending: 0 en el outbox Android y heartbeat reciente reportando 0.
  Un contador agregado o antiguo del dashboard no sustituye la lectura local.
- [ ] Alerts: 0 HIGH reales antes de la demo. Revisar el dispositivo y el
  alcance de la lista limitada del dashboard; no ocultar otras incidencias.
  El terminal histórico retirado puede conservar una alerta MEDIUM.
- [ ] SYBI: mostrar únicamente catálogo fuente, sin sincronizar/promover ni
  alterar coordenadas. Mojibake documentado como SOURCE_ENCODING_ISSUE.
- [ ] Fortia mock: visible “Modo de prueba”, sin conexión Fortia real ni
  activación de flags de escritura. No hacer sync como requisito del reset.
- [ ] ASISTENCIAS_FORTIA: git status --short sin cambios.
- [ ] Anotar hora CDMX/UTC de inicio, conteo server inicial, último evento del
  Android, pending local y estado de conexión. Capturas sin credenciales.

Si cualquier gate físico falla, DEMO: NOT_READY. Resolver conectividad o
documentar el bloqueo; nunca emitir heartbeat, ACK, GPS o asistencia simulados.

## Geocerca: elegir sin falsear GPS

A. Conservar la geocerca DEMO existente y demostrar OUTSIDE honestamente si
corresponde. Un evento STORED no equivale a geocerca INSIDE ni a autorización
laboral; revisar ambos resultados por separado.

B. Sólo con autorización explícita del responsable, un Pilot Admin puede crear
una **nueva versión** de geocerca local para VM-DEMO-001 usando ubicación real
comprobada del lugar de demo. Conservar la versión previa, motivo, responsable,
vigencia y auditoría mediante el flujo existente. No tocar coordenadas fuente
SYBI ni máquinas productivas; no ampliar el radio sólo para aparentar éxito.
Sin esa autorización, mantener A. El reset no ejecuta B.
Después, esperar configuración actualizada y ACK real antes de capturar.

## Evidencia ONLINE/OFFLINE y dashboard

Seguir [demo-walkthrough.md](demo-walkthrough.md). No confundir estados:
STORED/DUPLICATE son recibos servidor; SYNCED corresponde al outbox local.

- [ ] ONLINE: UUID de Entrada, empleado, device, hora real y captura de resultado.
- [ ] Recibo servidor del mismo UUID, una sola fila en vending_attendance_events,
  biometric_result=NOT_USED; no basta “Guardado localmente”.
- [ ] OFFLINE: red realmente cortada (Wi-Fi y datos), Salida guardada y pending=1.
- [ ] Cierre/reapertura sin red: identidad y pending persisten, no captura extra.
- [ ] Reconexión: el mismo UUID llega, pending=0, fila local SYNCED con remote_id.
  Si no existe visor de outbox en UI, comprobación técnica **sólo lectura** de
  event_uuid/status/remote_id en sync_outbox, sin extraer la identidad segura.
- [ ] SQL servidor sólo lectura: contar por cada UUID registrado, esperar 1
  antes y después de repetir Sincronizar; no crear otro evento para probar reintento.
- [ ] Dashboard /vending recargado: recibidas hoy aumentan por los eventos nuevos
  según el corte configurado, conexión reciente, pendientes reportados actuales,
  alertas reales, empleado y dispositivo correspondientes. No resetear totales.
- [ ] Registrar las horas antes/después: durante OFFLINE el dashboard puede
  conservar pending=0 antiguo; no es evidencia de pérdida ni de outbox vacío.
- [ ] Si la UI no muestra listado individual de asistencias Vending al Operator,
  validar fila/recibo mediante consulta administrativa local de sólo lectura;
  no inventar una pantalla ni ampliar RBAC.
- [ ] Medir duración real del ensayo y registrar recuperación/fallas; sin medición
  los tiempos del guion son presupuesto, no resultados certificados.

## Verificación automatizada y límites

```powershell
php artisan test --filter=VendingDemoResetCommandTest
php artisan test --filter=Vending
php vendor/bin/pint --test app/Console/Commands/VendingDemoResetCommand.php tests/Feature/Vending/VendingDemoResetCommandTest.php
git diff --check
```

Resultado actual: reset automatizado incluido en Vending 187 PASS /
1,374 assertions; web frontend 58 PASS; móvil 167 PASS; Android nativo 4 PASS.
Build móvil, cap sync y assembleDebug PASS. Suite completa 506 PASS / 1 FAIL,
únicamente OnPremDiagnosticsCommandTest; 0 regresiones nuevas.
DashboardExecutiveSummaryTest se hizo determinista tras reproducir en baseline
su dependencia de horario; no se modificó lógica productiva.
Referencia de Fase 10: frontend 58 PASS, build PASS; suite completa 479 PASS /
1 falla OnPremDiagnosticsCommandTest demostrada en baseline.
AuditCleanupModuleTest ya quedó determinista; véase
[audit-cleanup-temporal-investigation.md](audit-cleanup-temporal-investigation.md).
No declarar PASS de suite completa a partir de pruebas dirigidas.

Sin cambios HMAC, attendance, manifests, biometría, RBAC, salud/lifecycle,
contratos SYBI/Fortia ni ASISTENCIAS_FORTIA. Sin commit, tag, push o deploy.

## Evidencia anterior y decisiones pendientes

Revisión UX externa de Fase 10 aprobada por el usuario; no se reabre ni rediseña.
Antecedentes: [ux-report.md](ux-report.md), [ux-audit.md](ux-audit.md),
[import-sybi-evidence.md](import-sybi-evidence.md). Sus estados intermedios
corresponden a rondas anteriores al checkpoint aprobado.

PRODUCT_DISPLAY_NAME: PENDING_PRODUCT_OWNER_CONFIRMATION. Se conserva
“Medical Life · Vending Attendance”; no traducir arbitrariamente el nombre.
Producción y Fortia real siguen fuera de alcance.
