# Phase 13.6D.1.3 — HTTPS humano y administración de identidad

Fecha: 2026-09-09. Gate técnico: PASS tras autorización directa del operador.
Phase 13.6D.1.3 continúa PARTIAL hasta validación física. No se instaló APK ni se
introdujeron credenciales humanas ni se ejecutó enrolamiento físico.
Baseline Git: rama `phase/14-biometric-engine-selection`, HEAD
`702b641ef803793d025903435b81a26fc1f48a0c`. Se conservaron los cambios previos.

## Discovery sin escrituras

- APP_URL efectivo: `http://127.0.0.1`.
- API de máquina: `http://192.168.101.15`. La variable humana opcional no estaba configurada.
- Capacitor usa origen `https://localhost`; `CapacitorHttp` utiliza conexiones nativas
  `HttpURLConnection`/`HttpsURLConnection`. No está instalado el plugin Enterprise SSLPinning.
- Android principal: cleartext false. Debug previo: cleartext true y mixed content sólo
  cuando la app es debuggable. No había Network Security Config ni CA de demo.
- Apache Laragon escucha 80/443; `httpd-ssl.conf` configura protocolo/cifrados, pero no
  contiene un vhost con certificado válido para la IP LAN. No se encontró certificado
  de servidor utilizable en esa configuración. No se sustituyó ningún archivo de Laragon.
- CORS existente aplica sólo a API Device, sin credenciales. Human API nativa rechaza
  Origin/Cookie y exige HTTPS. No utiliza cookies ni el login web.
- Cookies web locales: driver file, SameSite lax, HttpOnly true, secure sin configurar.
  Ninguno de estos valores se modificó; no se relajó configuración de producción.
- Sanctum usa guard web en sus rutas originales. La sesión humana nativa mantiene
  token de 8 horas con digest separado; no puede autenticar otras APIs Sanctum.
- `App/Http/Middleware/TrustProxies.php` contiene wildcard histórico, pero el pipeline
  registrado usa el middleware de Illuminate sin esa configuración. La prueba negativa
  comprueba que X-Forwarded-Proto/Forwarded no convierten HTTP en HTTPS humano.
- CSRF web no se cambió. La excepción preexistente sólo es `api/v1/device/*`;
  la ruta nativa humana continúa separada de la autenticación web basada en cookies.

## Comparación y decisión HTTPS

| Opción | Seguridad/confianza Android | Complejidad | Dependencia | Demo / similitud productiva |
| --- | --- | --- | --- | --- |
| A. Reverse proxy LAN + CA confiable | TLS real; requiere CA explícita y limitar proxies confiables | Media/alta: dos listeners y frontera de cabeceras | LAN, sin Internet | Fiable con proxy configurado; similar a terminación TLS productiva |
| B. Certificado local OpenSSL + Apache TLS dedicado | TLS real, SAN de IP y CA explícita sólo en DEBUG | Media; sin cambiar proxies Laravel ni servidor existente | LAN, sin Internet | Elegida: menos componentes; HTTPS directo como servidor productivo, CA sólo de demo |
| C. Túnel HTTPS temporal | CA pública aceptada por Android; expone un ingreso adicional | Baja al inicio; requiere controlar exposición/proveedor | Internet y servicio externo | URL/disponibilidad dependientes del túnel; no preferida para demo LAN |

Ejecutado `tools/local-field-https/prepare.ps1` tras autorización explícita. Usa el OpenSSL instalado,
CA RSA 3072/SHA-256 de 30 días, certificado de servidor de 7 días y SAN
`IP:192.168.101.15`; listener dedicado `192.168.101.15:8443`, TLS 1.2/1.3 y FastCGI
directo. No instala servicios ni modifica Laragon, firewall o trust stores. Logs de
acceso deshabilitados; directorio y llaves fuera del webroot e ignorados por Git,
ACL limitada al usuario Windows y SYSTEM. Rechaza sobreescritura de llaves parciales.
Cadena, SAN y caducidad comprobados; Apache devolvió Syntax OK y el listener está activo.
Certificado de servidor válido del 9 al 16 de septiembre de 2026, a las 18:48:21 UTC.
Certificados, llaves y backup permanecen exclusivamente en el directorio privado
autorizado, ignorado por Git; no se publican sus contenidos en esta documentación.

Pruebas TLS reales desde PC con cURL/OpenSSL 3.0.18: validación de peer habilitada,
hostname estricto y CA explícita. GET /up devuelve 200 con verify result 0; GET a la
ruta humana profile devuelve 405 (requiere POST), con TLS válido. Hostname incorrecto
y CA no confiable son rechazados con error 60. El HTTP existente sigue devolviendo 200.
La sonda POST sin credenciales fue rechazada por la revisión automática y no se ejecutó;
el middleware HTTPS/auth se validó en pruebas aisladas. Esto no certifica login físico.
El cURL de Windows/Schannel informó revocación desconocida para la CA local sin CRL;
no se desactivó su validación ni se modificaron trust stores. La comprobación TLS se
realizó con el cliente OpenSSL del proyecto y controles peer/hostname activos.

La configuración Android DEBUG permite CA del usuario sólo para `192.168.101.15`,
sin subdominios. Para otros hosts conserva CA del sistema. No contiene trust-all,
hostname verifier permisivo ni cambios al validador TLS. La CA pública debe instalarse
explícitamente por el operador después de cotejar su fingerprint. Una CA de usuario
también puede ser confiada por otras aplicaciones que opten por ese almacén: no es
una acción automática ni debe instalarse sin consentimiento.

RELEASE no referencia esta configuración: conserva cleartext false y confianza estándar.
La excepción HTTP DEBUG previa de máquina se conserva; `FieldMobileTransport` continúa
rechazando URLs humanas HTTP antes de enviar credenciales. El APK se compiló con
`VITE_FIELD_IDENTITY_BASE_URL=https://192.168.101.15:8443` sólo en el proceso de build.
No se modificó ningún .env ni VITE_API_BASE_URL.

Fuentes primarias: [Android Network Security Configuration](https://developer.android.com/privacy-and-security/security-config),
[Apache SSL](https://httpd.apache.org/docs/2.4/ssl/),
[Apache proxy/FastCGI](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html).
La verificación física debe usar el transporte nativo, no sólo el navegador del PC.

## Administración y RBAC

Vending tiene permisos de instalación, no el tenant SaaS del otro repositorio DSSIA
(ver `docs/architecture/vending-field-support.md`). El encargo aprueba explícitamente
administración global de esta instalación para Pilot Admin.

- Nuevo catálogo: `employee_device.view` y `employee_device.manage`.
- No se reutiliza `support.manage`, `settings.manage` ni `biometrics.face.manage`.
- `/administration/identity`: auth web, identidad activa y permiso explícito fresco.
  Disponible sólo local/testing en esta fase. Tres secciones: Resumen, Dispositivos,
  Enrolamientos; navegación visible sólo con permiso propio.
- Contadores por estado derivados de DB. Dispositivos paginados de 20 en 20;
  empleados de 25 en 25. Proyección explícita: teléfono enmascarado, sin llaves,
  huellas, hashes de solicitud ni propietarios seleccionables por payload.
- Demo local NO se presenta como verificación telefónica. Firma comprobada indica
  prueba histórica; estado activo/revocado se muestra separadamente. Biometría no habilitada.
- Enrolamientos reconoce ausencia de plantilla y también registros previos reales
  mediante indicador existente o relación faceTemplates; no los inventa ni los modifica.
- Revocación: permiso manage, confirmación explícita, CSRF web, transacción y orden
  de locks compatible con registro/firma (owner, Employee, Device). Conserva ownership,
  clave e historial, consume challenges pendientes y audita `ADMIN_DEVICE_REVOKED`
  con administrador responsable y empleado afectado. Es idempotente.
- Reemplazo sigue exigiendo código, nueva clave y prueba del titular; la vista explica
  ese flujo y no ofrece activación forzada. La administración completa de reemplazo y
  de enrolamiento biométrico no se implementa como bypass de la prueba física.
- Support sigue exclusivamente User autenticado → Employee vinculado → dispositivo propio.
  Operator/Viewer no obtienen administración de identidad. Resolver SupportActivity intacto.

### Escrituras RBAC reales autorizadas y comprobadas

Después del bloqueo inicial, el operador autorizó directamente esta mutación exacta.
Se creó backup privado de 35,135 bytes, se verificó por SHA256 antes de la transacción
y se añadieron únicamente employee_device.view (ID 76) y employee_device.manage (ID 77)
al rol ID 2 Vending Pilot Admin, cuyo único miembro es User 2 pilot.admin@example.test.
Permisos: 75 → 77; permission_role: 38 → 40. Todos los registros anteriores de roles,
role_user, permisos y permission_role se compararon con el backup: idénticos.
Support/Operator/Viewer no tienen ninguno de los dos permisos. Ningún piloto recibió
biometrics.face.manage. Auditoría ID 2717, actor system, ejecución CLI bajo autorización
explícita del operador; no se simuló un login de Pilot Admin.
Pilot Admin tiene acceso autorizado a la pantalla; la revisión visual sigue pendiente.

## Validación

- Backend dirigido Identity/Auth/Permissions/UserEmployee/AttendanceAuthorization:
  100 PASS / 817 assertions. Incluye 6 pruebas nuevas de esta fase.
- Suite completa: 756 PASS / 1 FAIL, 6373 assertions. Única falla:
  OnPremDiagnosticsCommandTest, la misma documentada en baseline. Nuevas regresiones: 0.
- Frontend: 128 PASS, incluidas 4 nuevas de administración.
- Mobile: 281 PASS. Android native: 7 PASS (2 nuevas de aislamiento de confianza).
- Build web/mobile, cap sync android y assembleDebug: PASS.
- Pint scoped y git diff --check: PASS.
- Security scan: 1236 archivos versionables y 1063 entradas descomprimidas del APK;
  cero coincidencias del teléfono configurado, sin imprimirlo. Sesiones humanas reales: 0.
- Parse PowerShell: 0 errores. Configuración Apache/certificado: PASS técnico.
- Manifest DEBUG fusionado: debuggable true, cleartext true, Network Security Config
  de demo. RELEASE comprobado en fuentes y prueba nativa: cleartext false, sin CA demo.
- Revisión visual real 1920×1080 / 1366×768 y HONOR: PENDIENTE. Los tests SSR no son
  certificación visual. Se aplicó design-web-frontends para reutilizar tokens, navegación,
  estados vacíos, paginación, confirmación y presentación responsive sin tabla excesiva.

APK preparado, NO instalado:
`mobile/android/app/build/outputs/apk/debug/app-debug.apk`

Tamaño: 29,963,916 bytes.
SHA256: `77572abc2cf22fee5de89e480bbccf572708cb64b7c6dd2c2a389ec5cc287b49`.
Advertencias no bloqueantes del build: Browserslist antiguo, Tailwind/Ionic y chunk grande.

## Datos y siguiente gate

Antes/después: empleados 2507, usuarios 5, assignments 7, attendance_logs 0,
vending_attendance_events 19, tickets 2, actividades 0. FIELD_MOBILE/OTP/challenges/audit
de identidad: 0. Vínculo User 4 → Employee 5 intacto. PHONE LOCAL_SIMULATED,
phoneVerified=false; ningún teléfono se copió a Employee/Fortia, tests o documentos.
SYBI 7: DRAFT, sin geofence/Device, assignment 7 autorizado intacto. Fortia clean.
Phase 14 preservada. La telemetría del terminal continúa actualizándose externamente.

Demo-preflight final: PASS, heartbeat 7 s/<180 s, ONLINE, config 3/3 y empleados 5/5,
outbox 0, HIGH 0, MEDIUM 2 informativas. Esto certifica evidencia del terminal, NO el
login HTTPS humano físico pendiente ni un enrolamiento FIELD_MOBILE.

Los bloqueos iniciales de CA/listener y RBAC quedaron resueltos mediante autorización
directa del operador, sin eludir la revisión automática. Se repitieron los 100 tests
backend, 128 frontend y 7 nativos y todos los builds: PASS. La suite completa indicada
arriba corresponde al gate inmediatamente anterior; no hubo cambios de código funcional.

Siguiente gate: solicitar HONOR conectado, desbloqueado y Vending Attendance abierta.
Pendientes de autorización/acción física: instalar CA pública tras comprobar su huella
y `adb install -r` del APK preparado; no se realizó ninguna de estas operaciones.
Validar conexión nativa en HONOR antes de pedir que el operador escriba sus credenciales.
No capturar pantalla/jerarquía UI durante la introducción de contraseña.

Login, sesión, perfil, logout, expiración y reinicio pasan automatizados; físicamente
NOT_RUN. No solicitar OTP ni registrar FIELD_MOBILE hasta el gate físico autorizado.
No Face ID, asistencia, SupportActivity, borrado, reprovisionamiento, commit, tag, push o deploy.

## Archivos de esta entrega (comparados con hashes iniciales)

Creados:

- app/Http/Controllers/FieldIdentity/DeviceAdminController.php
- docs/operations/vending-field-https-admin-review.md
- mobile/android/app/src/debug/res/xml/field_demo_network_security.xml
- mobile/android/app/src/test/java/com/medicalife/vendingattendance/FieldNetworkSecurityTest.java
- resources/js/Pages/FieldIdentity/Index.vue
- routes/field-identity-web.php
- tests/Feature/FieldIdentity/DeviceAdminTest.php
- tests/Frontend/fieldIdentity.test.js
- tools/local-field-https/httpd.conf
- tools/local-field-https/openssl.cnf
- tools/local-field-https/prepare.ps1

Modificados sobre los cambios previos, sin sobrescribirlos:

- .gitignore
- config/permissions.php
- mobile/android/app/src/debug/AndroidManifest.xml
- resources/js/presentation/navigation.js
- routes/web.php
- tests/Feature/FieldIdentity/FieldMobileUxTest.php
