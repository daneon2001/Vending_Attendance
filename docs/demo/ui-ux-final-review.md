# Fase 12 — revisión final UI/UX de demo

Fecha: 7 de septiembre de 2026 (CDMX). Baseline:
`vending-demo-preflight-pass`, `3e97c91aa1991240c4fe1888a7ff5e898c7bb64e`.
Estado: **PASS — gates de cierre UI/UX comprobados**. Sin push ni deploy.
No equivale a aprobación productiva o a certificación integral de accesibilidad.

## Auditoría de cierre formal — 7 de septiembre de 2026

El encargo de cierre autoriza el commit `feat(ux): finalize vending demo interface`,
tag `vending-phase-12-ux-pass` y posteriormente la rama `phase/13-support-incidents`,
sólo después de satisfacer los gates. Fase 13 no se implementa en este checkpoint.
Las secciones inferiores son evidencia histórica, no el estado actual del cierre.

### Inventario exacto revisado

Se revisó el contenido completo de los 25 modificados y 4 no versionados contra
`vending-demo-preflight-pass`: 18 archivos UI/presentación, 8 tests y 3 documentos.
M = modificado; A = no versionado al comenzar. Cada candidato está justificado:

| Estado | Archivo | Clasificación | Justificación |
| --- | --- | --- | --- |
| M | docs/demo/demo-checklist.md | PHASE12_DOCUMENTATION | Checklist y límites de evidencia |
| M | docs/demo/demo-walkthrough.md | PHASE12_DOCUMENTATION | Recorrido del operador, sin nuevos flujos |
| A | docs/demo/ui-ux-final-review.md | PHASE12_DOCUMENTATION | Evidencia, inventario y decisión de cierre |
| M | mobile/src/App.vue | PHASE12_EXPECTED | Layout, foco, safe-area y controles táctiles |
| M | mobile/src/components/AttendanceResultCard.vue | PHASE12_EXPECTED | Recibo, español, hora y geocerca neutral |
| A | mobile/src/components/TerminalStatus.vue | PHASE12_EXPECTED | Conexión y pendientes reales, sin inferir recibos |
| M | mobile/src/composables/useLocationProgress.ts | PHASE12_EXPECTED | Sólo texto de demora GPS |
| M | mobile/src/main.ts | PHASE12_EXPECTED | Importación de tema; inicialización intacta |
| M | mobile/src/presentation/attendanceResult.ts | PHASE12_EXPECTED | Texto por estado persistido, hora local |
| M | mobile/src/theme/variables.css | PHASE12_EXPECTED | Paleta y contraste |
| M | mobile/src/views/AttendancePage.vue | PHASE12_EXPECTED | Jerarquía y aviso busy; handler sin cambios |
| M | mobile/src/views/HomePage.vue | PHASE12_EXPECTED | Búsqueda, Limpiar y detalle colapsado |
| M | mobile/src/views/ProvisioningPage.vue | PHASE12_EXPECTED | Sólo etiquetas; provisión intacta |
| M | mobile/src/views/StartupErrorPage.vue | PHASE12_EXPECTED | Sólo mensajes de recuperación |
| M | mobile/tests/unit/attendance-receipt-store.spec.ts | TEST | Respuesta real simulada antes de confirmar |
| M | mobile/tests/unit/attendance-receipt.spec.ts | TEST | No confundir red/errores con recibo individual |
| M | mobile/tests/unit/attendance-result.spec.ts | TEST | Estados, datos ocultos, enums y copy neutral |
| M | mobile/tests/unit/location-progress.spec.ts | TEST | Texto, tiempo fijo y timers intactos |
| A | mobile/tests/unit/terminal-ux.spec.ts | TEST | Estado, responsive estructural y contraste |
| M | resources/js/Pages/Employees/VendingCatalog.vue | PHASE12_EXPECTED | Fuente de prueba y No aplica a Fortia |
| M | resources/js/Pages/Settings/Audit/Index.vue | PHASE12_EXPECTED | Labels/tabla/detalle; sin cambiar acciones de auditoría |
| M | resources/js/Pages/VendingFleet/Dashboard.vue | PHASE12_EXPECTED | Explicación del KPI, mismo valor backend |
| M | resources/js/Pages/VendingFleet/Devices.vue | PHASE12_EXPECTED | Conexión reportada, incidencia, agregado backend |
| M | resources/js/Pages/VendingMachines/Index.vue | PHASE12_EXPECTED | Resumen fuente, administración SYBI preservada |
| M | resources/js/presentation/audit.js | PHASE12_EXPECTED | Mapping seguro del tipo de elemento |
| M | resources/js/presentation/navigation.js | PHASE12_EXPECTED | Reagrupación, mismas entradas y permisos |
| A | tests/Frontend/demoPolish.test.js | TEST | SSR de roles, recibos de props y correcciones |
| M | tests/Frontend/externalVisualReview.test.js | TEST | Ajustes a presentación aprobada, datos intactos |
| M | tests/Frontend/presentation.test.js | TEST | Labels y acciones sólo en su pantalla autorizada |

BUILD/GENERATED: 0; SENSITIVE: 0; UNEXPECTED: 0. Sin .env, credenciales,
tokens, contraseñas, llaves, runtime DB, dumps, logs, APK, screenshots ni assets
generados entre candidatos. Referencias a nombres de variables/diagnósticos en
tests/docs no contienen sus valores. Los fixtures técnicos son sintéticos.
Los builds locales permanecen ignorados, fuera del commit.

### Gates automatizados del cierre

Ejecutados en el orden solicitado: frontend 71 PASS; mobile 188 PASS;
Android native 4 PASS (2026-09-07T16:28:25Z); Laravel Vending 172 PASS /
1185 assertions; web build PASS; mobile build PASS; cap sync PASS;
assembleDebug PASS; Pint scoped/dirty PASS (0 PHP); diff check PASS;
suite completa 548 PASS / 1 FAIL (4212 assertions, 38.83 s).
La única falla es OnPremDiagnosticsCommandTest, la misma del baseline aprobado.
No se corrigió incidentalmente. Regresiones nuevas detectadas: 0.

630 archivos funcionales protegidos siguen idénticos. No hay diff de backend,
modelos, middleware, migraciones, contratos, salud, alertas, seguridad ni servicios
móviles. .env y mobile/.env.local sin cambios durante el cierre. Los cambios en
Provisioning y LocationProgress son exclusivamente copy, no semántica. Fortia clean.

### Evidencia visual del cierre

WEB: **EXTERNAL_VISUAL_REVIEW_PREVIOUSLY_COMPLETED**, confirmado por el usuario.
Se revisaron implementación y SSR de Resumen, Dispositivos, Máquinas, Empleados y
SYBI. No se declara una nueva sesión de navegador ni una medición responsive web
que no se realizó. Su ausencia no bloquea el checkpoint según el encargo.

APK: ADB disponible, HONOR desbloqueado, app accesible. APK instalada SHA-256
`97B3A81AE75B4CB28E662F12EA0B2E2EC9640028DB452421579B85B9C0E5D4F5`, igual a
la última APK revisada, posterior al source; no fue necesaria otra instalación.
HOME ONLINE, cero pendientes reales, nombres y detalle cerrado comprobados.
Empleado seleccionado y ambos botones visibles/habilitados comprobados.
«Limpiar» en una sola línea con teclado, foco y filtrado correctos; al pulsarlo
se restauró la lista. Capturas locales: checkpoint-home, checkpoint-keyboard,
checkpoint-employee; no se incluyen imágenes en Git.

La revisión automática de seguridad detuvo una primera propuesta antes de
ejecutarla. Después, el usuario autorizó explícitamente una única Salida DEMO
de 990001001 en VM-DEMO-001 / ANDROID-DEMO-001, incluyendo desconectar y restaurar
Wi-Fi y datos móviles. No se eludió la denegación ni se repitió la captura.

Antes de esa acción se encontró una Entrada de las 10:41:24 CDMX, id 18, creada
fuera de las acciones del agente. Su recibo real mostraba «Asistencia registrada»,
«Sincronizada correctamente», «Fuera de la zona permitida», distancia 5 km y
«Tu ubicación está fuera de la zona asignada». Servidor: STORED. Los 17 eventos
anteriores conservaban su digest. No se generó otra Entrada para la revisión.

La única captura autorizada del agente fue la Salida de las **10:43:42 CDMX /
16:43:42 UTC**. Sin Wi-Fi/datos, el recibo mostró «Asistencia guardada», «Salida»,
«Se enviará automáticamente al recuperar conexión» y «No fue posible confirmar
tu ubicación». El servidor seguía con 18 eventos. Se restauraron ambos canales
a su estado inicial habilitado, y el mismo recibo cambió a «Asistencia registrada»
y «Sincronizada correctamente» sin otra pulsación. El servidor recibió el id 19
a las **16:43:52 UTC**, CHECK_OUT / STORED / VM-DEMO-001 / ANDROID-DEMO-001 /
990001001. Edge y servidor conservaron UNCERTAIN: no se falseó GPS ni geocerca.

Total final: 19 eventos; sólo uno añadido por esta captura. Digest SHA-256 de
los 18 anteriores, idéntico antes y después:
`cf9ed1521bea8bd30377126b16b8f5c919324fc463b3ef1db76395d3bc0957a7`.
Ambos botones quedaron habilitados tras guardar y tras confirmar. HOME final:
En línea, 0 asistencias pendientes, detalle de terminal cerrado. Preflight posterior:
PASS, heartbeat 65 s, configuración 2/2, empleados 5/5, outbox 0, HIGH 0, MEDIUM 2.
No hubo uninstall, clear-data, reprovision, reset ni modificación del historial.
Capturas locales adicionales: checkpoint-existing-online, checkpoint-offline,
checkpoint-final-synced y checkpoint-home-after; fuera de Git.

### Resultado final comprobado

| Área | Resultado y alcance |
| --- | --- |
| APK UX | PASS: HOME, empleado, recibo online/offline y Limpiar físicos en HONOR |
| WEB UX | PASS: revisión externa previamente completada, implementación y SSR finales |
| Spanish | PASS en presentación revisada; marca comercial y fuente SYBI no se alteran |
| Responsive | HONOR con teclado comprobado; web por revisión externa, no nueva medición local |
| Attendance result | PASS: confirmación real, sin inferirla de ONLINE ni del contador global |
| Offline result | PASS: pendiente físico y posterior confirmación del mismo evento |
| Geofence presentation | PASS: OUTSIDE neutral y UNCERTAIN observado, sin cambiar valores |
| Button state | PASS: busy cubierto por tests; botones habilitados físicamente al terminar |
| Clean button | PASS: una línea con teclado; limpiar restaura la lista |
| Dashboard KPI | PASS: cuenta salud ONLINE; el caso 0 se explicó por RECENT_NETWORK/DEGRADED |
| Device health presentation | PASS: red reportada separada de incidencia, sin recalcular salud |
| Machines/SYBI | PASS: resumen de última ejecución; rutas y acciones preservadas |
| Employees | PASS: No aplica a Fortia sólo DEMO/MANUAL sin fecha; fechas reales intactas |
| SYBI encoding | SOURCE_ISSUE_UNCHANGED; sin recodificar ni modificar fuente |
| Demo preflight | PASS antes y después del ensayo físico |
| Security / functional freeze | PASS: sin secretos/artefactos; 630 archivos protegidos intactos |
| Tests/build | PASS salvo OnPremDiagnosticsCommandTest heredado; 0 regresiones nuevas |

Los gates permiten el checkpoint `vending-phase-12-ux-pass` con únicamente los
29 archivos inventariados. La rama `phase/13-support-incidents` debe partir de ese
tag después de confirmar el working tree limpio; no incluye implementación de
Fase 13. El hash de entrega se obtiene del tag, sin alterar tags anteriores.

Accesibilidad pendiente: TalkBack integral, recorrido de teclado web y auditoría
WCAG completa. Se mantienen advertencias conocidas de build, SOURCE_ENCODING_ISSUE,
PRODUCT_DISPLAY_NAME pendiente y gates productivos fuera de alcance.

## Histórico — ronda final acotada posterior a revisión externa

Esta sección conserva el estado de la ronda anterior; sus pendientes fueron
reevaluados en la auditoría de cierre superior, que es el estado actual.
La revisión externa de APK/web motivó aquella ronda. No se reinició el diseño
ni se modificaron reglas de negocio.

### Investigación y correcciones

- Recibo: `SqliteEdgeStore.getAttendanceReceipt` lee status/error del outbox por
  UUID. `applyOutboxResults` sólo marca SYNCED ante STORED/DUPLICATE. El composable
  existente relee el recibo ante notificaciones y al terminar el intento inmediato,
  con protección contra lecturas tardías. No se modificó ninguno de ellos.
  Los eventos previos 14–17 ya estaban STORED en servidor; su recepción tardó
  respectivamente 5, 3, 1 y 3 segundos. Eso no prueba cuál era el recibo local
  durante una captura externa sin timestamp. No se demostró un SYNCED persistido
  presentado como PENDING. Sí se demostró en código que SYNCING compartía el
  mensaje de falta de conexión: ahora muestra «Enviando asistencia. Esperando
  confirmación del servidor». SYNCED muestra «Asistencia registrada» y
  «Sincronizada correctamente»; PENDING, «Asistencia guardada» y «Se enviará
  automáticamente al recuperar conexión». REJECTED mantiene razones permitidas
  y tono de error; null no inventa confirmación. Red ONLINE no interviene.
- OUTSIDE: conserva advertencia, distancia y «Fuera de la zona permitida».
  Se añade solamente «Tu ubicación está fuera de la zona asignada».
  `VendingAttendanceReceiverService` almacena la evidencia geográfica y devuelve
  STORED sin convertir OUTSIDE por sí solo en rechazo. No se usa «autorizado»
  ni «conservado para su validación»: no se demostró un flujo de aprobación que
  permita prometerlo. Payload y cálculos intactos.
- Botones: el único binding disabled es `busy`; abarca captura GPS y el intento
  inmediato existente de sincronización y se libera en `finally`, también ante
  error. No es una regla que prohíba una segunda asistencia. Sólo se agregó un
  aviso visible durante la espera posterior a GPS. Handler/guard sin cambios.
- Limpiar: `flex-shrink: 0; white-space: nowrap`, conservando fuente heredada,
  altura táctil, v-model, filtrado y acción de limpieza.
- Dashboard: reproducido a las **09:20:44 CDMX / 15:20:44 UTC**. Heartbeat
  09:20:26 (18 s), red ONLINE, error NETWORK 09:12:35, salud DEGRADED con razón
  única RECENT_NETWORK; KPI online=0 y degraded=1. Ventanas reales: demora 180 s,
  offline 600 s, error reciente 30 min. El KPI cuenta exclusivamente salud ONLINE
  (`VendingFleetOperationsService`), no network_state. Es caso A, comportamiento
  correcto del cálculo; no bug de cálculo ni contador corregido/fabricado.
  Se aclaró sólo el texto del KPI. El controller calcula la proyección en cada
  GET sin cache explícito; Vue usa props sin polling, y muestra generated_at.
  Una pestaña sin recargar conserva su snapshot. Falta hora de la captura externa
  para atribuir retrospectivamente su caso concreto; no se afirmó caché defectuosa.
- Dispositivos: mantiene el badge derivado; añade «Red reportada: En línea»
  y «Incidencia reciente de red» cuando RECENT_NETWORK viene del backend.
  También ante heartbeat expirado la red se califica como reportada, nunca como
  una comprobación en vivo. Sin cálculo duplicado ni cambio de enums.
- Máquinas: resumen compacto de fuente con fecha, listas, incompletas/conflictos
  de latest_run; aclaración de que son resultados de esa ejecución, no conteos
  actuales. Sin datos muestra Sin información, no cero. Detalle y Sincronizar
  permanecen en Catálogo SYBI con mismo permiso, ruta y handler. Tabs intactos.
- Empleados: los cinco DEMO tienen source_synced_at=null. Fortia real usa source
  FORTIA; mock usa DEMO y puede tener fecha de sincronización. Por eso no se
  sustituye toda fecha DEMO: únicamente DEMO/MANUAL sin fecha muestra «No aplica
  a Fortia». Fechas existentes y estados FORTIA/LEGACY permanecen intactos.
- SYBI encoding: **SOURCE_ISSUE_UNCHANGED**. La evidencia aprobada en
  `import-sybi-evidence.md` demuestra mojibake previo al mapper. Revertir bytes
  no demuestra la intención del nombre ni distingue universalmente un literal
  legítimo. No se añadió un reparador heurístico ni se cambió fuente/DB/contrato.
  Las pruebas Unicode/SSR existentes siguen conservando textos correctos y fuente.

### Validación de esta ronda

| Control | Resultado |
| --- | --- |
| DEMO PREFLIGHT BEFORE | PASS: heartbeat 43 s, ONLINE, manifests 2/2 y 5/5, outbox 0, HIGH 0 |
| DEMO PREFLIGHT AFTER | PASS al consultar después de instalar: heartbeat 172 s, ONLINE, manifests 2/2 y 5/5, outbox 0, HIGH 0; snapshot, no promesa de vigencia posterior |
| Frontend | 71 PASS |
| Mobile | 188 PASS, 21 archivos |
| Laravel Vending | 172 PASS, 1185 assertions |
| Laravel completa, al concluir cambios de código | 548 PASS / 1 FAIL: OnPremDiagnosticsCommandTest, misma falla del baseline aprobado |
| Android native | 4 PASS / 0 FAIL; ejecución 2026-09-07T15:29:31Z |
| Builds web / mobile / cap sync / assembleDebug | PASS / PASS / PASS / PASS |
| Pint scoped | PASS, `--test --dirty`: 0 archivos PHP modificados |
| git diff --check | PASS |
| Regresiones nuevas automatizadas | 0 |

La prueba de recibo usa el servicio de sincronización y store reales con IO
simulado: espera respuesta individual STORED/DUPLICATE/REJECTED y comprueba que
un fallo posterior de manifest no borra la confirmación. Las pruebas de
presentación prohíben mensajes offline para SYNCED, preservan enums/payload y
comprueban la explicación neutral de OUTSIDE. SSR no sustituye capturas web.

Warnings previos de Browserslist, Tailwind, CSS Ionic y chunks grandes permanecen;
sin cambios de dependencias ni refactors adicionales.

### Instalación y pendientes físicos

Instalación adicional **1 de 2** mediante `adb install -r`: Success.
APK SHA-256: `97B3A81AE75B4CB28E662F12EA0B2E2EC9640028DB452421579B85B9C0E5D4F5`.
Sin uninstall, borrado, reprovisionamiento ni cambio de identidad.
El teléfono quedó bloqueado; se pidió al operador desbloquear y abrir la app.
No se atribuye la pantalla apagada/bloqueada a un fallo de la aplicación.
Revisión posterior de HOME/EMPLOYEE/Entrada/Salida/recibos/teclado pendiente.
No se han creado eventos en esta ronda: a las 15:35:14 UTC continúan 17 eventos;
el hash conjunto de sus registros originales coincide con el previo. Los cuatro
eventos añadidos entre la ronda inicial y ésta pertenecían a la revisión externa.

| Evidencia solicitada | Estado y alcance exacto |
| --- | --- |
| ATTENDANCE RECEIPT UX | PASS automatizado; retest físico pendiente, no certificado |
| OUTSIDE UX | PASS automatizado y semántica inspeccionada; retest físico pendiente |
| BUTTON STATE | PASS de código/guard y tests estructurales; retest físico pendiente |
| CLEAN BUTTON | PASS de CSS/test; falta captura real con teclado para certificar |
| DASHBOARD CONNECTED KPI | EXPLAINED; cálculo correcto reproducido, sin alterar números |
| RECENT INCIDENT UX | PASS SSR; nueva captura web pendiente |
| MACHINES/SYBI DUPLICATION | PASS SSR; nueva captura web pendiente |
| DEMO EMPLOYEE SYNC LABEL | PASS SSR; fuente/fecha verificadas, datos intactos |
| SYBI ENCODING | SOURCE_ISSUE_UNCHANGED |

No se consumió la segunda instalación disponible. Para continuar, el operador
debe desbloquear HONOR y dejar Vending Attendance abierta; no hace falta borrar,
reprovisionar ni reinstalar de nuevo. Se conserva el bloqueo de certificación
física, no se declara un defecto técnico no demostrado.

### Freeze y alcance

630 archivos funcionales protegidos conservan exactamente su hash conjunto:
`23CD9F7C589C7895C7F6D38B1DDE6802794E0034402F381635A1537E0EC522C8`.
Incluye app, config, DB/schema, rutas, servicios móviles, almacenamiento, APIs,
dominio, seguridad, composable de recibo, Android nativo, Capacitor y dependencias.
`.env` sigue idéntico. `mobile/.env.local` difiere de la ronda anterior, con
mtime **14:58:16 UTC**, anterior a esta ronda y a la revisión externa de eventos:
configuración LAN preexistente preservada, no editada ni restaurada por Codex.
No se publican sus contenidos. ASISTENCIAS_FORTIA permanece clean.
Los handlers completos de Home y captura de Attendance también coinciden con
el baseline. Revisión de los 29 paths acumulados: cero archivos prohibidos y
cero patrones de secretos encontrados; los cuatro nuevos pertenecen a la ronda
inicial. HEAD y tags no se han modificado.

Archivos ajustados en esta ronda (15; ninguno creado por esta ronda):

- mobile/src/views/HomePage.vue
- mobile/src/views/AttendancePage.vue
- mobile/src/components/AttendanceResultCard.vue
- mobile/src/presentation/attendanceResult.ts
- mobile/tests/unit/attendance-result.spec.ts
- mobile/tests/unit/attendance-receipt.spec.ts
- mobile/tests/unit/attendance-receipt-store.spec.ts
- mobile/tests/unit/terminal-ux.spec.ts (creado en la ronda previa)
- resources/js/Pages/VendingFleet/Dashboard.vue
- resources/js/Pages/VendingFleet/Devices.vue
- resources/js/Pages/VendingMachines/Index.vue
- resources/js/Pages/Employees/VendingCatalog.vue
- tests/Frontend/demoPolish.test.js (creado en la ronda previa)
- tests/Frontend/presentation.test.js
- docs/demo/ui-ux-final-review.md (creado en la ronda previa)

## Histórico — resultado y límites de la ronda inicial

| Control | Resultado |
| --- | --- |
| APK UX | PARTIAL: correcciones entregadas; Limpiar se parte en dos líneas con teclado |
| APK visual | EXTERNAL_REVIEW_REQUIRED para cierre estético; revisión física efectuada |
| APK español | PASS en textos operativos revisados; TalkBack completo pendiente |
| APK responsive | PASS en portrait HONOR y acciones principales; salvedad visual Limpiar |
| APK regresión funcional | NONE observada; GPS, recibos y reconexión reales comprobados |
| Web UX | PARTIAL; implementación/render aprobados, revisión visual externa pendiente |
| Web visual/responsive | EXTERNAL_VISUAL_REVIEW_REQUIRED; no navegador disponible |
| Web español | PASS en presentación de negocio revisada; códigos en detalle técnico |
| Demo preflight antes / después | PASS / PASS |
| Seguridad del cambio | PASS; freeze funcional conservado, producción no certificada |
| ASISTENCIAS_FORTIA | clean, sin modificaciones |

## Android: evidencia y correcciones

Dispositivo físico HONOR 400, serie AX3C026107002120, app
`com.medicalife.vendingattendance`, identidad ANDROID-DEMO-001 / VM-DEMO-001.
Pantalla 1264×2736, densidad 560, escala de fuente 1.0: aproximadamente 361 CSS px
de ancho. No se cambió tamaño, densidad, ubicación o configuración del servidor.

Antes: título truncado por el botón Sincronizar; seis tarjetas administrativas
empujaban empleados hacia el borde inferior. La segunda fila llegaba a la zona
de navegación Android. El inicio priorizaba versiones en vez del trabajador.

Después:

- Nombre del producto completo; conexión y pendientes reales compactos, con
  Sincronizar secundario. ONLINE nunca se presenta como recibo confirmado.
- Nombres destacados, número secundario, búsqueda y estados vacíos en español.
  Empleados antes del detalle de terminal, que inicia colapsado.
- Entrada/Salida ≥56 CSS px; filas de empleado ≥80 px. Conservados los dos
  handlers de captura, guards busy y selección/filtrado existentes.
- Spinner y mensaje GPS; explicación de demora a los 10 s sólo de presentación.
  No se modificó LocationService ni su presupuesto/algoritmo.
- Recibo: encabezado guardada/registrada/revisión, tipo, nombre, hora local.
  Sin UUID, coordenadas, precisión o códigos en la vista del empleado.
- OUTSIDE continúa como advertencia; REJECTED conserva mensaje seguro permitido;
  el título registrado depende exclusivamente del recibo individual SYNCED.
- Paleta sobria clara, barra superior oscura compatible con iconos Android,
  foco visible, espaciado y márgenes de seguridad. No se añadió una librería.
- La implementación Ionic del buscador trae etiquetas internas `search text`
  y `reset`. Se sustituyó sólo ese control por un input nativo etiquetado y un
  botón Limpiar en español, manteniendo el mismo v-model y computed de filtrado.
- Activación y recuperación: sólo textos; no se ejecutaron ni cambiaron sus flujos.

Se realizaron tres ciclos build/install/revisión. Primera revisión corrigió
campo truncado y contraste de iconos del sistema; tercera atendió las etiquetas
del buscador. Hallazgo residual real: Limpiar se divide como «Limpi / ar» con
teclado abierto, sin impedir su uso. No se hizo una cuarta iteración automática.

Pantallas observadas: inicio ONLINE/OFFLINE, empleado, Entrada/Salida, espera GPS,
resultado pendiente y confirmado, búsqueda vacía/sin coincidencias y filtrada
por número, limpieza, teclado y navegación de regreso. No hay overflow horizontal
ni recorte de las acciones principales en estas capturas. Las filas finales
se ubican aproximadamente entre y=1390 y y=1950; antes alcanzaban y=2698.

INSIDE, UNCERTAIN, REJECTED y fallos de almacenamiento se verifican mediante
tests, no mediante datos falsificados ni provocando corrupción del teléfono.
La espera larga GPS se prueba con reloj determinista; físicamente se observó
el spinner de espera inicial y después un resultado GPS válido.

## Eventos reales preservados

La autorización específica del encargo permite eventos sólo DEMO necesarios
para revisar resultados. Se verificó en DB source=DEMO antes de capturar.

| Evento nuevo | Captura UTC | Recepción UTC | Resultado |
| --- | --- | --- | --- |
| 12 · Entrada · 990001001 | 06:53:11 | 06:53:14 | Online; recibo confirmado; demora 3 s |
| 13 · Salida · 990001001 | 06:59:44 | 07:00:55 | Offline pendiente, luego envío automático; demora total 71 s |

Ambos del 07/09/2026, geocerca OUTSIDE en edge y servidor, servidor STORED.
Antes de reconectar, servidor continuaba con 12 filas y la Salida aún no estaba
recibida. Tras reconexión la misma tarjeta cambió a Asistencia registrada.
Total final 13; los 11 originales permanecen. No hubo reset, SQL de escritura,
uninstall, pm clear, reprovisionamiento ni exportación de SQLite/Secure Storage.
Wi-Fi quedó habilitada y datos móviles deshabilitados, iguales al estado inicial.

APK final instalada (tercera revisión), SHA-256:
`7514AF08D9B0C0E670D257F93D8C9E6D3C316B76BE4AC869B91762F0200996FB`.
El tercer ciclo sólo cambió el buscador; no se crearon más eventos para repetir
un resultado ya comprobado y cuyo componente no cambió.

## Web: auditoría y alcance implementado

| Pantalla | Decisión y evidencia |
| --- | --- |
| Login | Se conserva branding, labels y errores en español; render existente PASS |
| Navegación | Tres grupos para pilotos: Operación, Integraciones, Administración; mismas entradas, rutas, hashes, parámetros y reglas strict |
| Dashboard | Se conservan seis KPIs, lista real y secciones secundarias colapsadas; backend ya ordena HIGH antes de MEDIUM |
| Dispositivos | Siete columnas: máquina, dispositivo, estado operativo, última conexión, sincronización agregada real, pendientes, acciones |
| Detalle dispositivo | Versiones y los dos estados de manifest permanecen bajo Ver detalle; filtros avanzados colapsados, estado administrativo separado |
| Máquinas | Se conserva tabla de seis columnas y acceso al detalle/dispositivos, sin inventar agregados no presentes en el contrato |
| SYBI | Se preserva separación fuente/operación, estados amigables y diagnóstico colapsado; no se corrige mojibake de fuente |
| Empleados | Fortia mock ahora Fuente de prueba; permisos de importar/sincronizar/asignaciones intactos |
| Importación | Wizard de cinco pasos, preview/diff, paginación y confirmación intactos; tests incluidos, sin dataset real |
| Versiones | Se conserva presentación y administración condicionada por canManage |
| Auditoría de máquina | Mapping existente intacto, salvo helper adicional reutilizable para tipo de elemento |
| Auditoría administrativa | Cinco columnas, actividad y elemento amigables, sin ancho mínimo forzado de 72rem; códigos/IDs/descripción en detalle; conservación colapsada |

Pilotos Admin/Operator/Support/Viewer: render de navegación y acciones probado
con las matrices exactas del seeder. Ninguno recibe permiso nuevo de auditoría
administrativa; su render adicional se prueba con un fixture autorizado, no con
una elevación del rol piloto. No se ejecutó ninguna purga de auditoría.

Se buscó texto de enums/diagnósticos en resources/js y mobile/src. Se mantienen
sus valores internos y los option values; su presentación usa labels españoles.
Los módulos heredados ajenos al recorrido no se rediseñaron. La auditoría global
conserva herramientas técnicas de conservación detrás de una sección cerrada.

Browser runtime no disponible; lista de navegadores vacía. No se insistió ni se
simuló navegador. Pendiente revisión externa real 1920×1080 y 1366×768, cuatro
roles, navegación/foco/contraste/overflow. El SSR NO es certificación visual.

## Accesibilidad y performance

Comprobado: botones con texto, superficies táctiles, foco visible del buscador,
input/limpieza etiquetados en español, alert/status/live regions en código,
estado no dependiente sólo del color. Siete pares de colores de texto/fondo
verificados por test con contraste ≥4.5:1, no una certificación WCAG integral.

uiautomator reconoce el nombre Limpiar búsqueda de empleados; no expuso nombre
para el campo vacío incluso siendo un input HTML etiquetado. Falta prueba hablada
con TalkBack y recorrido de teclado web. No se afirma accesibilidad completa.
Sin dependencias nuevas, animaciones costosas ni cambios a consultas/servicios.
Selección y resultado respondieron físicamente sin espera perceptible añadida;
no se efectuó un benchmark formal de startup o render web.

## Validación automatizada

- Laravel: `php artisan test tests/Feature/Vending`: 172 PASS, 1,185 assertions.
- Web: `node --test` sobre todos los `tests/Frontend/*.test.js`: 66 PASS.
- Mobile: `npm test`: 185 PASS / 21 archivos.
- Web y mobile: `npm run build`: PASS. `npx cap sync android`: PASS.
- Android: assembleDebug PASS; testDebugUnitTest 4 PASS. Primera ejecución
  con --rerun-tasks; siguientes builds sin cambios nativos reutilizan resultados.
- Pint scoped: `php vendor/bin/pint --test --dirty`: PASS / 0 archivos PHP,
  porque no se modificó PHP. Sin formatear código ajeno al alcance.
- `git diff --check`: PASS.
- No se repitió la suite completa: referencia aprobada proporcionada por el
  usuario, 548 PASS / 1 FAIL heredado OnPremDiagnosticsCommandTest.

Warnings sin actualizar dependencias: Browserslist antiguo, configuración
Tailwind detectada durante build móvil, minificador host-context de Ionic,
chunks grandes y flatDir Gradle. Builds exitosos; no se ocultaron los avisos.

## Freeze y seguridad

Comparación antes/después: 630 archivos protegidos idénticos por SHA-256 agregado
(app/bootstrap/config/database/routes, Android nativo, servicios/storage/API/domain/
security/biometrics/runtime/router/config móviles, useAttendanceReceipt, Capacitor,
package manifests y lockfiles). Además, handlers funcionales de Home, Attendance,
Provisioning e inicialización main idénticos; main sólo deja de importar tema oscuro.
Todas las entradas de navegación son idénticas: únicamente se reagruparon.
`.env` y `mobile/.env.local` idénticos. Tags y HEAD conservados.

Debug HTTP permanece habilitado sólo por la configuración debug existente;
release cleartext=false y mixed content prohibido, sin relajación de seguridad.
No se modificaron health, lifecycle, RBAC, GPS, HMAC, attendance, manifests,
SYBI/Fortia, importación, reset ni preflights. ASISTENCIAS_FORTIA limpio.
Sin secretos, credenciales, APK, logs, dumps o archivos reales de importación
en el diff. Capturas sólo de datos DEMO, fuera del repositorio, para revisión local.

Preflight inicial: heartbeat 55 s, ONLINE, configuración 2/2, empleados 5/5,
pending 0, HIGH 0, MEDIUM 2. Final: heartbeat 4 s, mismos manifests, pending 0,
HIGH 0, MEDIUM 1. Las alertas evolucionaron con telemetría real; no se alteraron
datos ni umbrales para cumplir el chequeo.

## Archivos

Creados:

- mobile/src/components/TerminalStatus.vue
- mobile/tests/unit/terminal-ux.spec.ts
- tests/Frontend/demoPolish.test.js
- docs/demo/ui-ux-final-review.md

Modificados:

- mobile/src/App.vue
- mobile/src/main.ts
- mobile/src/theme/variables.css
- mobile/src/views/HomePage.vue
- mobile/src/views/AttendancePage.vue
- mobile/src/views/ProvisioningPage.vue
- mobile/src/views/StartupErrorPage.vue
- mobile/src/components/AttendanceResultCard.vue
- mobile/src/presentation/attendanceResult.ts
- mobile/src/composables/useLocationProgress.ts
- mobile/tests/unit/attendance-result.spec.ts
- mobile/tests/unit/location-progress.spec.ts
- resources/js/presentation/navigation.js
- resources/js/presentation/audit.js
- resources/js/Pages/VendingFleet/Devices.vue
- resources/js/Pages/Employees/VendingCatalog.vue
- resources/js/Pages/Settings/Audit/Index.vue
- tests/Frontend/externalVisualReview.test.js
- tests/Frontend/presentation.test.js
- docs/demo/demo-walkthrough.md
- docs/demo/demo-checklist.md

## Riesgos y revisión externa

- HIGH: ninguno nuevo demostrado en el cambio; producción continúa fuera de
  alcance y conserva sus gates previos (incluido almacenamiento local del piloto).
- MEDIUM: visual web/responsive y TalkBack no certificados. Revisión externa obligatoria.
- LOW: Limpiar se divide en dos líneas con teclado; corregir después de observación/
  autorización de otra iteración. Warnings de build y PRODUCT_DISPLAY_NAME pendiente.

No declarar Fase 12 PASS hasta la revisión externa y resolución/aceptación del
hallazgo residual. No crear checkpoint en esta entrega.
