# TA-0C — Lectura legacy para Vending Pilot Admin

Decisión aprobada: añadir exclusivamente `asistencias.view` al rol
`Vending Pilot Admin` para consulta durante desarrollo/demo. No concede
`asistencias.edit`, `asistencias.export` ni `settings.manage`.

`/admin/asistencias` consulta `attendance_logs`, no `vending_attendance_events`.
El evento físico Build 12 STORED no tiene por qué aparecer en esta pantalla.
El mismo permiso permite consultar `/attendance-cards`.

## Aplicación reproducible

- Instalaciones nuevas: `VendingPilotUsersSeeder` incluye el permiso en su
  definición administrativa; mantiene el mecanismo aditivo existente.
- Pilotos existentes: ejecutar únicamente
  `php artisan db:seed --class=VendingPilotAttendanceReadSeeder` después de
  comprobar que el destino es el entorno local de desarrollo autorizado.
- El seeder puntual sólo acepta local/testing, exige el rol administrado y
  la definición de permiso existentes, y usa transacción, bloqueo del rol
  y `syncWithoutDetaching`. No crea cuentas, no cambia contraseñas/bindings,
  no sincroniza todo el catálogo y no elimina permisos previos.
- No requiere migración de esquema ni ejecutar los seeders globales de RBAC.
  No usar `permissions:fix-admin` para esta ampliación mínima.

Si el rol ya tuviera otros privilegios, este cambio no los revoca: la garantía
es añadir sólo lectura y preservar lo existente. Los roles Demo Admin,
Pilot Operator, Pilot Support y Pilot Viewer no reciben nuevas asignaciones.

## Regresión

`tests/Feature/Permissions/PilotAttendanceReadTest.php` usa SQLite `:memory:`,
guards activos, middleware real y bloqueo de HTTP externo. Comprueba:

- instalación nueva con lectura, sin edición/exportación/configuración global;
- GET de asistencias y tarjeta permitido; exportaciones, ajuste, anulación
  y configuración rechazados;
- actualización repetida sin duplicar relaciones ni eliminar permisos;
- conservación de las demás relaciones RBAC, usuarios y bindings;
- rechazo del seeder puntual en producción.

Tras aplicar en local, comprobar permisos mediante lectura y refrescar la
pantalla. No hace falta cambiar middleware ni extraer cookies de la sesión.

## Resultado de esta fase

- Safety: PASS, 12 tests / 71 assertions.
- Prueba específica: PASS, 3 tests / 25 assertions.
- Permissions/Auth/Attendance y seeder piloto: PASS, 57 tests / 489 assertions.
- Suite PHP completa: PASS, 876 tests / 7389 assertions.
- Aplicación local: ambiente local, base de desarrollo verificada en loopback.
  Dos ejecuciones del seeder puntual añadieron exactamente una relación.
  Comparadores internos confirmaron usuarios, roles, catálogo de permisos,
  bindings de usuarios y demás relaciones de permisos sin cambios.
- Lectura posterior: view=YES; edit/export/settings.manage=NO.
- Validación HTTP: rutas reales en SQLite/testing. No se automatizó una sesión
  privada del navegador; el usuario debe refrescar la página local.
