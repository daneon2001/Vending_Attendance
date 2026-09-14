# Phase 13.7.1 — cierre de candidata interna

2026-09-11. **PASS / INTERNAL BETA READY / READY_FOR_CHECKPOINT_AUTHORIZATION**.
No equivale a segundo Android autorizado, piloto real ni producción.

Acta vigente: [auditoría de los 16 gates](internal-beta-closeout-13.7.1.md).
La evidencia 13.7 que sigue se conserva como historial; sus pendientes y hash
anterior no sustituyen los resultados de 13.7.1.

- Versión 1.0.1-beta.1 / build 2, DEBUG INTERNAL BETA.
- HEAD 702b641ef803793d025903435b81a26fc1f48a0c más worktree preservado; no commit nuevo.
- APK instalada y preservada: SHA256
  `1f57e7af956b2f4ea6be612e0bfb804feb4c910b850feff1fb8006d314631898`,
  29,374,425 bytes. Actualización física comprobada: 10:49:56 local.
- Pruebas históricas: Laravel 414 / 3776 assertions; frontend 132; mobile 317;
  Android 9, builds PASS. No se repitieron ni atribuyeron a una nueva ejecución.
- Gates físicos/visuales de todos los módulos, resolución, offline, restart y
  reconnect confirmados por operador. Capturas directas: Mis actividades y Resumen.
- HIGH inicial por heartbeat vencido se recuperó automáticamente. Final: 0 HIGH,
  DEVICE_DEGRADED MEDIUM por NETWORK_TIMEOUT reciente y DEVICE_RETIRED MEDIUM.
- KPI 0 correcto: cuenta terminales ONLINE, excluye FIELD_MOBILE ACTIVE.
- Baseline: attendance 0, vending events 22, actividades 2 COMPLETED, eventos 8;
  empleados 2507, usuarios 5, assignments 8, misma identidad ACTIVE.
  Quince conjuntos protegidos sin diferencias entre lecturas.
- Backup DB, APK, fuente y fotos preservados fuera de Git, hashes en acta.
- Security scan acotado PASS: 1275 archivos/1081 entradas APK, 11 valores sensibles
  cotejados sin coincidencias; marcador redacted en prueba negativa, no clave real.
- PHONE LOCAL_SIMULATED, phoneVerified=false, biometría NOT_IMPLEMENTED, HTTPS
  LAN/DEMO CA, START_ONLY_V1, push diferido, carga 1000 no certificada.
- Segundo Android BLOCKED por identidad/fuente telefónica individual; piloto
  NOT_READY; producción NOT_APPROVED. No cleanup, commit, tag, push ni deploy.

---

# Phase 13.7 — cierre técnico parcial

11 septiembre 2026. **PARTIAL / INTERNAL BETA NOT_READY**.
Siguiente gate: revisión visual externa, no distribución ni segundo enrolamiento.
Se usó design-web-frontends: matriz previa, activos/tokens/componentes existentes,
permisos backend intactos y separación entre prueba automatizada y visual.

## Cambios acotados

- Home prioriza tareas; distingue instalación personal de terminal. No provisiona
  automáticamente ni inicia sincronización vending sin identidad terminal.
- Diagnóstico consulta metadata nativa real, HTTPS, permisos y pendientes por
  contexto. No solicita permisos, captura ubicación/fotos, firma challenges,
  ejecuta OTP, recupera reintentos ni sincroniza colas. Sin almacén abierto o
  contexto verificable informa No disponible, nunca cero inventado.
- Marca corta Medical Life reutilizada sin transformación. SHA256 original y
  ambas copias: DA6C0088C74285CC5D46D8A8EBD688391EDDF757D9D26E36C8A2CE7F5100667C.
  Recursos legacy/adaptive/round y splash de producto; validar máscaras físicamente.
- HTML base español, nombre oficial, favicon oficial, sin bloqueo de zoom.
- Grupo web Identidad y biometría con rutas/pestañas existentes, permisos
  employee_device explícitos. Terminales y dispositivos personales diferenciados.
- Biometría/enrolamiento futuro honestamente no habilitados. No modelos ni cambios
  de resolver, OTP, RBAC, HMAC, GPS, outbox, asistencia, contratos o datos reales.
- Versión beta opt-in DEBUG. Web muestra metadata candidata, no publicación DB.

## Validación automatizada

| Comprobación | Resultado |
| --- | --- |
| Laravel Support + Vending + FieldIdentity + Permissions | 414 PASS, 3776 assertions |
| Frontend (todos los archivos tests/Frontend/*.test.js) | 132 PASS |
| Mobile (36 archivos) | 317 PASS |
| Android native | 9 PASS: marca 2, firma 1, confianza DEBUG/RELEASE 2, mixed-content 4 |
| Build web | PASS |
| Build mobile | PASS |
| Cap sync Android | PASS, mismos 8 plugins |
| assembleDebug -PinternalBeta=true | PASS |
| Intento verifyPilotReleaseConfiguration con internalBeta=true | DENEGADO, esperado; guard PASS |
| Pint | NOT_APPLICABLE: no cambios PHP en esta fase |
| git diff --check | PASS |

No se repitió suite Laravel completa: no hubo cambios backend transversales.
Se corrigieron pruebas estructurales afectadas por naming/rutas y soporte JSON
en el renderer de pruebas. Dos fallos iniciales del nuevo test Java (escape de
comillas e incompatibilidad de readString con API Android) corregidos; ejecución
final nativa sin fallos. No se alteró API/TLS para hacer pasar pruebas.

Warnings de build: Browserslist desactualizado, Tailwind content móvil,
pseudoselector host-context de CSS Ionic, chunk grande SQLite y flatDir Gradle.
No bloquean compilación; no se actualizaron dependencias fuera de alcance.

## APK candidata — NO artefacto beta distribuible aprobado

- Ruta: mobile/android/app/build/outputs/apk/debug/app-debug.apk (ignorada por Git).
- Package: com.medicalife.vendingattendance.
- Versión: 1.0.1-beta.1. Build: 2.
- Tamaño: 29,377,422 bytes.
- SHA256: 0AA559A881AC0A26B1CD35EA3759838AD9C90FAA6B515FE2AC03EA7013F491B4.
- Timestamp de compilación: 2026-09-11T16:16:04.7771139Z.
- APK/merged manifest comprobados: debuggable=true, allowBackup=false,
  icon/roundIcon product_icon, configuración de CA sólo DEBUG existente.
- RELEASE no incorpora networkSecurityConfig DEMO ni cleartext; pruebas nativas
  y guard de Gradle PASS. No se compiló/publicó una release productiva.
- Capacitor empaquetado: loggingBehavior=none, androidScheme=https,
  allowMixedContent=false; excepción nativa DEBUG preexistente, no ampliada.
- Scan: 165 archivos fuente modificados/no rastreados y 1081 entradas APK;
  comprobación en memoria de 11 nombres sensibles configurados sin imprimir sus
  valores. Cero coincidencias de valores, PEM privado o nombres de DB/dumps/keys.
  Sin secretos/phone DEMO/OTP fijo/llaves privadas detectados. Esto no es un pentest.
- No instalada, no compartida. La APK beta final con nombre distribuible se
  preparará sólo después de aprobación visual y demás gates.

## Baseline protegido

SELECT y hashes, sin nuevas escrituras de negocio:

- attendance_logs=0; vending_attendance_events=22. Evento 22 manual autorizado
  expresamente por usuario; no eliminar para volver a 21. Hash de 22 sin cambios:
  e6e75791e32afc85b3395954252678f9f384d6caafe65be16229cf164cfaf1da.
- Dos actividades COMPLETED, 8 eventos de timeline; hashes de ambas tablas intactos.
- Empleados 2507, Users 5, assignments 8, tickets 2; hashes intactos.
- FIELD_MOBILE: mismo UUID, misma key fingerprint, mismo owner y ACTIVE; no
  nueva OTP, challenge o binding generado por esta ejecución.
- PHONE=LOCAL_SIMULATED; phoneVerified=false por contrato/ActorContext vigente.
  phone_verified_at del OTP simulado no debe interpretarse como prueba telefónica real.
- SYBI 7 DRAFT, sin geofence/Device, assignment 7 conservado; hash intacto.
- ASISTENCIAS_FORTIA clean. Phase 14 preservada. Biometría NOT_IMPLEMENTED.
- COMPLETE_LOCATION_POLICY=START_ONLY_V1, sin cambios.

## Archivos de esta fase (los demás cambios del worktree son previos)

Nuevos:

- docs/beta/internal-beta-discovery.md
- docs/beta/internal-beta-installation.md
- docs/beta/internal-beta-checklist.md
- docs/beta/internal-beta-validation.md
- mobile/internal-beta.json
- mobile/public/medical-life-mark.png
- mobile/src/app/startup.ts
- mobile/src/diagnostics/collect.ts
- mobile/src/diagnostics/presentation.ts
- mobile/src/views/DiagnosticsPage.vue
- mobile/tests/unit/beta-readiness.spec.ts
- mobile/tests/unit/diagnostics.spec.ts
- mobile/android/app/src/main/res/drawable-nodpi/medical_life_mark.png
- mobile/android/app/src/main/res/drawable/product_icon.xml
- mobile/android/app/src/main/res/drawable/product_splash.xml
- mobile/android/app/src/main/res/drawable/product_splash_icon.xml
- mobile/android/app/src/main/res/drawable-anydpi-v26/product_icon.xml
- mobile/android/app/src/test/java/com/medicalife/vendingattendance/BetaBrandResourcesTest.java

Modificados (incluye algunos archivos nuevos de fases anteriores, conservados):

- mobile/index.html
- mobile/android/app/build.gradle
- mobile/android/app/src/main/AndroidManifest.xml
- mobile/android/app/src/main/res/values/styles.xml
- mobile/src/main.ts
- mobile/src/router/index.ts
- mobile/src/views/HomePage.vue
- mobile/src/views/ProvisioningPage.vue
- mobile/src/fieldIdentity/FieldMobileFlow.test.ts
- mobile/tests/unit/support-ui.spec.ts
- resources/js/Layouts/AuthenticatedLayout.vue
- resources/js/presentation/navigation.js
- resources/js/Pages/FieldIdentity/Index.vue
- resources/js/Pages/VendingFleet/Releases.vue
- tests/Frontend/externalVisualReview.test.js
- tests/Frontend/fieldIdentity.test.js
- tests/Frontend/vueRender.mjs

## Pendientes y punto de parada

Revisión externa de Home, navegación, diagnóstico, launcher/splash, safe areas y
pantallas críticas en HONOR; Web a 1920×1080 y 1366×768 por roles. Todos los PASS
visuales de esta fase siguen pendientes. Máximo tres iteraciones.
Hace falta autorización para instalar esta candidata mediante adb install -r en
HONOR; no borrar datos ni generar nuevos registros en la revisión.

MULTI_DEVICE=BLOCKED para onboarding real adicional hasta autorizar identidad
individual y fuente telefónica. No relajar el resolver ni compartir cuenta/clave.
SECOND_ANDROID=NOT_RUN. No commit/tag/push/deploy, sin cambios .env ni CA.

FINAL: READY_FOR_EXTERNAL_VISUAL_REVIEW.
