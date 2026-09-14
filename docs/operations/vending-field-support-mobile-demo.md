# Phase 13.6D.2 — Field Support online local

## Alcance y seguridad

Implementación local/DEMO. No habilita biometría, asistencia ni soporte offline completo.
El resolver productivo `User::authenticatedEmployee()` conserva sus reglas Fortia.
`FieldSupportActivityAccess` se construye explícitamente sólo en el adaptador Field Support;
no sustituye la vinculación global del contenedor.

La excepción exige local/testing, User 4 `pilot.support@example.test`, Employee 5
`990001005`, fuente DEMO, identidades activas y el FIELD_MOBILE activo del mismo
propietario. Sólo VM-DEMO-001 fuente DEMO y MAINTENANCE/REPAIR/COMPONENT_REPLACEMENT.
Conserva RBAC, asignación vigente y maintenance_allowed. Producción rechaza DEMO.

El transporte nativo reutiliza HTTPS, sesión humana expirable y Android Keystore.
No acepta identidad del actor desde el payload. Cada lectura de actividad y transición
firma un challenge ACTOR con hash canónico de operación (`FIELD_SUPPORT_ONLINE_V1`).
El backend consume el proof una sola vez, comprueba ownership/binding vivo y vuelve
a autorizar antes de transicionar. No hay HMAC vending ni credenciales nuevas.

START/COMPLETE reutilizan `SupportActivityService` y los recibos persistentes
`SupportOperations`. El recibo guarda el ActorContext verificado y referencia pública
de clave atómicamente con la transición; no almacena teléfono ni clave privada.
Un reintento con otro challenge y el mismo operation_uuid/contenido obtiene el recibo;
un cambio de contenido o dispositivo no puede reutilizarlo. El proof consumido nunca
es una autorización reutilizable. No se añade ni aplica una migration.

El panel web permite a Pilot Admin 2, con permisos existentes de soporte/identidad,
observar sólo estas actividades DEMO en local/testing. No concede crear, iniciar,
finalizar ni cancelar actividades mediante esta excepción de lectura.

## Ubicación y reintento

START obtiene configuración actual y GPS fresco mediante LocationService. Edge y
servidor reutilizan GeofenceValidationService. OUTSIDE/UNCERTAIN no habilitan START.
Sólo un recibo backend cambia el estado visible a confirmado.

`COMPLETE_LOCATION_POLICY = START_ONLY_V1`: COMPLETE conserva la regla actual sin GPS
nuevo. La finalización NO acredita ubicación física en ese momento.

Una solicitud incierta se conserva cifrada como intención de reintento manual,
separada por origen y dispositivo. No hay outbox nuevo, envío automático ni confirmación
optimista. Reiniciar recupera el estado del servidor y permite reintentar el mismo UUID.
No elimina SQLite, Secure Storage, sesión de terminal ni claves.

## Evidencia física aprobada — 10 de septiembre de 2026

- Autorización explícita en mensaje directo para geocerca, actividad, START, reinicio,
  COMPLETE y reintentos idempotentes. El bloqueo anterior de revisión de permisos
  quedó resuelto; no se reutilizó la muestra GPS diagnóstica anterior.
- Backup privado previo: 51,101 bytes; SHA256
  `03d729dca3c8c8642a9745e5f4855ede567b6b0f15f423c94dca23fc993c44ef`.
  Ubicación privada ignorada: `storage/framework/local-mysql/phase-13-6d2/preparation-backup.json`.
  Esquemas/filas afectados; nunca se incorpora a Git ni se restaura automáticamente.
- Assignment 8 intacto en esta continuación: Employee 5 → VM-DEMO-001;
  attendance_allowed=false, enrollment_allowed=false, maintenance_allowed=true.
- Geocerca anterior ID 1 / versión 1: geometría, UUID, radio, precisión, tolerancia,
  fuente y fecha de creación comprobados idénticos al respaldo. El workflow la
  marcó SUPERSEDED con fin de vigencia; no se borró ni sobrescribió su geometría.
- Nueva geocerca ID 4 / versión 2, fuente DEMO, etiqueta DEMO / LOCAL TEST.
  GPS NUEVO del HONOR: accuracy 12.753 m, timestamp validado; radio 50 m,
  tolerancia existente 10 m, precisión máxima existente 30 m.
  Publicación por MachineGeofenceService: 2026-09-10T16:35:46Z.
  Actor: system / explicitly_authorized_local_setup.
  Motivo auditado: "Phase 13.6D.2 physical Field Support demo".
- ACK real tras sincronización normal: configuración 4/4, empleados 6/6, SYNCED.
  No se editaron versiones/applied manualmente.
- Actividad ID 1, UUID `81687ac4-b490-436e-80a6-1e25939471e1`,
  MAINTENANCE, "Mantenimiento DEMO en sitio", Employee 5, máquina 1, sin ticket.
  Preparación mediante SupportActivityService bajo contexto de cuenta Pilot Support,
  auditada explícitamente como ejecución automatizada autorizada, NO como creación
  manual web. Estado inicial ASSIGNED, creada/asignada a las 16:38:11Z.
- Home mostró Mis actividades; lista y detalle reales respondieron HTTP 200 usando
  sesión humana conservada, challenge y firma del Android Keystore existente.
- START desde botón de la APK: GPS NUEVO accuracy 13.376 m; timestamp posterior al
  inicio de captura. EDGE INSIDE / SERVER INSIDE. HTTP 200 confirmado.
  IN_PROGRESS a las 16:45:17Z (10:45:17 CDMX), geofence_version=2.
- START repetido: nuevo challenge/firma, mismo operation_uuid y mismo contenido;
  HTTP 200 con recibo previo. Un solo evento started.
- Pilot Admin confirmó externamente web En progreso, identidad/máquina/zona y timeline.
  El operador aprobó Home, lista y detalle en el HONOR.
- Force-stop real: PID 13176 → PID 14058. Reapertura, lista y detalle firmados:
  la MISMA actividad sigue IN_PROGRESS. Mismo binding y fingerprint.
- COMPLETE desde botón de la APK: HTTP 200, COMPLETED a las 16:49:17Z
  (10:49:17 CDMX). Cero capturas GPS desde el reinicio.
  COMPLETE_LOCATION_POLICY=START_ONLY_V1; NO acredita ubicación al finalizar.
- COMPLETE repetido: nueva firma/challenge, mismo operation_uuid; HTTP 200 con
  recibo previo. Un solo evento completed.
- Timeline persistido: created, assigned, started, completed; cuatro eventos únicos.
  Dos recibos de transición con ActorContext, user=4, employee=5, device=1,
  deviceVerified=true, phoneVerified=false, LOCAL_SIMULATED, proofs consumidos.
- Operador confirmó externamente estado Completada y timeline final en web y HONOR.
  VISUAL REVIEW: PASS dentro de estas pantallas/escenario DEMO.
- Observadores temporales y forward ADB retirados. App conservada en detalle completado.
  Sin otro OTP, binding, ticket, asistencia ni SupportActivity.

## Build e instalación

Una APK intermedia rechazó correctamente la identidad humana porque el proceso de
build no recibió la URL HTTPS opcional. Se identificó mediante mensaje de la UX
antes de START, sin degradar a HTTP ni modificar TLS. Se reconstruyó pasando sólo:

```powershell
$env:VITE_FIELD_IDENTITY_BASE_URL='https://192.168.101.15:8443'
npm run build
npx cap sync android
$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
.\android\gradlew.bat -p android assembleDebug
```

No se modificó VITE_API_BASE_URL ni .env. Instalaciones exclusivamente adb install -r.
Sesión, SQLite, Secure Storage, Android Keystore y ANDROID-DEMO-001 preservados.

APK final: `mobile/android/app/build/outputs/apk/debug/app-debug.apk`,
29,964,606 bytes; SHA256
`48ce128fb003155be8882c31cf4c848d2da80a23011a221f841a0c9ba7297da6`.

## Rollback lógico preparado, NO ejecutado

La versión 1 conserva centro, radio y tolerancia; la versión 2 está activa.
Para restaurar posteriormente la geometría anterior, con autorización explícita:

1. Leer la geometría histórica de versión 1 y configuración vigente de VM-DEMO-001.
2. Crear **otra nueva versión** mediante MachineGeofenceService::create, copiando
   centro/radio/precisión/tolerancia de la histórica, fuente DEMO, razón de rollback,
   actor y expectedVersion vigente. Publicarla por el workflow normal.
3. No reactivar directamente una SUPERSEDED (el servicio lo prohíbe), no actualizar
   geometría con SQL y no borrar versiones ni evidencia de la prueba.
4. Esperar propagación normal y ACK físico. No editar versiones/applied manualmente.

El backup es evidencia de recuperación, no un script para truncar/restaurar tablas
enteras sobre datos nuevos. Cualquier recuperación debe preservar historia posterior.

## Validación final

- Backend Support + FieldIdentity + Vending: 391 PASS / 3,476 assertions, SQLite
  en memoria. Incluye 11 pruebas nuevas Field Support y las pruebas de autorización
  y User–Employee; no usa la DB local real como base de testing.
- Mobile: 291 PASS, 33 archivos.
- Frontend: 128 PASS.
- Android native: 7 PASS, cero errores/fallas.
- Build móvil, cap sync, assembleDebug: PASS.
- Pint scoped (8 archivos PHP) y git diff --check: PASS.
- Demo-preflight final: PASS, heartbeat 18 s, ONLINE, config 4/4, empleados 6/6,
  outbox 0, HIGH 0, MEDIUM 1 informativa.
- No migraciones, cambios Java/TLS/release, HMAC, attendance ni contratos en esta continuación.
- Hashes de users, employees, assignments y tickets idénticos a antes de la
  continuación; assignment 7 y SYBI 7 preservados. Cambios permitidos: geocerca
  nueva/versionado de configuración, una actividad/eventos/recibos y auditoría.
  La telemetría y los proofs propios se actualizaron por sus mecanismos normales.

## Resultado actual

```text
PHASE 13.6D.2: PASS (DEMO local, no certificación productiva)
ASSIGNMENT 8: PASS
GEOFENCE OLD VERSION: 1, geometría histórica preservada
GEOFENCE NEW VERSION: 2, ACTIVE
GPS CENTER ACCURACY: 12.753 m
GEOFENCE SOURCE: DEMO
ACTIVITY ID: 1
MOBILE LIST: PASS
DETAIL: PASS
GPS: PASS (START 13.376 m)
GEOFENCE EDGE: INSIDE
GEOFENCE SERVER: INSIDE
MATCH: PASS
START: PASS
STATE AFTER START: IN_PROGRESS
WEB REFLECTION START: PASS (confirmación externa)
RESTART PERSISTENCE: PASS
COMPLETE: PASS
COMPLETE LOCATION POLICY: START_ONLY_V1
STATE FINAL: COMPLETED
WEB REFLECTION FINAL: PASS (confirmación externa)
TIMELINE: PASS
IDEMPOTENCY: PASS (START y COMPLETE reales repetidos)
FIELD_MOBILE: ACTIVE, mismo binding/clave
ATTENDANCE: 0
VENDING ATTENDANCE EVENTS: 19
SUPPORT ACTIVITIES: 1
EMPLOYEES: 2507
USERS: 5
ASSIGNMENTS: 8
SYBI 7: PRESERVED — DRAFT, sin geofence ni Device, assignment 7 intacto
ASISTENCIAS_FORTIA: clean
PHASE 14: PRESERVED
PHONE: LOCAL_SIMULATED / phoneVerified=false
BIOMETRY: NOT_IMPLEMENTED
TESTS: Backend 391 / Mobile 291 / Frontend 128 / Native 7 PASS
BUILD: PASS
APK INSTALLED: YES — adb install -r
VISUAL REVIEW: PASS — operador externo
SECURITY: PASS dentro del alcance DEMO local
FINAL: READY_FOR_PHASE_13_6E
```

Archivos creados:

- app/Services/Support/FieldSupportActivityAccess.php
- app/Services/Support/FieldSupportActivities.php
- app/Http/Controllers/Support/FieldSupportActivityController.php
- tests/Feature/Support/FieldSupportActivityTest.php
- mobile/src/fieldSupport/FieldActivityFlow.ts
- mobile/src/fieldSupport/FieldActivityFlow.test.ts
- mobile/src/fieldSupport/FieldActivitiesPage.vue
- mobile/src/fieldSupport/services.ts
- docs/operations/vending-field-support-mobile-demo.md

Archivos existentes modificados en esta fase, conservando el trabajo previo:

- app/Services/Support/SupportActivityAccess.php
- app/Services/Support/SupportActivityWebQueries.php
- app/Services/FieldIdentity/DeviceIdentityService.php
- routes/field-mobile-api.php
- mobile/src/fieldIdentity/FieldMobileTransport.ts
- mobile/src/router/index.ts
- mobile/src/views/HomePage.vue

La skill design-web-frontends guió los componentes/estilos durante la implementación.
Para la verificación física se aplicó el flujo de inspección/interacción de
vercel:agent-browser mediante CDP del WebView DEBUG real, al no estar disponible
el CLI agent-browser. La aprobación visual provino del operador externo.

No se implementó Phase 13.6E ni Phase 14. Sin commit, tag, push ni deploy.
