# Central de Asistencias (MVP)

## Que hace

Modulo web para centralizar checadas en `/admin/asistencias` con:

- Consulta unificada de registros crudos (`attendance_logs`)
- Filtros por fecha, empleado, unidad, reloj, tipo, fuente y estatus
- Anulacion logica de registros (sin borrar)
- Ajuste manual con registro de motivo
- Bitacora de cambios antes/despues por cada accion
- Exportacion del resultado filtrado en `CSV` o `Excel (.xls compatible)`

## Tablas que usa

- `attendance_logs` (existente, extendida para centralizacion)
  - Nuevos campos: `source`, `attendance_status`, `adjustment_reason`, `annulled_at`, `annulled_by_user_id`
- `attendance_changes` (nueva)
  - Auditoria de anulaciones y ajustes manuales
- `attendance_dailies` (nueva base para consolidado diario)

## Reglas base de consolidacion (pendientes de ajuste fino)

Configurables en `config/attendance.php`:

- `entry_log_types`: por defecto `[1]`
- `exit_log_types`: por defecto `[2, 4]`
- Primera marca del dia en ventana de entrada = entrada diaria
- Ultima marca del dia en ventana de salida = salida diaria
- `dedup_window_seconds`: deduplicacion por ventana (default `90s`)
- `entry_tolerance_minutes` y `exit_tolerance_minutes` para tolerancias operativas
- `allow_multiple_in_out` para conservar conteo de marcas intermedias

Servicio base: `App\Services\Attendance\AttendanceConsolidationService`

- `consolidateRange($from, $to, $filters = [])`
- `consolidateDay($workDate, $filters = [])`

## Permisos

Se agrega modulo de permisos `asistencias` con acciones:

- `asistencias.view`
- `asistencias.export`
- `asistencias.edit`
- `asistencias.admin`

El proyecto maneja permisos por `module/action`, por lo que estas llaves se generan como:

- `module = asistencias`
- `action = view|export|edit|admin`

Seeder relacionado: `database/seeders/RolePermissionSeeder.php`.

## Compatibilidad

- No se modifica el flujo de captura/sync existente.
- Endpoint de captura actual sigue en `Api\\AttendanceController@storeFromDevice`.
- Solo se enriquecen campos cuando existen (`source`, `attendance_status`) para no romper ambientes legacy.
