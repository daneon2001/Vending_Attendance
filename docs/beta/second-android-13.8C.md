# Phase 13.8C: gates previos al segundo Android

Estado inicial: PARTIAL, pendiente de conexión de Android B. Sólo se detectó el HONOR A por adb devices; no se le enviaron comandos de modificación, instalación, navegación ni diagnóstico activo.

## Gate 0: reconciliación de APK

SECOND_ANDROID_APK: `storage/app/private/phase-13.8A-implementation/VendingAttendance-1.0.1-beta.1-build9-medical-life-one.apk`.

| Artefacto | versionName / code | Timestamp del archivo UTC | Tamaño | SHA256 |
| --- | --- | --- | --- | --- |
| build 8 LAN | 1.0.1-beta.1 / 8 | 2026-09-14 15:38:02 | 29284838 | 4b6a9dc161b42de2a896caa89265c634926b9580c8e869cbe82a2e399d4039d2 |
| build 9 Medical Life One | 1.0.1-beta.1 / 9 | 2026-09-14 16:16:32 | 31734704 | fae73bfda7f3c8358c62db15109ee3747212d5e3d82fbd3201c78dfdac911cc9 |

Build 8 contiene el retorno de origen y TLS corregidos, pero usa la marca anterior. Build 9 deriva de esa implementación y añade la marca aprobada; no se elige sólo por su número. Se revalidaron versionCode/versionName, firma, endpoint dentro del bundle, presencia de contexto device_uuid/OTP y hash exacto del símbolo de marca empaquetado frente al asset aprobado. XML compilado restringe la CA de usuario al host 192.168.101.15. El certificado firmante es el mismo: 2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec.

Lineage: HEAD base ea5852d151151014214e660abfd3032414997d44, branch phase/14-biometric-engine-selection, más cambios de trabajo sin commit de 13.8A, corrección LAN build 8 y marca build 9. No existe un commit exclusivo para build 9 ni se afirma reproducibilidad desde HEAD solo. Pruebas documentadas al compilar: 41 móviles y 11 nativas PASS. No se volvió a ejecutar suite durante este gate.

A/B/C (política multi-tester, teléfono privado, excepción MANUAL) se implementan en el backend, no dentro del APK ni como datos de tester empaquetados. Se verificó su configuración efectiva con User 6 → Employee 12, política habilitada/vigente y resolver PASS. D/E/F/G están presentes en cliente + backend y lineage: aislamiento OTP, recuperación por origen, HTTPS y marca. Ninguna política privada, teléfono o credencial debe incorporarse al APK.

## Gates 1 y 2

IP actual observada por ipconfig: 192.168.101.15, Wi-Fi. HTTPS efectivo https://192.168.101.15:8443. /up devuelve HTTP 200 con validación de CA/hostname y SSL_VERIFYRESULT=0 desde la PC. Esto no sustituye Gate 5 dentro del Android B real: aún NOT_RUN.

CA pública vigente hasta 2026-10-09 18:48:20 UTC. Fingerprint SHA256 F575BA30AE81559CE5DA3BCA01C70B58D665B5E9E8977E5F797EE3BCB1B7785A. No se copiaron certificados ni claves a ningún dispositivo.

Baseline nuevo privado: `storage/app/private/phase-13.8A-implementation/phase13.8C-before.json`, SHA256 b0a03c642791b98b8f0de9274ea3d242e0e73e8acda34f13405a50666eca3f69. Contiene hashes/conteos de conjuntos protegidos e identidad pública de A, no teléfonos ni credenciales.

Employees 2507; Users 6; attendance 0; eventos vending 22; actividades 2; FIELD_MOBILE 1 ACTIVE (A); B inexistente. Assignment 9 mantiene maintenance=true, attendance=false, enrollment=false. Política B vigente hasta 2026-09-28T16:37:44Z. No OTP ni challenges B. ASISTENCIAS_FORTIA sin acceso/escritura, SYBI 7 preservado.

## Gates pendientes

Conectar B, confirmar fabricante/modelo/versión/serial y ausencia de estado/restauraciones anteriores. Sólo entonces transferir CA pública si hace falta y pausar para instalación manual. Instalar el artefacto reconciliado según autorización vigente; Gate 6 (instalación) debe preceder operativamente a la comprobación Gate 5 dentro de la APK, siempre antes del login. Sin bypass TLS.

Login y OTP requieren intervención privada del titular, sin capturas durante introducción ni códigos en logs. No iniciar actividad de soporte hasta autorización independiente del gate opcional. No modificar A, ni crear asistencia.

FINAL actual: BLOCKED:ANDROID_B_NOT_CONNECTED.
