# Phase 13.7.2 — LAN rebinding validation

2026-09-12 CDMX / 2026-09-13 UTC. **PARTIAL**: nueva LAN y APK funcionales;
validación personal detenida antes de credenciales conforme al gate 12.
**FINAL: BLOCKED:PERSONAL_SESSION_NEW_ORIGIN_REQUIRES_AUTHORIZATION.**

No cambios de lógica funcional, usuarios, identidad, asistencias ni soporte.
La candidata build 2 y la auditoría 13.7.1 siguen siendo evidencia histórica válida.
No se reescribieron sus URLs, hash o resultados.

## Resultado obligatorio

| Campo | Resultado |
| --- | --- |
| PHASE 13.7.2 | PARTIAL |
| OLD LAN | 192.168.101.15 |
| NEW LAN | 192.168.1.80 |
| CA REUSED | YES |
| NEW SERVER CERT | PASS |
| CERT SAN | IP:192.168.1.80 |
| HTTPS LISTENER | PASS |
| ANDROID TRUST | PASS, confirmado desde APK real |
| FIELD_IDENTITY_URL | https://192.168.1.80:8443 |
| OLD URL REMOVED FROM ACTIVE BUILD | YES |
| APP VERSION | 1.0.1-beta.1 |
| APP BUILD | 3 |
| REBUILD | PASS |
| ADB INSTALL -R | PASS |
| HTTPS FROM APK | PASS |
| FIELD_MOBILE | PRESERVED en DB y almacenamiento; consulta autenticada pendiente |
| KEYSTORE | PRESERVED por actualización compatible, sin borrado/regeneración; firma/clave pública nativa no revalidadas en esta fase |
| SESSION | EXPIRED para uso en nuevo origen; no se afirma expiración temporal del token |
| PENDING OPERATIONS | 0 asistencias; 0 reportes/verificaciones; personales NO DISPONIBLE sin sesión válida |
| WEB LOCAL ACCESS | PASS: /up 200, /login 200, /vending 302 al login nuevo |
| OLD APK | PRESERVED |
| NEW APK | VendingAttendance-internal-beta-build3-lan-192.168.1.80.apk |
| NEW APK SHA256 | 1b84260bc8389b8ac6f4e2e5c72827e7013171eb4bcb84291eafa743952c26a0 |
| ATTENDANCE | 0 |
| VENDING ATTENDANCE EVENTS | 22 |
| SUPPORT ACTIVITIES | 2, ambas COMPLETED |
| SYBI 7 | PRESERVED |
| SECURITY | PASS para alcance configurado/escaneado |
| FINAL | BLOCKED:PERSONAL_SESSION_NEW_ORIGIN_REQUIRES_AUTHORIZATION |

## Red y certificado

`ipconfig`: PC Wi-Fi 192.168.1.80/24, gateway 192.168.1.254.
ADB `ip route`: HONOR 192.168.1.77/24 en wlan0. Ping desde Android a PC:
3 enviados/3 recibidos, 0% pérdida. No se asumió conectividad por USB.

CA reutilizada: CN=Vending Local Demo CA, vigente 2026-09-09 a 2026-10-09 UTC.
Fingerprint SHA256 anterior y posterior idéntico:
`F575BA30AE81559CE5DA3BCA01C70B58D665B5E9E8977E5F797EE3BCB1B7785A`.
No se emitió ni reinstaló CA. No se modificó su clave ni serial de emisión previo.

Nuevo certificado de servidor, issuer Vending Local Demo CA, SAN IP:192.168.1.80,
vigencia 2026-09-13 03:21:28 a 2026-09-20 03:21:28 UTC.
Fingerprint SHA256:
`8578891278CB1306319DD7DCCB0B1C6264F13940D142D70CFEC620681B5A18C4`.
Archivos nuevos en directorio privado existente:

- `storage/framework/local-https/server-192.168.1.80.pem`
- `storage/framework/local-https/server-192.168.1.80.key`
- `storage/framework/local-https/server-192.168.1.80.csr`

Los server.pem/server.key originales se conservaron. No se copiaron claves a
Git ni se modificaron ACL. Se usó la elevación autorizada para acceder al directorio.
OpenSSL verify con CA y verify_ip=192.168.1.80: OK.

Listener nuevo separado: `tools/local-field-https/httpd-lan-192.168.1.80.conf`.
Usa PID/log/cache/FastCGI propios y certificados nuevos; conserva TLS 1.2/1.3,
reglas de proxies, TEMP/TMP privados y límite de carga existentes. Apache syntax OK.
No se detuvo ningún listener anterior. Su IP antigua no está disponible en esta LAN.

Apache registra warnings de la instalación preexistente OpenSSL/mod_ssl y CN
genérico con nombre de servidor IP. Se comprobó SAN/cadena con cliente TLS y
desde Android; no se deshabilitó hostname verification para evitarlos.
curl Schannel rechazó la CA DEMO por estado de revocación desconocido; se utilizó
Python ssl.create_default_context(cafile=CA), con validación de cadena/hostname
activa. Android confirmó la conexión con su trust store real. No trust-all ni -k.

Arranque futuro (sólo si no existe listener y certificado vigente):
`powershell.exe -NoProfile -ExecutionPolicy Bypass -File tools/local-field-https/start-lan-192.168.1.80.ps1 -Start`.
El script valida certificados existentes; no los emite ni modifica confianza.
Puede requerir ejecución por el custodio del directorio privado. No ejecutar el
prepare.ps1 antiguo para esta LAN: se conserva para la configuración histórica.

## Auditoría de referencias y cambios acotados

| Clasificación | Referencias | Acción |
| --- | --- | --- |
| RUNTIME local | .env APP_URL y VITE_API_BASE_URL | nueva URL HTTPS, ningún secreto modificado |
| BUILD CONFIG | mobile/.env.local | VITE_API_BASE_URL y VITE_FIELD_IDENTITY_BASE_URL explícitas a nueva URL |
| BUILD CONFIG DEBUG | field_demo_network_security.xml | sólo domain-config de usuario cambia a nueva IP |
| BUILD CONFIG | mobile/internal-beta.json | versionName igual, build 2 → 3; política opt-in DEBUG preservada |
| TEST FIXTURE activo | FieldNetworkSecurityTest.java y beta-readiness.spec.ts | host esperado y build esperado adaptados |
| Configuración LAN anterior | httpd.conf, openssl.cnf, prepare.ps1 | conservados; nueva configuración en archivos paralelos |
| DOCUMENTATION / HISTORICAL EVIDENCE | docs/operations, actas beta previas | sin reemplazo de IPs históricas |
| TEST FIXTURE histórico | tests/Support/local_https_transport_probe.php | conservado; no ejecutado contra nueva LAN |

No se cambió runtimeConfig ni el transporte de Field Identity. Este último usa
VITE_FIELD_IDENTITY_BASE_URL con fallback existente a VITE_API_BASE_URL.
Ambos valores son HTTPS nuevo en esta build. La API de terminal también se mueve
al HTTPS nuevo porque dependía de la IP anterior.

Web es same-origin. Field Mobile usa transporte nativo sin cookies/Origin y token
propio; no depende de Sanctum stateful domains. CORS conserva localhost/Capacitor
para rutas device. SESSION_DOMAIN=null permanece host-only. Trusted proxies y
allowed origins no requieren apertura ni cambio. APP_URL local actualizado para
generación de enlaces; /vending redirige a https://192.168.1.80:8443/login.

## Builds, pruebas y artefactos

- Web build: PASS; prueba frontend de identidad/versiones: 6 PASS.
- Mobile: 36 archivos, 317 tests PASS.
- Mobile build: PASS; warnings existentes Browserslist, Tailwind, Ionic CSS y chunk SQLite.
- Cap sync Android: PASS, mismos 8 plugins.
- JDK Android Studio 21.0.10, Gradle wrapper existente, modo offline;
  `-PinternalBeta=true testDebugUnitTest assembleDebug`: PASS.
- Native: 9 tests, 0 failures/errors, incluye aislamiento DEBUG/RELEASE.
- Sin dependencias actualizadas, sin suites backend/E2E repetidas.
- git diff --check: PASS.

APK nueva en `storage/app/private/phase-13.7.2/` (ignorada):
`VendingAttendance-internal-beta-build3-lan-192.168.1.80.apk`.
Size **29,283,627 bytes**; timestamp **2026-09-13T03:27:46.267346Z**;
versionName **1.0.1-beta.1**, versionCode **3**, package sin cambios,
debuggable. SHA256 indicado en tabla. ZIP íntegro. Búsqueda en todas las entradas
APK: cero referencias a 192.168.101.15; nuevo HTTPS presente en los bundles de
FieldMobilePage, DiagnosticsPage y runtime, incluyendo variantes legacy.

APK anterior conservada en
`storage/app/private/internal-beta-1.0.1-beta.1-build-2/vending-attendance-1.0.1-beta.1-build-2-debug-observed-unapproved.apk`:
29,374,425 bytes, SHA256
`1f57e7af956b2f4ea6be612e0bfb804feb4c910b850feff1fb8006d314631898`.
El archivo de salida habitual app-debug.apk ahora contiene build 3; el artefacto
histórico separado nunca se sobreescribió.

## Instalación y evidencia física

Única instalación: adb -s HONOR install -r APK nueva → Success.
firstInstallTime conservado 2026-09-04 16:03:36; lastUpdateTime 2026-09-12 21:28:28
local. Android confirma versión 1.0.1-beta.1/build 3. Apertura COLD OK, 989 ms.
No uninstall/pm clear, extracción de datos cifrados, clonación ni regeneración.

SHA256 de los archivos cifrados WSSecureStorageSharedPreferences.xml y
sqlite_encrypted_shared_prefs.xml iguales antes/después de instalar. Sólo se
obtuvieron hashes, nunca el contenido. Esto verifica preservación de esos archivos;
no se presenta como prueba nueva de firma con Android Keystore. No se invocó
createKey, sign, OTP, registro ni reemplazo. El plugin de claves no fue modificado.

Diagnóstico real abierto y botón Actualizar diagnóstico ejecutado:

- Versión 1.0.1-beta.1, compilación 3.
- Servidor de identidad personal: **Accesible por HTTPS**.
- Dispositivo personal: **Inicia sesión para consultar tu dispositivo**.
- Trabajo de campo pendiente y operación personal por confirmar: **No disponible**.
- Asistencias pendientes: **0**.
- Reportes y verificaciones pendientes: **0**.
- Última sincronización de configuración: **10/09/2026 10:37:14 a.m. CDMX**.
  Es un timestamp guardado, no se presenta como sincronización nueva de manifests.
- Permisos GPS/cámara existentes permitidos; no se solicitaron nuevos permisos.

Capturas privadas: home-build3.png, diagnostic-build3.png,
diagnostic-network-build3.png y diagnostic-sync-build3.png, en el directorio de fase.

La sesión y el draft están vinculados al origin original. `hasSession()` exige
coincidencia exacta de origin además de vigencia. Al cambiar la IP, no se reutiliza
la sesión anterior, aunque los bytes del almacenamiento se preservan. No se editó
origin/token/draft para sortear ese aislamiento. **Parada antes de credenciales**.
Tampoco se afirma que iniciar sesión por sí solo resuelva el draft del origen
anterior: ese gate requiere revisar recuperación de identidad existente sin
reenrolamiento ni cambio de clave. Los pendientes personales no se certifican en cero.

## Baseline y seguridad

Dos snapshots SELECT dentro de transacción READ ONLY, 14 tablas comparadas por
SHA256 de filas ordenadas: **todas idénticas**. Archivos privados:
baseline-before.json, baseline-after.json, baseline-comparison.json.
employees=2507; attendance_logs=0; vending_attendance_events=22;
vending_support_activities=2 COMPLETED; users=5; assignments=8.
EmployeeDevice 1, UUID a7807121-1079-4223-9fff-abe34043be6a, mismo ACTIVE,
Employee 5/User 4/key_version 1. Tabla completa incluido fingerprint sin cambios.
SYBI 7 preservada por igualdad de máquinas, geocercas, asignaciones y ausencia
de operaciones de negocio. No se accedió a Fortia ni al proyecto ASISTENCIAS_FORTIA;
clean se conserva como baseline recibido, no auditoría externa nueva.
PHONE=LOCAL_SIMULATED, phoneVerified=false por contrato intacto,
BIOMETRY=NOT_IMPLEMENTED, Phase 14 sin cambios de esta fase.

Security scan: 11 valores configurados comparados en memoria contra todas las
entradas APK, cero coincidencias; sin material PEM privado. Claves CA/servidor,
.env/.env.local, DEVICE_DEMO_PHONE y APKs permanecen fuera de Git. Android RELEASE
conserva manifest/confianza de sistema y guard de build; no trust-all, hostname
verifier inseguro ni HTTP para autenticación humana.

No commit/tag/push/deploy. No piloto ni segundo Android.
Siguiente paso requiere autorización para revisar/recuperar la sesión personal
en el origen nuevo conservando el FIELD_MOBILE existente; credenciales sólo en
el teléfono por su operador. No continuar hacia OTP o registro automáticamente.
