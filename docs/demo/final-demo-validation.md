# Fase 11 — evidencia de cierre de demo controlada

Fecha: 6 de septiembre de 2026 CDMX / 7 de septiembre UTC.
Baseline: vending-phase-10-pass, 94275636386e666ebf251f976dff367e0ab66202.
FASE 11: PASS. VALIDACIÓN FUNCIONAL: PASS.
DEMO: READY. PRODUCTION: NOT_APPROVED.
Checkpoint autorizado para este cierre: vending-phase-11-demo-pass.
Sin push, remote ni deploy.

## Autorización directa del cierre Git

Después de completar los gates técnicos y revisar los 33 archivos del diff,
el control de ejecución rechazó git add -A antes de ejecutarlo: consideró
vigentes restricciones anteriores de commit/tag y señaló el riesgo de incluir
archivos ajenos o sensibles mediante staging global. El alcance condicional de
Fase V del adjunto fue leído, pero no se eludió el rechazo con staging selectivo
ni otro mecanismo. Después, el usuario respondió «sí» a la autorización explícita
para preparar los 33 archivos revisados, crear el commit
feat(demo): complete vending attendance live demo scenario y el tag
vending-phase-11-demo-pass, sin push. Con ello quedó resuelto el bloqueo.

Verificación posterior de sólo lectura: índice vacío, HEAD permanece en
94275636386e666ebf251f976dff367e0ab66202, el tag de Fase 11 no existe y no hay
remotes. Esta lectura corresponde al estado anterior al cierre autorizado.
El inventario y la búsqueda de secretos se repitieron antes del staging: 33
archivos, ningún artefacto prohibido ni coincidencia con secretos configurados.
No hubo cambios de código después de las pruebas; esta reanudación sólo actualiza
el estado documental y materializa el checkpoint. Los tags anteriores se preservan.

## Autorización y resolución del bloqueo anterior

La protección de ejecución rechazó, antes de ejecutarlas:

- La pulsación SALIDA para una nueva asistencia online de la APK final.
- Las dos ejecuciones de vending:demo-reset sobre la DB local.

El revisor interpretó restricciones anteriores como vigentes y pidió autorización
directa para esas mutaciones. Se confirmó el alcance del adjunto y el control
exacto mediante XML; se permitió navegar al empleado, pero no capturar.
No se reintentó la asistencia por API, otra pulsación o un mecanismo alternativo.
El reset tampoco se ejecutó indirectamente durante ese bloqueo. Después, el
usuario respondió «sí» directamente a la autorización conjunta de nuevas
asistencias DEMO y dos resets locales. Ambas operaciones se completaron:
CHECK_IN id 10, CHECK_OUT id 11 y dos resets idempotentes, documentados abajo.
No hizo falta desbloqueo manual, reprovisionamiento ni borrado de datos.

El revisor también rechazó vaciar logcat. Se conservaron todos los registros y
se aisló la evidencia por PID/timestamp; esto no impidió la primera prueba física.
En la reanudación se rechazó una nueva copia completa de SQLite y la impresión
de una captura como base64. No se eludieron esas restricciones: para las nuevas
asistencias se verificaron los recibos individuales visibles mediante la
jerarquía Android y las filas acotadas del servidor, sin extraer otra base.

## Android y evidencia física

Equipo: HONOR 400, ANDROID-DEMO-001, VM-DEMO-001. Empleado sintético 990001001.
Detalles reproducibles: [offline-gps-validation.md](../mobile/offline-gps-validation.md).

| Gate | Evidencia | Estado |
| --- | --- | --- |
| CHECK_IN / CHECK_OUT online anteriores | Usuario aprobó ids 7/8, GPS y recepción, demora 4/23 s, OUTSIDE/OUTSIDE | PASS previo |
| Aislamiento | Wi-Fi y datos OFF, forward/reverse vacíos, API Network is unreachable, GPS ON | PASS físico |
| GPS offline | Solicitud 04:30:49.649Z; fix 04:30:50.036Z; éxito TypeScript 04:30:50.893Z; 1244 ms | PASS físico |
| Asistencia offline | CHECK_IN local PENDING; total local 4, tres previamente SYNCED | PASS físico |
| Servidor durante corte | Total 8 / máximo id 8, también después del reinicio | PASS físico |
| Persistencia | Ocho tablas locales idénticas antes/después de force-stop y reapertura; mismo Secure Storage | PASS físico |
| Reconexión automática | Sólo Wi-Fi; recibido 04:37:44Z como id 9 STORED, demora 414 s | PASS físico |
| Outbox | Mismo UUID/payload, SYNCED; servidor pasa exactamente de 8 a 9 | PASS físico |
| Idempotencia | Reenvío HTTP autenticado del payload original: 200 DUPLICATE, una fila, evento intacto | PASS real |
| Geocerca | OUTSIDE edge / OUTSIDE servidor, AUTHORIZED; sin cambio de radio/centro | PASS paridad |
| APK final | Build, instalación install -r y arranque correctos; hashes SQLite/Secure Storage idénticos antes/después de actualizar | PASS |
| CHECK_IN online de APK final | id 10: GPS 2419 ms; capturado 05:13:12Z, recibido 05:13:16Z; STORED, demora 4 s | PASS físico |
| CHECK_OUT online de APK final | id 11: GPS 2398 ms; capturado 05:15:28Z, recibido 05:15:31Z; STORED, demora 3 s | PASS físico |
| Recibos individuales de APK final | Entrada y Salida muestran «Asistencia registrada correctamente» tras confirmación; total servidor 11, pending 0 | PASS físico |

El reenvío se realizó desde un arnés PHP local, usando la credencial existente
únicamente en memoria y el contrato HMAC vigente, con timestamp/nonce nuevos.
No se imprimieron firmas ni secretos, no se alteró el outbox ni el evento.
No confundir reenvío de evento con replay del mismo nonce, que debe rechazarse.

No se pudo demostrar la causa nativa del antiguo mensaje GPS_UNAVAILABLE: no se
reprodujo con la implementación actual. El plugin normal entregó una posición
fresca offline; no se añadió fallback GNSS, caché, tolerancia ni otro proveedor.

## Presentación móvil

- Se conservaron las mejoras previas: geocerca y Entrada/Salida en español,
  UUID/precisión ocultos, distancia humana y recibo individual PENDING/SYNCED.
- Nuevo estado «Obteniendo ubicación…» y explicación tras 10 s. Es un timer
  de presentación, con limpieza al terminar/destruir la vista; no altera GPS.
- Timeout amigable solicitado; categorías de diagnóstico con elapsed_ms como
  JSON legible en logcat, sólo en development y sin datos personales.
- Inicio presenta lifecycle, fase de sync y asignaciones en español. Los enums
  permanecen intactos. La hora antes llamada «Último sync» realmente se escribe
  al confirmar configuración/empleados; ahora la etiqueta aclara ese alcance.
  No se fabricó una hora de sincronización ni se cambió su persistencia.
- Se inspeccionaron el inicio y los resultados de la APK nueva. Los dos fixes
  online tardaron menos de 3 s; no se afirma haber observado físicamente una
  espera superior a 10 s. El mensaje demorado y su limpieza tienen tests con
  reloj controlado, sin simular ese retraso en una asistencia real.

## Web, roles y datos reales

Chrome local headless mediante Playwright instalado; contextos independientes
con las cuatro cuentas piloto y sus passwords existentes en memoria. Sin reset
de passwords, exportar sesiones, trazas de autenticación ni nuevas dependencias.

Login, /vending, dispositivos, máquinas, catálogo SYBI, empleados y versiones:
HTTP 200 para los cuatro roles, sin errores JavaScript ni códigos/etiquetas
técnicas prohibidas en el texto visible examinado. Import visible sólo para
Admin/Operator; wizard de cinco pasos inspeccionado después de su transición.
Support/Viewer no muestran importación, consulta Fortia o acciones de edición.
Admin conserva administración; Operator no edita máquinas/geocercas/versiones.
Auditoría: HTTP 403 para los cuatro pilotos y sin enlace de navegación; no se
amplió RBAC. La auditoría del recorrido debe presentarla un responsable con
permiso o mediante evidencia sanitizada, no desde la cuenta Operator.

No hubo overflow horizontal de página en los seis módulos a 1366×768 para los
cuatro roles ni a 1920×1080 para Operator. Se revisaron capturas de las pantallas
principales, con filtros/detalles colapsados y estados vacíos honestos. No se
rediseñó web ni se certifican todas las combinaciones posibles de datos/zoom.

Import CSV/XLSX, preview/diff, paginación, confirmación, concurrencia,
idempotencia, ownership Fortia y ceros iniciales: cubiertos por tests de fixtures
aislados. No se aplicó dataset real ni se cargaron archivos personales en web.
Catálogo SYBI separa fuente/promoción y muestra estados amigables; los tests
cubren READY, INCOMPLETE_LOCATION e IDENTIFIER_CONFLICT. Mojibake visible se
conserva como SOURCE_ENCODING_ISSUE, según evidencia aprobada de Fase 10.
No se sincronizó/reescribió fuente SYBI ni se conectó Fortia real.

Lectura a 04:57:28Z: 9 eventos acumulados, KPI attendance_today=3 conforme al
corte real de la aplicación, pending=0, configuración v2 y empleados v5 SYNCED.
Red ONLINE, heartbeat de 62 s; salud DEGRADED únicamente por RECENT_NETWORK.
El timeout reportado a 04:44:16Z conserva una ventana de 30 minutos: no se borra
ni se acorta para aparentar ONLINE. Alertas visibles: 0 HIGH y 2 MEDIUM.
Los totales incluyen registros previos; no se equiparan «hoy» y total acumulado.
Antes de la demo debe repetirse el preflight y comprobar salud real ONLINE.

Preflight de cierre a 05:17:40Z, tras las capturas y ambos resets: salud ONLINE,
sin razones de degradación; red ONLINE, heartbeat de 35 s, configuración y
empleados SYNCED, pending=0. Total acumulado 11 y KPI attendance_today=5;
devices_online=1, devices_degraded=0, alerts HIGH=0 / MEDIUM=1.
La alerta reciente caducó por su ventana natural de 30 minutos, sin mutar
last_error ni alterar el cálculo. Se conserva la alerta histórica restante.

## Falla adicional de suite: horario del dashboard legado

La primera suite de esta ronda: 500 PASS / 2 FAIL. Además de OnPrem, falló
DashboardExecutiveSummaryTest::test_dashboard_summary_can_refresh_only_clocks_tab
en línea 541: esperaba dos alertas, recibió una. Se reprodujo también aislado.

Comparación segura: worktree detached de vending-phase-10-pass bajo el directorio
temporal vending-phase11-20260906/phase10-baseline, con vendor independiente
copiado, sin .env ni cambios del árbol actual. Test y servicio eran idénticos
al tag. Tres ejecuciones baseline produjeron exactamente la misma falla.
Un bootstrap temporal, sin modificar el test, fijó Carbon y confirmó por
reflexión que DashboardSummaryService se cargaba desde el worktree baseline:

| Reloj CDMX explícito | Test original en tag |
| --- | --- |
| 07/09/2026 10:00:00 | PASS, 13 aserciones |
| 06/09/2026 22:00:00 | FAIL: una alerta frente a dos |

Root cause: el test no fijaba la hora. buildConnectivityAlerts devuelve dos
alertas durante 07:00 ≤ hora < 20:00, pero una informativa fuera de horario.
No es el cálculo de salud Vending ni un bug introducido por móvil.

Corrección sólo del test: Carbon y zonas explícitas; seis escenarios a 06:59:59,
07:00:00, 19:59:59, 20:00:00, 23:59:59 y 00:00:00, con conteos y business-hours
exactos. Se conservan las demás aserciones de payload parcial. No se amplía
tolerancia ni cambia lógica productiva. Pint normalizó formato del archivo.
Tres repeticiones de DashboardExecutiveSummaryTest: 14 PASS / 293 aserciones
cada una; Laravel restaura los relojes en teardown. Worktree baseline limpio.

## Validación final ejecutada

| Comando / alcance | Resultado |
| --- | --- |
| Laravel Vending, repetido tras la corrección | 187 PASS / 1374 aserciones |
| php artisan test --compact | 506 PASS / 1 FAIL / 4003 aserciones |
| Única falla | OnPremDiagnosticsCommandTest, línea 54, exit 1 frente a 0; demostrada previamente en baseline |
| Frontend web Node | 58 PASS |
| Mobile npm test | 167 PASS / 20 archivos |
| Mobile npm run build | PASS |
| npx cap sync android | PASS |
| assembleDebug y testDebugUnitTest | BUILD SUCCESSFUL; 4 tests nativos, 0 fallos |
| Pint scoped, comando reset y sus tests + dashboard test | PASS |
| git diff --check | PASS |

Regresiones nuevas: 0 en las pruebas ejecutadas; no se afirma que la suite
completa sea verde. OnPrem no se modificó ni se ocultó. Las advertencias de
Browserslist, Tailwind/Ionic y tamaño del bundle se mantienen como deuda técnica;
no se actualizaron dependencias para este cierre.

## Reset y seguridad

El comando local/testing existente conserva historial, credenciales, identidad,
roles, origen ajeno y geocerca; sus 22 casos automatizados pasan dentro de Vending.
Las dos ejecuciones anteriores constan en demo-checklist como antecedentes.
Tras la autorización directa se repitió exactamente dos veces a 05:16:56Z:
exit 0 y DEMO RESET PASS en ambas. Con la app detenida brevemente para evitar
telemetría concurrente, se compararon en memoria los registros de las 63 tablas
de la DB local antes/después de cada ejecución. Cambios: ninguno. Se preservaron
las 11 asistencias, usuarios/passwords, RBAC, credenciales, tokens, geocerca,
asignaciones, manifests, fuentes SYBI y datos ajenos. No se imprimieron registros,
secretos ni hashes de credenciales ni se generó un dump. La app se reabrió y
recuperó su estado saludable; no se reprovisionó.

HMAC, payload/idempotencia, outbox, cálculo geocerca, API, enums,
manifests, RBAC, salud/lifecycle, arquitectura biométrica y contratos SYBI/Fortia
sin cambios productivos en este diff. Sólo se añadió lectura del recibo local
en la etapa UX previa; no se reescribió el protocolo de sincronización.
Los cambios GPS autorizados se limitan al timeout independiente, exigencia de
fix fresco y diagnóstico descritos; se conserva el proveedor Capacitor normal.
mobile/.env.local conserva su hash previo; no se cambió API URL ni secretos.
ASISTENCIAS_FORTIA limpio. Escaneo del diff/archivos nuevos sin coincidencias de
secretos configurados ni archivos prohibidos versionables.

Debug mantiene HTTP/mixed content exclusivamente para demo local. Fuente release
niega cleartext y mixed content, y conserva gate HTTPS/firma. No se generó release.
La APK está ignorada por Git; no se incorpora ni distribuye como producción.

SQLITE SECURITY: encrypted=false / no-encryption confirmado. HIGH para datos
productivos: requiere decisión formal de cifrado, migración y gestión de llaves
antes de producción. En esta demo sólo se capturó el empleado sintético autorizado.
Copias de diagnóstico y capturas permanecen privadas en el directorio temporal,
fuera del repositorio; requieren política de retención, no publicación en Git.
Riesgos MEDIUM: estabilidad de la LAN durante la demo y falla heredada OnPrem.
LOW: nombre comercial PRODUCT_DISPLAY_NAME pendiente, título móvil truncado y
warnings del build. No se tradujo ni rediseñó arbitrariamente el producto.

APK debug SHA-256:
F6D314917EB7AAE2A556259340A9B13EB30CFA51D188C5EEAC9FF9F9C2F27D03.

FINAL: READY_FOR_LIVE_DEMO.
La validación corresponde a esta instalación local y estos gates, no a producción
ni a recepción GPS garantizada en todo lugar.

## Inventario del diff desde Fase 10

Incluye trabajo Phase 11 preexistente conservado, no sólo esta ronda.

Archivos creados:

- `app/Console/Commands/VendingDemoResetCommand.php`
- `docs/demo/final-demo-validation.md`
- `docs/mobile/offline-gps-validation.md`
- `mobile/android/app/src/test/java/com/medicalife/vendingattendance/MainActivityTest.java`
- `mobile/src/components/AttendanceResultCard.vue`
- `mobile/src/composables/useAttendanceReceipt.ts`
- `mobile/src/composables/useLocationProgress.ts`
- `mobile/src/presentation/attendanceResult.ts`
- `mobile/src/presentation/operationLabels.ts`
- `mobile/tests/unit/android-http-policy.spec.ts`
- `mobile/tests/unit/attendance-receipt-store.spec.ts`
- `mobile/tests/unit/attendance-receipt.spec.ts`
- `mobile/tests/unit/attendance-result.spec.ts`
- `mobile/tests/unit/gps-runtime-config.spec.ts`
- `mobile/tests/unit/location-progress.spec.ts`
- `mobile/tests/unit/location-service.spec.ts`
- `mobile/tests/unit/offline-gps-attendance.spec.ts`
- `tests/Feature/Vending/VendingDemoResetCommandTest.php`

Archivos modificados:

- `docs/demo/demo-checklist.md`
- `docs/demo/demo-walkthrough.md`
- `docs/mobile/android-build.md`
- `mobile/.env.example`
- `mobile/android/app/src/main/java/com/medicalife/vendingattendance/MainActivity.java`
- `mobile/capacitor.config.ts`
- `mobile/src/app/services.ts`
- `mobile/src/config/runtime.ts`
- `mobile/src/services/LocationService.ts`
- `mobile/src/storage/EdgeStore.ts`
- `mobile/src/storage/SqliteEdgeStore.ts`
- `mobile/src/views/AttendancePage.vue`
- `mobile/src/views/HomePage.vue`
- `mobile/src/vite-env.d.ts`
- `tests/Feature/DashboardExecutiveSummaryTest.php`
