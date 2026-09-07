# Fase 11 — GPS fresco sin dependencia de la API

Estado al 7 de septiembre de 2026 UTC: GPS offline, almacenamiento físico,
persistencia, reconexión e idempotencia comprobados en el HONOR 400.
FASE 11: PASS; validación funcional PASS y demo READY, sin aprobación productiva.
El cierre Git cuenta con autorización directa del usuario. Tras autorización
directa del usuario, la APK final completó Entrada y Salida online y el reset
se ejecutó dos veces sin cambiar las 63 tablas comparadas.

## Ensayo físico comprobado

Wi-Fi y datos móviles desactivados; ubicación activa y permiso preciso concedido.
ADB forward/reverse vacíos; ping a la API: Network is unreachable. Sin modo avión.
Se conservó ANDROID-DEMO-001, VM-DEMO-001 y Empleado Demo Uno (990001001).

| Evidencia | Resultado observado (7 de septiembre UTC) |
| --- | --- |
| Inicio GPS en logcat | 04:30:49.649 |
| Llamada nativa | 04:30:49.651; alta precisión, timeout 45000, maximumAge 0 |
| Timestamp original del fix | 04:30:50.036Z; posterior a la solicitud |
| Validación TypeScript completada | 04:30:50.893; 1244 ms desde inicio |
| Evento físico local | CHECK_IN, OUTSIDE, PENDING; asistencia total local 4 |
| Servidor durante aislamiento y reinicio | 8 filas, máximo id 8; sin recepción nueva |
| Reinicio forzado y reapertura sin red | 8 tablas idénticas; Secure Storage con mismo hash |
| Wi-Fi reactivado; datos siguen apagados | 04:37:38.051Z; ping posterior exitoso |
| Recepción automática | 04:37:44Z; id 9, STORED, AUTHORIZED, demora real 414 s |
| Cola después de reconectar | SYNCED, mismo UUID/payload; total servidor 9 |
| Reenvío deliberado HTTP autenticado | 200, DUPLICATE; una fila, evento servidor intacto |
| Geocerca | Edge OUTSIDE / servidor OUTSIDE; no se movió ni amplió |

CDMX corresponde al día anterior, seis horas menos. Se midió la duración con
timestamps de logcat del PID objetivo; no se vació logcat porque la protección
rechazó esa operación. No se publican UUID, coordenadas ni credenciales.
La comparación local usó copias privadas temporales de SQLite DEMO, sin modificar
la base del teléfono ni descargar Secure Storage. No se incorporan a Git.
Las tablas comparadas fueron device_state, machine_state, geofence_state,
employees, employee_assignments, manifest_state, attendance_events y sync_outbox.

La APK posterior con mensajes de espera y etiquetas españolas se instaló mediante
install -r; SQLite y Secure Storage fueron idénticos antes/después de instalar,
antes del arranque. Arrancó con identidad y empleados conservados.
El bloqueo inicial de la captura fue resuelto mediante autorización directa.
La APK final completó CHECK_IN (id 10) y CHECK_OUT (id 11): ambos STORED,
AUTHORIZED y OUTSIDE/OUTSIDE. GPS: 2419 y 2398 ms; demora servidor: 4 y 3 s.
En ambos casos la UI cambió de pendiente a «Asistencia registrada correctamente»
tras leer su recibo individual del outbox. Sin nuevas copias de SQLite:
la protección rechazó esa extracción y se utilizó el recibo nativo más la
consulta acotada del servidor. No se exportaron payloads ni Secure Storage.

Conclusión: el fallo histórico genérico no se reprodujo con la configuración
actual. No hay evidencia que justifique fallback LocationManager/GNSS, aceptar
caché o aumentar nuevamente el timeout. No se atribuye retroactivamente la
falla original a Fused, permisos o a un timeout sin su código nativo original.

## Evidencia y límites del diagnóstico

- El código anterior fijaba GPS en 15 000 ms y maximumAge en 5 000 ms.
  HTTP tenía su propia configuración de 15 000 ms: coincidían numéricamente,
  pero no compartían la misma variable.
- El mensaje físico reportado, «No fue posible obtener la ubicación», corresponde
  a la clasificación genérica GPS_UNAVAILABLE. Por sí solo no demuestra que el
  origen fuera un timeout, ni que ampliarlo resuelva el comportamiento del teléfono.
- AttendanceCaptureService obtiene configuración local y ubicación, evalúa la
  geocerca y confirma la transacción local antes de intentar sincronizar desde
  AttendancePage. No se requiere una consulta API para crear la asistencia.
- Se inspeccionó @capacitor/geolocation 7.1.8: Android utiliza
  iongeolocation-android 1.0.0 y Google Play Services Location 21.3.0. El plugin
  transmite timeout, enableHighAccuracy y maximumAge al controlador nativo.
- El helper nativo usa FusedLocationProviderClient.getCurrentLocation con
  setMaxUpdateAgeMillis y setDurationMillis. No se añadió un proveedor alternativo,
  dependencia, actualización del plugin ni fallback a una ubicación anterior.

Referencias primarias de la versión inspeccionada:

- [Helper Android 1.0.0](https://github.com/ionic-team/ion-android-geolocation/blob/1.0.0/src/main/kotlin/io/ionic/libs/iongeolocationlib/controller/IONGLOCServiceHelper.kt).
- [Contrato de Google CurrentLocationRequest.Builder](https://developers.google.com/android/reference/com/google/android/gms/location/CurrentLocationRequest.Builder).

Google define máximo de antigüedad 0 como solicitud de una ubicación nueva, sin
ubicaciones históricas. También documenta que el proveedor puede limitar
internamente la duración aproximadamente a 30 segundos. Por tanto, configurar
45 segundos es un límite solicitado y de la aplicación, no una garantía de que
el proveedor mantenga la solicitud abierta durante los 45 segundos.
La disponibilidad GNSS del sistema no demuestra por sí sola que Fused Location
entregue una posición a esta aplicación sin red; el ensayo anterior sí comprobó
una respuesta fresca en este equipo, no una garantía universal de recepción.

## Configuración y validación

- VITE_GPS_TIMEOUT_MS: 45 000 ms por defecto; sólo enteros entre 15 000 y 90 000.
  Valores vacíos, no numéricos o fuera del rango fallan explícitamente.
- VITE_HTTP_TIMEOUT_MS: default de 15 000 ms y comportamiento anterior intactos.
- Sólo se actualiza .env.example; no se modifican entornos locales ni API URL.
- Geolocation.getCurrentPosition conserva enableHighAccuracy: true y ahora usa
  maximumAge: 0. No se consulta una ubicación last-known ni se reintenta con caché.
- Como segunda defensa, el timestamp debe ser finito y estar entre el inicio de
  la solicitud GPS y su recepción. Una posición anterior, futura o sin timestamp
  se rechaza, nunca se sustituye por Date.now(). Esto es deliberadamente estricto:
  un cambio de reloj o una respuesta cacheada del proveedor puede causar rechazo.
- Un deadline de la aplicación con reloj monotónico cubre proveedores que ignoren
  el timeout. No incluye el tiempo del diálogo de permisos. Una respuesta tardía
  nunca produce una asistencia y el temporizador JS se limpia al finalizar.
- getCurrentPosition no expone cancelación de su solicitud nativa. El deadline
  descarta su resultado tardío, pero no afirma cancelar el trabajo nativo.
  No se crea un watch ni un proceso de captura en segundo plano.
- Los valores GPS originales y la política de precisión/geocerca se conservan.
  No se añade un umbral diferente ni se convierte UNCERTAIN en INSIDE.
- Timeout produce GPS_TIMEOUT internamente y el texto amigable solicitado.
  Ubicación desactivada, permiso denegado y evidencia inválida siguen siendo
  errores distintos; no se presentan códigos internos ni trazas al empleado.

## Diagnóstico seguro

Sólo en deploymentMode development se emiten GPS_CAPTURE_STARTED,
GPS_CAPTURE_SUCCESS y GPS_CAPTURE_TIMEOUT, con elapsed_ms. No se emiten
coordenadas, identificadores, precisión, empleado, credenciales, HMAC ni
excepciones nativas. No se añade almacenamiento ni historial de telemetría.
Pilot/production no habilitan estos mensajes. Las políticas Android debug HTTP
y release HTTPS no se modifican.

Se serializa elapsed_ms como JSON para que logcat no lo reduzca a [object Object].
La UI muestra «Obteniendo ubicación…» y, tras 10 segundos, explica la búsqueda
de señal; ese temporizador sólo cambia presentación y se limpia al terminar o
destruir la vista. No cambia timeout, petición nativa ni sincronización.

## Cobertura automatizada

- Default, límites y validación de GPS; independencia respecto de HTTP.
- Permiso preciso, alta precisión, maximumAge 0, timestamps frescos y deadline.
- Timeout real del mock nativo y ausencia de respuesta: ninguna escritura de
  asistencia/outbox; respuesta posterior al timeout descartada.
- Fix válido con conectividad OFFLINE: evento y PENDING en la misma transacción
  local, sin llamada API. Reconexión: mismo UUID/payload, confirmación SYNCED.
- Red ONLINE con servidor inaccesible: evidencia válida permanece PENDING.
- Coordenadas inválidas no se persisten; precisión insuficiente mantiene UNCERTAIN.
- Servicios de captura, geocerca, sincronización y métodos de outbox reales,
  usando dobles de los límites nativos GPS/SQLite y API. Estas pruebas no
  certifican el sensor GNSS, SQLite físico ni el teléfono.

## Retest físico requerido

1. Instalar la APK debug por actualización, conservando datos e identidad.
   No desinstalar, reprovisionar, limpiar SQLite ni Secure Storage.
2. Mantener ubicación y permiso preciso activos; desactivar Wi-Fi y datos.
   No desactivar la ubicación como parte de la prueba offline.
3. Con un empleado de prueba vigente, solicitar Entrada/Salida en un lugar con
   buena recepción GPS. No usar coordenadas simuladas ni ubicaciones anteriores.
4. Confirmar que la evidencia nueva permite guardar localmente y la UI informa
   envío pendiente. La geocerca puede seguir siendo OUTSIDE o UNCERTAIN:
   no se cambia para aparentar éxito.
5. Restaurar red y comprobar confirmación del mismo evento, sin duplicación.
6. Si vence el plazo, verificar el mensaje específico y ausencia de una nueva
   asistencia/outbox. Registrar sólo categorías y tiempos del diagnóstico.

La captura física y sincronización del ensayo documentado están comprobadas.
La regresión online de la APK final también está comprobada. No presentar las
pruebas simuladas como evidencia física ni extrapolar estos tiempos a otros
lugares, equipos o condiciones de recepción.
