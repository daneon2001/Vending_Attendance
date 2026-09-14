# Phase 13.6D.1.2 — preparación de la demo física

Fecha: 2026-09-09. Estado: PARTIAL. Sin commit, tag, push ni deploy.

## Implementación disponible

- Mi dispositivo → inicio de sesión humano → perfil propio enmascarado → OTP local →
  generación/reutilización de ECDSA P-256 → registro PENDING → challenge firmado →
  ACTIVE sólo tras confirmación del servidor.
- Sesión humana expira en ocho horas; bearer con digest separado de Sanctum, no
  utilizable en las APIs ajenas. No reutiliza HMAC de la terminal.
- Metadata de registro e identidad de operación persistidas en claves propias de
  Secure Storage. No se guarda contraseña, OTP, teléfono completo ni clave privada
  en ese draft. Nunca se limpia el almacenamiento de la terminal.
- Al abrir de nuevo: consultar servidor e inspeccionar clave existente; para ACTIVE,
  challenge nuevo y firma. Clave ausente/revocación/binding ajeno bloquean reemplazo
  automático. Recuperación de respuesta OTP ambigua mediante estado propio.
- Excepción local/testing exacta User 4 / Employee 5 / número 990001005 / source DEMO;
  resolver global y autorización de SupportActivity intactos.
- DEVICE_DEMO_PHONE sólo de .env.local local ignorado; testing usa proveedor
  simulado generado en memoria y nunca lee ese archivo. PHONE=LOCAL_SIMULATED,
  phoneVerified=false. No hay verificación SMS real.
- El puente nativo permite inspección KeyInfo, nivel de seguridad y disponibilidad
  StrongBox. Esto NO prueba hardware real antes del gate físico.
- loggingBehavior=none porque Bridge.java de Capacitor instalado imprime methodData
  en debug. Respuestas de error de la nueva API sanitizadas incluso con APP_DEBUG.
- HTTPS obligatorio para credenciales humanas. El HTTP autorizado previamente para
  la terminal no se extiende a login humano. No se cambia VITE_API_BASE_URL.

## Bloqueos y límites de esta entrega

1. La configuración compilada actual no tiene VITE_FIELD_IDENTITY_BASE_URL y la
   URL heredada de la terminal es HTTP. Mi dispositivo muestra bloqueo amigable
   antes de enviar credenciales. Hace falta una URL HTTPS de esta misma API con
   certificado aceptado por HONOR, sin desactivar validación TLS, y reconstruir
   usando esa URL local. No se configuró TLS ni se alteraron archivos .env.
2. El control automático de ejecución rechazó el shell administrativo por alcance
   sensible de datos. También rechazó la alternativa limitada a User 4/Employee 5.
   No se aplicaron controller, rutas, páginas ni navegación administrativos.
   Se requiere aprobación y resolución explícita de esa frontera antes de seguir;
   no se eludió el rechazo.
3. Pilot Admin y Pilot Support no poseen biometrics.face.manage en la DB consultada.
   No se añadieron permisos, roles, seeder ni grants. Debe definirse qué cuenta y
   alcance administrativo se autorizan, sin conceder acceso por el nombre del rol.
4. No se instaló la APK, no se ejecutó OTP real/local persistido, no se creó keypair
   físico, employee_device, actividad ni asistencia. No hay certificación visual.

## Validación automatizada

- FieldIdentity: 44 PASS (30 existentes + 14 nuevos).
- Conjunto dirigido identidad/User–Employee/Support/contrato auth: 110 PASS, 1407
  assertions antes del último ajuste de presentación de revocación; cubierto de
  nuevo por la suite completa final.
- Laravel completa final: 750 PASS / 1 FAIL, 6282 assertions. Única falla:
  OnPremDiagnosticsCommandTest.php:54, previamente documentada en baseline.
- Mobile: 281 PASS, 32 archivos. Incluye OTP incorrecto, éxito HTTP sin verified,
  reinicio, pérdida de respuesta, retry idéntico, revocación, falta de clave,
  draft ajeno, sesión expirada, HTTPS, mensajes y ocultamiento técnico.
- Frontend web existente: 124 PASS. No se creó ni certificó el shell administrativo.
- Android Java: 5 PASS; algoritmo/encoding y política MainActivity. No equivalen
  a Android Keystore físico.
- Build web, build mobile, cap sync android, testDebugUnitTest, assembleDebug: PASS.
- Advertencias heredadas de Browserslist, Ionic CSS/Tailwind, chunks y flatDir;
  no se agregaron dependencias ni se intentó corregirlas.
- Pint scoped y git diff --check: PASS.

## APK preparada, no instalada

Ruta: mobile/android/app/build/outputs/apk/debug/app-debug.apk.
Tamaño: 29,963,731 bytes.
SHA256: 4fcb724979e2f441405ac1fd4f1bb0ab156764203f1c5ab225adaa0ae899d15b.
El hash corresponde a esta compilación con el origen humano aún bloqueado por HTTP.
Config empaquetada: loggingBehavior none; allowMixedContent false compartido,
excepción debug existente en MainActivity sin cambios.

Se comprobó en memoria que el teléfono configurado no aparece en ninguno de los
1,224 archivos versionables examinados ni en las entradas descomprimidas del APK.
No se imprimió su valor. .env.local y APK siguen ignorados por Git.

## Evidencia persistida protegida

Antes/después: employees 2507, users 5, assignments 7, attendance_logs 0,
vending_attendance_events 19, support tickets 2, support activities 0.
employee_devices / OTPs / challenges / field identity audit: todos 0.
Sesiones humanas de este flujo creadas en DB real: 0.
Hashes de usuarios, empleados, assignments, asistencia y tickets idénticos.
User 4 ↔ Employee 5 sigue siendo el único vínculo real.

SYBI 7: RESERVED, DRAFT, sin geofence ni Device; assignment 7 y su hash intactos.
Roles, permissions y pivots: hashes iguales. ASISTENCIAS_FORTIA clean.
Archivos de Phase 14 y trabajo previo conservados.

El hash agregado de devices cambió mientras llegaba telemetría externa del
terminal. No se hicieron llamadas físicas ni escrituras de Device por Codex.
Preflight real observado: PASS; heartbeat 18 s / <180; ONLINE; configuración 3/3;
empleados 5/5; outbox 0; HIGH 0; MEDIUM 1 informativa. Esto no certifica FIELD_MOBILE.

## Siguiente gate y autorización

Antes de probar: resolver HTTPS, identificar la cuenta autorizada y el alcance
administrativo, confirmar HONOR conectado/desbloqueado y cuenta humana ingresada
manualmente por el operador; no compartir contraseña en chat ni CLI.

Sólo después de resolver bloqueos y confirmar autorización física: instalación
no destructiva con adb install -r (misma appId y firma), preflight, OTP local,
clave real, prueba de posesión, ACTIVE, reinicio y firma nueva. La instalación
nunca debe sustituirse por uninstall/pm clear si falla; detenerse y reportar.

No borrar SQLite/Secure Storage ni reprovisionar VENDING_TERMINAL. No crear
asistencias, SupportActivity ni Face ID. Sin cambios en SYBI 7.

Revisión visual externa pendiente en Android (registro, OTP, ACTIVE) y, cuando
se autorice/implemente, administración web en 1920×1080 y 1366×768.
KEYSTORE BACKING=UNKNOWN; evidencia física, replay físico y reinicio físico=NOT_RUN.
FINAL: BLOCKED: HTTPS_HUMAN_TRANSPORT_AND_ADMIN_SCOPE_APPROVAL.

## Archivos de este turno

Creados (además de este reporte):

- `app/Http/Controllers/FieldIdentity/FieldMobileErrors.php`
- `app/Http/Controllers/FieldIdentity/FieldMobileSessionController.php`
- `app/Http/Middleware/AuthenticateFieldMobile.php`
- `app/Http/Middleware/FieldMobileTransport.php`
- `app/Services/FieldIdentity/EnrollmentIdentity.php`
- `app/Services/FieldIdentity/FieldMobileSession.php`
- `app/Services/FieldIdentity/LocalDemoPhone.php`
- `mobile/src/fieldIdentity/FieldMobileFlow.test.ts`
- `mobile/src/fieldIdentity/FieldMobileFlow.ts`
- `mobile/src/fieldIdentity/FieldMobilePage.vue`
- `mobile/src/fieldIdentity/FieldMobileStore.ts`
- `mobile/src/fieldIdentity/FieldMobileTransport.ts`
- `routes/field-mobile-api.php`
- `tests/Feature/FieldIdentity/FieldMobileUxTest.php`

Modificados (respecto al comienzo de este turno; no atribuir cambios previos):

- `app/Http/Controllers/FieldIdentity/DeviceIdentityController.php`
- `app/Services/FieldIdentity/DeviceIdentityService.php`
- `app/Services/FieldIdentity/RegisteredPhoneSource.php`
- `bootstrap/app.php`
- `mobile/android/app/src/main/java/com/medicalife/vendingattendance/FieldDeviceKeyPlugin.java`
- `mobile/capacitor.config.ts`
- `mobile/src/fieldIdentity/FieldDeviceKey.ts`
- `mobile/src/fieldIdentity/FieldEnrollment.ts`
- `mobile/src/router/index.ts`
- `mobile/src/views/HomePage.vue`
- `routes/api.php`
- docs/architecture/vending-device-identity.md (apéndice D.1.2)
- docs/operations/vending-device-enrollment-runbook.md (gate de continuación)
