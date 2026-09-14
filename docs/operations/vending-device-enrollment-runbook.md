# Runbook — Fase 13.6D.1 FIELD_MOBILE

Estado: PARTIAL / no habilitado físicamente. No commit, tag, push ni deploy.

Actualización posterior, Gate 2 de 13.6D.1.1 (2026-09-09): esquema local aplicado
en batch 11 y único vínculo autorizado User 4 → Employee 5. No hay dispositivos
FIELD_MOBILE ni OTP reales. El registro histórico de D.1 de este documento se
conserva; consultar [evidencia del Gate 2](vending-device-identity-local-schema-enablement.md)
para el estado actualizado y respaldo. Esta actualización no autoriza más
migraciones, teléfonos, claves ni enrolamientos físicos.

## Prohibiciones de este checkpoint

No ejecutar migrate en vending_attendance_dev. No crear vínculos reales,
empleados, OTP reales, tickets, actividades ni asistencias. No instalar APK,
reaprovisionar ni borrar Secure Storage/SQLite. No usar SYBI 7.

La implementación falla cerrada por ausencia de fuente telefónica. LocalOtpProvider
es una simulación; no certifica posesión de teléfono. Los bindings de prueba
registran LOCAL_SIMULATED y el ActorContext no afirma phoneVerified real.

## Qué se puede validar ahora

1. Tests PHP sólo con APP_ENV=testing, DB_CONNECTION=sqlite,
   DB_DATABASE=:memory:, DB_URL vacío y cache/session=array.
2. Ejecutar php artisan test tests/Feature/FieldIdentity.
3. Ejecutar tests Vending, Support, Auth y Permissions; suite completa por el
   nuevo punto de entrada API.
4. En otra terminal local, ejecutar php tests/Support/field_identity_mysql.php.
   El harness crea una base field_identity_test_<aleatorio> nueva, verifica
   ownership, dirige todas las conexiones a ella, migra únicamente allí y la
   elimina al finalizar. Nunca reutiliza ni migra la base configurada.
5. El harness prueba dos procesos concurrentes bloqueados sobre el mismo User,
   reintento único persistente, firma/activación, unique ACTIVE, FK RESTRICT,
   revocación y aislamiento de asistencia/terminal.
6. En Windows, si generación OpenSSL devuelve false por ausencia de configuración,
   establecer OPENSSL_CONF para ese proceso apuntando al archivo instalado:
   dirname(PHP_BINARY)/extras/ssl/openssl.cnf. No cambiar .env ni guardar claves.
7. Mobile: npm test; npm run build; npx --no-install cap sync android.
8. Android: gradlew.bat testDebugUnitTest assembleDebug, usando JDK compatible.
   Sólo genera artefactos ignorados; NO installDebug ni adb install.
9. Pint sólo sobre PHP de esta fase y git diff --check.

No ejecutar ambos entornos de testing en una misma terminal sin restablecer sus
variables: el harness MySQL exige local y una conexión MySQL, y crea su propio
esquema desechable. Nunca configurar PHPUnit contra una DB persistida.

## Registro futuro, aún NO autorizado

Prerequisitos pendientes:

- Fuente corporativa de teléfono y su trazabilidad aprobadas.
- Decisión explícita para las identidades MANUAL actuales: no convertir su source
  ni crear vínculos por coincidencia de nombres/email.
- Login/transporte humano móvil sobre HTTPS con token de corta duración y ability
  field-device:enroll. No usar el secreto HMAC ni el login opensync.
- Persistir UUIDs del draft/reintentos sin guardar OTP ni private key.
- Revisión/aprobación separada de la migración real y de un usuario físico piloto.
- Si se requiere SMS real, aprobar proveedor y flujo; no desactivar el gate de
  entorno de V1 sólo cambiando APP_ENV.
- Autorización física explícita del dispositivo. Una compilación no es esa autorización.

Secuencia de contrato cuando existan esos prerequisitos:

1. User autenticado y activo; backend resuelve Employee Fortia activo.
2. Backend obtiene teléfono registrado, devuelve sólo máscara.
3. Enviar/verificar OTP; nunca guardar el código en logs, SQLite o draft.
4. Clave P-256 nativa y UUID propio. Mantener operation_uuid para reintentos.
5. register con public_key y metadata mínima; respuesta PENDING.
6. Solicitar challenge ENROLLMENT, firmar bytes exactos, prove.
7. ACTIVE sólo con respuesta confirmada del servidor. Si se pierde la respuesta,
   repetir register con el mismo contenido, no crear otro binding.
8. Para reemplazar: nuevo OTP/UUID/clave y replaces_uuid del activo actual.
   El dispositivo previo no cambia hasta validar la firma nueva.
9. revoke es propio; bloquea nuevas pruebas y deja el histórico. Recuperación
   administrativa de cuenta/teléfono perdido necesita política/UI posterior.

Nunca llamar a esto Face ID. No registrar una asistencia para probar identidad.

## Evidencia y límites

- Clave privada no expuesta por el bridge; sólo public key/SPKI y firma DER.
- ECDSA en tests Java y OpenSSL backend, no attestation hardware.
- Sin verificación física Keystore/reinicio del HONOR en esta fase.
- iOS sólo contrato portable; plugin iOS pendiente.
- ActorContext genérico no autoriza una operación ni reemplaza RBAC/geocerca.
- No UI administrativa ni móvil nueva: ambas dependen del cierre de los gaps.
- No se añadieron librerías/dependencias ni un proveedor SMS.

## Protección del baseline

Auditoría de datos antes: employees=2507, users=5, assignments=7,
attendance_logs=0, vending_attendance_events=19, support_tickets=2,
support_activities=0 y support_activity_events=0; user links=0.
SYBI 7: DRAFT, sin geofence ni terminal, assignment 7 conservado.
ASISTENCIAS_FORTIA clean. Registrar comparación final de fingerprints, no PII.

Consultar [arquitectura](../architecture/vending-device-identity.md) para decisiones,
límites V1, modelo histórico y threat model.

## Resultado técnico de este checkpoint (2026-09-09)

- Backend específico: 30 PASS / 240 assertions.
- Suite completa: 736 PASS / 1 FAIL / 6131 assertions.
  Única falla: OnPremDiagnosticsCommandTest, misma del baseline; archivo intacto.
  Regresiones nuevas detectadas: 0.
- MySQL aislado: PASS, incluyendo dos procesos concurrentes, índice unique,
  FK RESTRICT y prueba criptográfica. Dos ejecuciones del harness; ambas bases
  desechables eliminadas. No se restauró ni eliminó ningún dato real.
- Mobile: 264 PASS; build PASS. Advertencias de CSS Ionic, Browserslist y tamaño
  de chunks, sin fallo de compilación.
- Android: testDebugUnitTest PASS (5 pruebas: 4 de política anterior + 1 de firma);
  assembleDebug PASS. No Keystore físico ni attestation medidos.
- Pint scoped PASS; diff check tracked y archivos nuevos PASS.
- Escaneo local de patrones de secretos: 0 hallazgos. Es revisión acotada,
  no certificación de seguridad productiva.
- Fingerprints de users, employees, assignments, attendance_logs,
  vending_attendance_events y support_tickets coinciden antes/después.
- Conteos finales: 2507 employees, 5 users, 7 assignments, 0 attendance_logs,
  19 vending events, 0 activities y 0 activity events. User links=0.
- SYBI 7 DRAFT, 0 geofences, 0 vending devices, 1 assignment; hash de assignment 7
  intacto. ASISTENCIAS_FORTIA clean.
- Migración nueva ausente del registro real; employee_devices no existe en DB real.
- Comparación de 1187 archivos del baseline anterior: sólo cambian los dos
  archivos de integración listados abajo. La documentación C.2 preexistente se
  conserva. Phase 14, User/Employee y los dominios protegidos permanecen intactos.

### Archivos creados en D.1

- database/migrations/2026_09_09_120000_create_field_device_identity_tables.php
- app/Models/EmployeeDevice.php
- app/Services/FieldIdentity/RegisteredPhoneSource.php
- app/Services/FieldIdentity/MexicanPhone.php
- app/Services/FieldIdentity/OtpProvider.php
- app/Services/FieldIdentity/LocalOtpProvider.php
- app/Services/FieldIdentity/DeviceSignature.php
- app/Services/FieldIdentity/ActorContext.php
- app/Services/FieldIdentity/DeviceIdentityService.php
- app/Http/Controllers/FieldIdentity/DeviceIdentityController.php
- routes/field-identity-api.php
- mobile/android/app/src/main/java/com/medicalife/vendingattendance/FieldDeviceKeyPlugin.java
- mobile/src/fieldIdentity/FieldDeviceKey.ts
- mobile/src/fieldIdentity/FieldEnrollment.ts
- mobile/android/app/src/test/java/com/medicalife/vendingattendance/FieldDeviceSignatureTest.java
- tests/Feature/FieldIdentity/DeviceIdentityTest.php
- mobile/src/fieldIdentity/FieldEnrollment.test.ts
- app/Http/Middleware/RequireFieldIdentityToken.php
- tests/Support/field_identity_mysql.php
- docs/architecture/vending-device-identity.md
- docs/operations/vending-device-enrollment-runbook.md

### Archivos existentes modificados en D.1

- routes/api.php: sólo incluir field-identity-api.php.
- mobile/android/app/src/main/java/com/medicalife/vendingattendance/MainActivity.java:
  sólo registrar FieldDeviceKeyPlugin antes de super.onCreate.

El working tree ya contenía cambios de fases anteriores; no se atribuyen a D.1.
No commit, tag, push, deploy ni instalación física.

## Actualización D.1.2 — UX personal y gate físico pendiente (2026-09-09)

Se implementó Mi dispositivo en Android, transporte humano HTTPS separado,
sesión de ocho horas con digest no aceptado por Sanctum fuera de este flujo,
draft persistente y recuperación/revalidación con firma después de reiniciar.
La excepción DEMO permanece exclusiva de identidad FIELD_MOBILE local/testing
para el vínculo autorizado User 4 / Employee 5; el resolver global no cambió.
El teléfono se lee sólo de DEVICE_DEMO_PHONE en .env.local ignorado, nunca de
Employee/Fortia ni del cliente; phoneVerified sigue false y LOCAL_SIMULATED.
Testing no lee el archivo local.

No se montó el shell administrativo: el control automático bloqueó su alcance.
La API terminal actual HTTP tampoco habilita transporte de contraseñas humanas.
No se instaló APK ni se crearon identidades físicas; no hay PASS físico/visual.

Consultar docs/operations/vending-device-demo-review.md para resultados,
archivos, bloqueos y requisitos exactos de continuación. Las secciones anteriores
son evidencia histórica de D.1, no el estado actualizado de la UX móvil.
