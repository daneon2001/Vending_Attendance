# Retorno a LAN 192.168.101.15: corrección build 8

Build 7 actualizó los endpoints Vite pero omitió la política nativa de confianza y el retorno autorizado de origen. La APK seguía confiando en la CA instalada por el operador únicamente para 192.168.1.82; FieldOriginRecoveryPolicy sólo autorizaba migrar hacia esa IP. El diagnóstico del HONOR mostró servidor sin confirmación y sesión pendiente. HTTPS /up comprobado en el servidor: HTTP 200, cadena CA y hostname válidos. La conectividad ICMP del teléfono no demostraba confianza TLS dentro de la APK.

Build 8 limita la confianza debug de la CA de usuario a 192.168.101.15 y añade exclusivamente el retorno debug https://192.168.1.82:8443 → https://192.168.101.15:8443. Conserva las transiciones históricas expresamente permitidas. No cambia la confianza de release, no admite destinos arbitrarios y no deshabilita validaciones de certificado. La recuperación sigue exigiendo login humano y challenge con la clave existente; la autorización de origen no autentica por sí sola.

No se instala automáticamente: la autorización anterior nombraba build 7. El siguiente paso es autorizar adb install -r de build 8, actualizar diagnóstico, iniciar sesión privadamente si procede y verificar el mismo binding mediante recuperación criptográfica. No ejecutar OTP, enrolamiento, generación de clave ni asistencia. Si aparece Registrar dispositivo, detenerse.

Base posterior a la preparación: 14/14 conjuntos idénticos, único FIELD_MOBILE preservado. La política del tester B permanece deshabilitada, sin registro privado. Build 7 queda como artefacto fallido para esta LAN, no como checkpoint validado. No commit, tag, push ni deploy.

## Validación física autorizada completada

Build 8 instalada por adb install -r en HONOR, versionName 1.0.1-beta.1, versionCode 8; tamaño 29,284,838 bytes. APK VendingAttendance-1.0.1-beta.1-build8-lan-192.168.101.15.apk, SHA256 4b6a9dc161b42de2a896caa89265c634926b9580c8e869cbe82a2e399d4039d2. Mismo certificado de firma que builds anteriores.

Login humano completado privadamente por el titular. User 4 → Employee 5 Técnico Demo / 990001005. La APK muestra Dispositivo autorizado, Estado activo, Identidad verificada. Backend registró ACTOR_PROVED a 2026-09-14 15:47:12 UTC con challenge nuevo; después de force-stop y reapertura registró otro ACTOR_PROVED a 15:50:17 UTC. Recuento de challenges 55 → 57; ningún OTP ni enrolamiento. No se solicitaron ni capturaron credenciales.

Mismo employee_device id 1, UUID, fingerprint, key_version=1, owner y activated_at=2026-09-10 15:35:29. Un solo FIELD_MOBILE ACTIVE. Firmas verificadas contra la clave pública existente, sin regeneración de keypair. Reinicio conserva sesión y reconocimiento, sin nuevo registro.

Diagnóstico físico: servidor Accesible por HTTPS; dispositivo Activo; trabajo de campo pendiente 0; operación personal por confirmar 0; asistencias pendientes 0; reportes/verificaciones pendientes 0. No se creó una operación para probar sincronización. La fecha histórica de sincronización de configuración no se presenta como una sincronización terminal nueva.

Baseline final: 13/14 hashes completos idénticos; employee_devices difiere únicamente en last_seen_at y updated_at (ambos 15:50:17 UTC). Para verificarlo se normalizaron ambos timestamps en memoria a su valor previo 04:46:40 UTC y se reprodujo exactamente el hash anterior; ninguna escritura a DB. Resultado semántico 14/14 preservado. Employees 2507, attendance 0, eventos vending 22, actividades 2; máquinas/asignaciones/SYBI 7 intactos. ASISTENCIAS_FORTIA no tocada. Tester B y su dispositivo no creados.

Evidencia privada: build8-after-login.png, build8-restart-pass.png, build8-final-diagnostics.png y build8-final-outboxes.png en storage/app/private/phase-13.8A-implementation. Pruebas automatizadas previas 41 móviles y 11 nativas conservadas, sin suite completa durante validación física.

BUILD 8 PHYSICAL RECOVERY: PASS.
FINAL: READY_TO_RESUME_SECOND_TESTER_PROVISIONING.
