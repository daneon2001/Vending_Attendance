# Fase 13.6C.2 — habilitación controlada de esquema MySQL local

Fecha: 2026-09-08. PHASE 13.6C.2: PASS.
Autorización: aplicar sólo esquema Field Support tras respaldo, sin datos de actividad.
La confirmación visual externa de C.1 fue aprobada por el usuario antes de esta fase.
No se modifica código ni se implementa 13.6D / Phase 14.

## Identidad y baseline

Rama preservada: phase/14-biometric-engine-selection.
HEAD: 702b641ef803793d025903435b81a26fc1f48a0c.
Se registraron git status --short, rama, HEAD, diff check y hashes de 1187 archivos
existentes, conservando el trabajo previo sin stage/commit/reset.

Configuración efectiva comprobada mediante Laravel y SELECT DATABASE():

- DB_CONNECTION: mysql.
- DB_HOST: 127.0.0.1.
- DB_PORT: 3306.
- DB_DATABASE: vending_attendance_dev.
- Gate local: PASS. No se imprimieron usuario ni contraseña de DB.

| Datos | Antes | Después |
|---|---:|---:|
| users | 5 | 5 |
| employees | 2507 | 2507 |
| employee_machine_assignments | 7 | 7 |
| attendance_logs | 0 | 0 |
| vending_attendance_events | 19 | 19 |
| support_tickets | 2 | 2 |
| vínculos users.employee_id no nulos | 0 (columna ausente) | 0 |
| vending_support_activities | tabla ausente | 0 |
| vending_support_activity_events | tabla ausente | 0 |

Los 2502 empleados MANUAL y 5 DEMO se conservan, sin reclasificación ni creación de Users.
Comparación SHA-256 de filas ordenadas por id: las seis tablas de negocio de la tabla
anterior conservan íntegros sus valores. En users se comparan las columnas previas,
excluyendo únicamente employee_id nueva; se comprueba que sus cinco valores son NULL.
No se imprimieron filas ni datos personales/credenciales.

SYBI 7: RESERVED; proyección DRAFT, 0 geofences, 0 Devices, 1 assignment autorizado.
Assignment 7 conserva el hash completo de su fila; no se cambió estado, permisos ni fechas.
ASISTENCIAS_FORTIA clean. Phase 14 preservada.

## Inventario y revisión previa

migrate:status mostró exactamente estas tres pendientes; 100 migraciones previas aplicadas:

| Migración (prefijo 2026_09_08_) | Propósito | Tablas afectadas | Riesgo revisado |
|---|---|---|---|
| 140000_add_employee_link_to_users_table | Vínculo opcional 1:1 | users | ALTER/FK/UNIQUE; posible bloqueo de metadatos; 5 usuarios |
| 150000_create_vending_support_activities | Actividad e historial duradero | vending_support_activities, vending_support_activity_events nuevas | Creación y FK RESTRICT; sin filas iniciales |
| 160000_index_support_activity_queries | Índices de estado/fecha | vending_support_activities nueva | Índices sobre tabla vacía |

Se leyeron up/down completos y se ejecutó migrate --pretend con las tres rutas exactas.
Forward SQL: ADD nullable, FK/UNIQUE, CREATE de dos tablas, ADD INDEX.
Sin DROP, TRUNCATE, DELETE, backfill, seed ni UPDATE de negocio.
No se ejecutó ninguna migración ajena al alcance.

## Respaldo privado

Directorio ignorado por Git, fuera del árbol público servido por la aplicación:

C:/laragon/www/vending-attendance/storage/framework/local-mysql/schema-enable-20260908T234122Z-838685f9

ACL sin herencia: acceso sólo a la cuenta local ejecutora y SYSTEM.
No se guarda la contraseña de conexión en archivo ni argumento de CLI.
mysqldump recibe la credencial desde la configuración efectiva, únicamente en el
entorno de su proceso hijo; no se imprime comando expandido ni contenido del dump.

| Archivo | Bytes | UTC | SHA-256 |
|---|---:|---|---|
| field-support-before.sql | 2247434 | 2026-09-08T23:42:05+00:00 | 2e1ac4335d10ce4a7c348e162fb0e7cc76bc21bea98d876113fe1c30f04df129 |
| referenced-schema.sql | 12177 | 2026-09-08T23:42:06+00:00 | 990bf9e34334ba56baa63834d629d0be00e95ff44218215f1f7fc9f543865686 |

field-support-before.sql contiene definición y datos de users (5), employees (2507),
employee_machine_assignments (7), migrations (100).
referenced-schema.sql contiene sólo definiciones de vending_machines, support_tickets
y machine_geofences para documentar dependencias, no datos sensibles adicionales.

Se eligió respaldo acotado porque sólo users preexistente se altera; las otras tablas
de actividad son nuevas. No es un respaldo completo de recuperación de toda la instalación.

mysqldump 8.4.3: single-transaction, sin bloqueos de tablas, hex-blob, utf8mb4,
columnas explícitas, un INSERT por fila, sin DROP TABLE y sin GTID/tablespaces.
Verificación: exit 0, marcador de finalización, CREATE de cada tabla, conteos exactos
de INSERT de las cuatro tablas, tamaño y hash. No se hizo restauración de prueba
ni se afirma que el dump parcial sea una restauración autónoma de toda la DB.
El respaldo contiene datos personales y hashes de acceso: no compartir ni versionar.

## Aplicación

Comando ejecutado, en local sin --force:

```powershell
php artisan migrate --path=database/migrations/2026_09_08_140000_add_employee_link_to_users_table.php --path=database/migrations/2026_09_08_150000_create_vending_support_activities.php --path=database/migrations/2026_09_08_160000_index_support_activity_queries.php
```

Resultado: las tres DONE, batch 10, IDs de migrations 101/102/103.
Sin migrate:fresh, migrate:refresh, db:wipe, rollback ni restauración de respaldo.

information_schema confirma:

- users.employee_id bigint unsigned NULL, UNIQUE y FK employees.id ON DELETE RESTRICT.
- vending_support_activities: InnoDB, 33 columnas exactas en el orden de la migración.
- vending_support_activity_events: InnoDB, 6 columnas.
- 13 FK nuevas con RESTRICT, sin cascadas de eliminación.
- UUID único de actividad y UNIQUE(activity_id, kind) en historial.
- Índices employee/status/id, machine/status/id, status/created_at/id y created_at/id.
- Índices de apoyo FK, incluido support_ticket_id.

Nota del diagnóstico: una comprobación ad hoc contó inicialmente 34 columnas esperadas.
La migración y el SQL revisados definen 33; se reemplazó ese conteo manual por comparación
de los 33 nombres y su orden, con PASS. No se modificó ni reejecutó la migración.

## Salud y regresión

optimize:clear: PASS; sólo cachés generadas del framework, sin borrado de audit ni negocio.
vending:demo-preflight: PASS en la comprobación posterior a migrar:
heartbeat 64 s / <180 s; ONLINE; configuration SYNCED 3/3; employees SYNCED 5/5;
outbox 0; HIGH 0; MEDIUM 2 informativas. No actividad artificial para renovar heartbeat.

Regresión: 197 PASS / 1904 assertions / 21.84 s.
APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, cache/sesión array:

```text
tests/Feature/Support
tests/Feature/Vending/UserEmployeeIdentityTest.php
tests/Feature/Vending/MachineAuthorizationServiceTest.php
tests/Feature/Vending/GeofenceEditorTest.php
tests/Feature/Vending/FleetMaintenanceAndUiTest.php
tests/Feature/Auth
tests/Feature/Permissions
```

Sin tests de escritura contra DB local real. No full suite: no cambio de código.
Pint/build: no aplica en esta fase de esquema existente. Git diff --check: PASS.

## Rollback y siguientes pasos

No se ejecuta rollback cuando todo pasa. MySQL DDL tiene commits implícitos:
las tres migraciones no forman una única transacción reversible.
Ante fallo parcial, detenerse y presentar qué columnas/tablas/filas migrations existen.
No reintentar down, eliminar tablas/columnas ni importar dumps automáticamente.

Los down existentes rechazan eliminar vínculo con asociaciones o tablas con historial.
Aun vacías, cualquier reversión o restauración requiere autorización separada y revisión
de dependencias/datos escritos después del respaldo. No restaurar migrations de forma
aislada ni asumir que una restauración parcial conserva escrituras posteriores.

Sólo el esquema quedó habilitado. No hay vínculos User–Employee ni actividades reales
nuevas. Una prueba operativa requiere planificación y autorización explícita de 13.6D.1;
no se crean datos para demostrar UI ni se usa SYBI 7.

FILES CREATED: este documento y los dos SQL privados ignorados por Git.
FILES MODIFIED: ninguno de código/configuración; cachés de framework limpiadas.
No commit, tag, push ni deploy.
FINAL: READY_FOR_PHASE_13_6D_1.
