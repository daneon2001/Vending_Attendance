> Actualización 13.7.1 (2026-09-11): el operador confirmó revisión visual y
> funcional de todos los módulos web/APK, resolución, offline, restart y reconnect.
> PASS del alcance reportado; capturas directas adicionales: Mis actividades y
> Resumen de operación. Los pendientes de abajo describen la primera iteración,
> no el estado final. Véase [cierre vigente](internal-beta-closeout-13.7.1.md).
> No se inventan capturas por rol/resolución ni se repiten pruebas físicas.

# Phase 13.7 — revisión visual, iteración 1 pendiente

11 septiembre 2026. Instalación expresamente autorizada por usuario, sin nuevas
operaciones de negocio. Skill design-web-frontends: evidencia antes de corregir;
no se ha demostrado un defecto que requiera modificar código en esta ejecución.

## Instalación física

- HONOR DNY-NX9 conectado por ADB, serial autorizado AX3C026107002120.
- APK candidata verificada antes de instalar:
  SHA256 0AA559A881AC0A26B1CD35EA3759838AD9C90FAA6B515FE2AC03EA7013F491B4,
  29,377,422 bytes. Sólo adb install -r; resultado Success.
- Package com.medicalife.vendingattendance. Android confirma versión
  1.0.1-beta.1, build 2; antes 1.0/build 1.
- firstInstallTime permanece 2026-09-04 16:03:36; actualización 2026-09-11
  10:28:01. Ambas SQLite existentes siguen presentes.
- Apertura explícita de MainActivity: Status ok, COLD, 1188 ms.
- No uninstall, pm clear, borrado de datos/Keystore, reprovisionamiento ni nueva
  identidad. No se extrajo Secure Storage ni se generó otra keypair.

## Evidencia visual actual

Captura real del HONOR: Home con logo oficial, nombre Vending Attendance /
Medical Life, acciones visibles, estado En línea, sincronización y cero
asistencias pendientes. Diagnóstico e información y detalle de terminal visibles.
Sin clipping aparente en ese encuadre. No constituye aprobación de otras pantallas,
launcher/splash transitorio, teclado, flujo offline o permisos personales.

Un toque de navegación por coordenadas fue bloqueado por el control de seguridad
antes de ejecutarse. No se intentó eludirlo. La lectura de accesibilidad no produjo
un árbol utilizable; se solicitó al operador abrir Diagnóstico manualmente.
No se pulsaron acciones de negocio.

Pendientes: Diagnóstico, Mi dispositivo, actividades/detalle/notas/evidencia,
launcher/splash y navegación; revisión web externa 1920×1080 / 1366×768 según
internal-beta-checklist.md. No se solicitaron credenciales ni se capturaron.
La comprobación de uso de la clave/sesión desde esta nueva APK sigue pendiente;
conservar archivos y binding backend no acredita por sí solo una firma física nueva.

## Baseline tras apertura

- Employees 2507, users 5, assignments 8.
- attendance_logs 0; vending_attendance_events 22.
- Dos actividades, ocho eventos de timeline, dos tickets. Hashes protegidos iguales
  al baseline previo a instalar, incluyendo todas las asistencias.
- Mismo FIELD_MOBILE ACTIVE, mismo owner y fingerprint; employee_devices 1,
  OTPs 1 y challenges 46, sin incremento.
- PHONE LOCAL_SIMULATED; contrato phoneVerified=false intacto.
- SYBI 7 DRAFT, sin geofence ni Device; assignment 7 mismo hash.
- ASISTENCIAS_FORTIA clean. Phase 14 y biometría sin modificaciones.
- La telemetría automática de terminal sí actualiza su conexión al abrir la app;
  esto no añade asistencias ni operaciones de soporte.

No cambios de código ni nuevos tests/build necesarios. Referencia candidata:
Laravel 414 PASS, Frontend 132 PASS, Mobile 317 PASS, Android native 9 PASS;
build web/mobile/cap sync/assembleDebug PASS. git diff --check PASS.

ANDROID APK INSTALLED: YES
ANDROID VISUAL: PARTIAL
WEB 1920 / WEB 1366: PARTIAL
BASELINE: PRESERVED
FINAL: READY_FOR_ANOTHER_VISUAL_ITERATION (esperando navegación/capturas del operador).

No certificación visual global, distribución a compañeros ni segundo enrolamiento.
Sin commit, tag, push ni deploy.
