# Cambio de red local a 192.168.1.87

Fecha: 2026-09-16. Solicitado por el operador. Origen local nuevo: `https://192.168.1.87:8443`.

## Configuración

- La interfaz Wi-Fi del PC confirma la IP nueva. `.env` ya tenía APP_URL y VITE_API_BASE_URL correctos; se conservaron sus valores y se limpió únicamente la caché de configuración Laravel.
- `mobile/.env.local`: API e identidad cambiaron desde `https://192.168.101.15:8443` al origen nuevo.
- Configuración Apache aislada: `tools/local-field-https/httpd-lan-192.168.1.87.conf`, con PID/log/cache propios. No se modificó el Apache general de Laragon ni configuraciones beta/producción.
- Certificado con SAN de la IP nueva, firmado por la CA local existente y reutilizando la clave servidor existente de LAN80. No se generaron claves nuevas ni se modificó la confianza del sistema/teléfono. El certificado local dura siete días; la renovación es independiente de futuros cambios de red.
- Inicio/validación: `powershell -File tools/local-field-https/start-lan-192.168.1.87.ps1 -Start`. Requiere acceso al directorio TLS privado existente. Sin `-Start` sólo prepara/verifica certificado y sintaxis; no duplica un listener existente.
- Builds web/móvil regenerados y assets sincronizados a Android. No se cambiaron código de negocio, datos, usuarios, permisos, asignaciones ni geocercas mediante scripts de esta tarea.

## Android local

El teléfono conectado tenía versionCode 10. El source ya contemplaba versionCode 11, todavía sin artefacto en el cierre anterior. La compilación de esta LAN utiliza ese 11 y conserva versionName `1.0.1-beta.1`; es variante debug local, no beta pública ni release.

Parámetros nativos de esta compilación, además de `-PinternalBeta=true`:

```powershell
$env:JAVA_HOME = 'C:\Program Files\Android\Android Studio\jbr'
$env:LOCAL_DEBUG_CA_HOST = '192.168.1.87'
$env:LOCAL_FIELD_IDENTITY_BASE_URL = 'https://192.168.1.87:8443'
$originHasher = [System.Security.Cryptography.SHA256]::Create()
$env:FIELD_RECOVERY_PREVIOUS_ORIGIN_SHA256 = ([BitConverter]::ToString($originHasher.ComputeHash([Text.Encoding]::UTF8.GetBytes('https://192.168.101.15:8443')))).Replace('-','').ToLowerInvariant()
$originHasher.Dispose()
# Desde mobile/android, después del build móvil y cap sync android:
.\gradlew.bat --offline --no-daemon -PinternalBeta=true testDebugUnitTest assembleDebug
```

La CA de usuario queda limitada al nuevo host en debug. El permiso de recuperación permite sólo origen anterior exacto → origen nuevo exacto; sigue requiriendo sesión humana y challenge con clave existente. No OTP, enrolamiento ni reemplazo de identidad como solución a cambio de IP.

## Verificación

- Certificado: cadena CA y hostname/IP PASS; sintaxis Apache PASS.
- HTTPS `/up` y `/login`: HTTP 200 con validación TLS, sin bypass.
- Página de acceso: 11 assets HTTP 200 desde nuevo origen; sin referencias a IPs anteriores.
- Build web y build móvil/typecheck: PASS; avisos móviles de CSS/chunk ya conocidos.
- Pruebas dirigidas de recuperación/configuración móvil: 46/46 PASS.
- Android `testDebugUnitTest assembleDebug`: PASS; 11 tests, 0 fallos/errores.
- APK: manifiesto de deployment interno confirma API/identidad nuevas; BuildConfig confirma target nuevo y SHA256 del origen anterior; política debug confirma confianza CA de usuario sólo para 192.168.1.87.
- Firma APK verificada: SHA256 del certificado `2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec`, coincide con la firma histórica. apksigner emitió avisos sobre entradas META-INF; validación terminó con exit 0.
- APK generada: `mobile/android/app/build/outputs/apk/debug/app-debug.apk`; SHA256 `17b9ac4b09fa658a70f421a3bef9bf1da0cd706ca12ef1f03a47bc8eaae83607`.
- Instalación por `adb install -r`: Success. Android confirma versionCode 11 / versionName 1.0.1-beta.1. Sin uninstall, clear-data, OTP ni reprovisioning. Esto acredita actualización compatible; no sustituye comprobación autenticada de identidad ni hashes de base de datos.
- App abierta mediante MainActivity; conectividad teléfono → PC comprobada por ping (1/1). ICMP no acredita por sí solo TLS dentro de la app; login/challenge autenticado queda por comprobar por el titular.

Build 11 queda consumido como artefacto LAN local; el siguiente artefacto actualizado debe incrementar versionCode por encima de 11. La propuesta histórica de checkpoint público debe considerar este nuevo baseline sin renombrar sus tags automáticamente.

Sin commit/tag/push, migraciones ni suite PHP completa: el cambio es configuración local y empaquetado. Configuraciones/certificados anteriores se conservan; no se detuvieron servicios ajenos. La validación del login autenticado y la identidad en pantalla requiere al titular si la sesión solicita autenticación nuevamente.

Revisión Git: se conservan rama `phase/14-biometric-engine-selection`, HEAD `ea5852d` y los 64 M / 1 D previos; índice vacío. Nuevos archivos propios: este documento y tres archivos LAN87 bajo `tools/local-field-https/`. `mobile/.env.local`, certificados y builds son locales/ignorados. Diff/whitespace PASS. Los errores iniciales EPERM de npm se resolvieron con ejecución autorizada fuera del sandbox; no hubo pruebas funcionales fallidas.
