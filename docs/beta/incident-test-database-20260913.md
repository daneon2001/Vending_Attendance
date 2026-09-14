# Incidente durante Phase 13.7.2.1

2026-09-13 UTC / 2026-09-12 CDMX.
**BLOCKED: LIVE_DATABASE_RESTORE_REQUIRES_AUTHORIZATION.**

## Hecho e impacto

La ejecución del agente de `php artisan test tests/Feature/FieldIdentity` usó
`bootstrap/cache/config.php`, que fijaba environment=local y la conexión MySQL
`127.0.0.1 / vending_attendance_dev`. Las variables de proceso que solicitaban
testing/sqlite/:memory: no prevalecieron sobre esa caché.

RefreshDatabase se ejecutó antes de las aserciones de aislamiento ubicadas en
los setUp de los tests concretos. La suite terminó con 51 fallos. La lectura
posterior confirmó empleados=0, vending_attendance_events=0, actividades=0 e
identidades=0 en la DB de uso. Esto fue causado por la ejecución del agente;
no es un fallo del login del operador. El baseline vivo NO está preservado.

Se detuvieron la validación backend y la instalación. No hubo login físico,
OTP ni invocación de generación de claves Android. Las APK build 2 y 3 y el
almacenamiento Android no se modificaron en esta fase. La corrección de código
de recuperación sigue sin validación completa ni APK instalada.

## Respaldo y recuperación preparada

Respaldo local verificado:
`storage/app/backups/mysql/Vending_Attendance-vending_attendance_dev-20260911_230904-utc.zip`.
SHA256 `6d090f4bbd3111755229c567d3a428e864fac63ac28e580cf6fd7f9f479fa5cd`.
ZIP íntegro. El dump no contiene USE ni CREATE DATABASE para redirigir destino.

Se restauró exclusivamente en una base NUEVA aislada:
`vending_attendance_restore_test_20260913_incident`.
Validación 2026-09-13T04:24:16Z, archivo privado
`storage/app/private/phase-13.7.2.1-incident/recovery-validation.json`.

Las 14 tablas protegidas coinciden por SHA256 con el baseline previo a este
incidente. Se recuperan 2507 empleados, 5 usuarios, 8 asignaciones, 22 eventos
vending, 0 attendance_logs, 2 actividades, 8 eventos de actividad, notas/fotos,
máquinas/geocercas y el mismo employee_device/OTP. La tabla employee_devices
completa coincide, incluidos UUID, fingerprint, key_version, owner y activated_at.

**La base de uso no se ha restaurado todavía.** Se solicitó autorización explícita
para restaurarla desde ese respaldo, conservando antes el estado afectado.
Sesiones, auditoría y telemetría posteriores al respaldo no están certificadas
como recuperables; no confundir igualdad de 14 tablas con igualdad de toda la DB.
Las fotos del disco privado se preservaron en la fase anterior y no se borraron.

El primer nombre propuesto para la base aislada excedió el límite del servidor;
CREATE DATABASE fue rechazado sin crearla. Se usó el nombre corto anterior.

## Protección preventiva incorporada, sin cambio de lógica de producto

`tests/TestCase.php::createApplication()` ahora comprueba configuración EFECTIVA
antes de que los traits de setup puedan ejecutar RefreshDatabase:
environment testing, conexión sqlite, database :memory:, sin DB_URL.
Si falla, lanza excepción y no comienza las migraciones del test.

`phpunit.xml` fuerza esos valores y una ruta de caché exclusiva de pruebas, para
no reutilizar la caché del servidor local. No se eliminó ni modificó la caché viva.

Dos preflights sin migraciones comprobados:

- Caché local existente: REJECTED antes de setup DB, esperado.
- Caché de pruebas independiente: testing / sqlite / :memory:, confirmado
  por la conexión efectiva.

No se presenta esta protección como restauración de los datos. La recuperación
de la DB sigue pendiente de autorización y comprobación posterior.

## Código de recuperación en progreso

Cambios de flujo, referencia de identidad persistente, inspección de fingerprint
y política DEBUG de transición fueron preparados. Mobile dio 329 PASS antes del
último test adicional de diagnóstico. El build detectó un tipo de UUID demasiado
estrecho en un mock; se corrigió pero no se certificó un nuevo build después del
incidente. Backend 51 FAIL pertenece a la ejecución inválida, no cuenta como
evidencia de seguridad ni de recuperación. No se generó ni instaló build 4.

No commit, tag, push ni deploy. No declarar READY_FOR_PHYSICAL_SESSION_RECOVERY.
