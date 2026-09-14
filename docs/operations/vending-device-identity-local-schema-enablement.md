# Phase 13.6D.1.1 — Gate 2: esquema local y vínculo DEMO

Fecha: 2026-09-09. Resultado: PASS.
Autorización explícita: backup, una migración y User 4 ↔ Employee 5.
No habilita todavía enrolamiento, OTP, claves físicas ni un FIELD_MOBILE ACTIVE.

## Objetivo y controles previos

Target efectivo: APP_ENV local, MySQL 127.0.0.1:3306, vending_attendance_dev.
Baseline: 103 migraciones, batch máximo 10. Las cuatro tablas de identidad no existían.
Users sin vínculo: 5/5. Código preexistente conservado: hashes de 1209 archivos iguales
antes/después del gate, antes de incorporar este registro documental.

Migration exacta:
2026_09_09_120000_create_field_device_identity_tables.php

SHA-256:
99132c87504eed1b7a819a2e163757b8b919bda8aa210f29b382a5d1e5a9dc98

SQL revisado sin PDO real: 21 sentencias CREATE TABLE / ALTER TABLE ADD únicamente
sobre employee_devices, field_device_otps, field_device_challenges y
field_device_audit_events. No backfill, UPDATE, DELETE de filas ni cambios a tablas
de negocio existentes. Todas las FK son ON DELETE RESTRICT. MySQL aislado PASS
previo de D.1, incluida concurrencia de dos procesos, unique y FK.

Nota de la comprobación preventiva: el primer filtro detectó DELETE dentro de
ON DELETE RESTRICT y detuvo la ejecución antes del migrate. Se confirmó que seguían
103 migraciones y ninguna tabla nueva. Se corrigió sólo el filtro ad hoc, no la
migración: se reconoce esa cláusula restrictiva y se conserva el rechazo de SQL
destructivo. La ejecución real posterior fue única.

## Backup privado, previo a cualquier escritura autorizada

Path:
C:/laragon/www/vending-attendance/storage/framework/local-mysql/device-identity-20260909T165227Z-5aa14263/identity-before.sql

Bytes: 2279336
UTC: 2026-09-09T16:53:03+00:00
SHA-256:
3a6d0a2f292e301b25f7f58b74efcc5ab28c84420e60376cb33102f6658ad909

Directorio ignorado por Git, no público, ACL sin herencia: sólo cuenta ejecutora
y SYSTEM. El dump contiene datos personales y hashes de acceso: no compartir.

Tablas incluidas con esquema/datos: users (5), employees (2507),
employee_machine_assignments (7), roles (5), permissions (75), role_user (5),
permission_role (38), migrations (103). Las tablas nuevas no existían para respaldarlas.

mysqldump 8.4.3: single-transaction, sin locks/add-locks/add-drop-table,
sin GTID/tablespaces, utf8mb4, hex-blob, INSERT explícito por fila.
Contraseña entregada exclusivamente en el entorno del proceso hijo; no en CLI,
logs ni archivos de configuración nuevos. Salida deshabilitada.
Verificación: exit 0, marcador de finalización, definición de cada tabla,
conteos exactos de INSERT, tamaño y SHA-256. Hash revalidado antes de migrar.
No se realizó restore de prueba. Es backup acotado, no restauración autónoma
completa de toda la instalación.

## Aplicación y esquema

Se invocó Artisan migrate con un único --path, sin --force:

php artisan migrate --path=database/migrations/2026_09_09_120000_create_field_device_identity_tables.php

Resultado: DONE, migrations.id=104, batch=11. Total de migraciones: 104.
No migrate:fresh, migrate:refresh, db:wipe, truncate ni migraciones adicionales.

information_schema comprobado:

| Tabla | Columnas exactas y orden | Motor | Filas |
| --- | ---: | --- | ---: |
| employee_devices | 26 | InnoDB | 0 |
| field_device_otps | 13 | InnoDB | 0 |
| field_device_challenges | 7 | InnoDB | 0 |
| field_device_audit_events | 6 | InnoDB | 0 |

9 FK revisadas: tablas/columnas destino exactas y DELETE_RULE RESTRICT.
11 índices requeridos verificados, incluidos UUID/operation UUID/fingerprint
únicos, active_employee_id único nullable bigint unsigned, y consultas de
histórico/rate limiting. No claves ni OTP insertados.

## Único vínculo real autorizado

User 4: pilot.support@example.test, activo.
Employee 5: 990001005 — Técnico Demo, source=DEMO, status=A.

Transacción con bloqueo de ambas filas y validación de IDs, email, número, nombre,
source/status, ausencia de vínculo previo y esquema aplicado.
Única actualización de negocio: users.employee_id = 5 WHERE id = 4.
No se cambió updated_at ni ninguna otra columna del User.
Total de vínculos reales: exactamente uno, 4 → 5.

No se modificaron roles, permissions, assignments ni capacidades.
El resolver global User::authenticatedEmployee() continúa devolviendo null para
este User DEMO: comprobación real de sólo lectura en CLI, sin crear sesión persistida.
SupportActivity y su resolver permanecen intactos.

DEMO EXCEPTION: NOT_REQUIRED para crear esquema y vínculo.
No se implementó aún una excepción de enrolamiento. Si se añade después, debe
quedar limitada a local/testing y al flujo de device enrollment, nunca global.
PHONE SOURCE productivo continúa BLOCKED; no se configuró ni persistió teléfono.
phoneVerified no se promovió; no existen todavía bindings reales.

## Integridad posterior

| Tabla / recurso | Antes | Después |
| --- | ---: | ---: |
| employees | 2507 | 2507 |
| users | 5 | 5 |
| employee_machine_assignments | 7 | 7 |
| attendance_logs | 0 | 0 |
| vending_attendance_events | 19 | 19 |
| support_tickets | 2 | 2 |
| vending_support_activities | 0 | 0 |
| vending_support_activity_events | 0 | 0 |

Fingerprints SHA-256 idénticos de employees, assignments, attendance_logs,
vending_attendance_events, tickets, devices, vending_machines, machine_geofences,
employee_details, roles, permissions, role_user, permission_role y ambas tablas
de actividades. users es idéntico excluyendo únicamente employee_id; los cinco
valores de esa columna se validaron aparte y sólo 4 → 5 cambió.

SYBI 7 RESERVED: DRAFT, sin geofence ni VENDING_TERMINAL, assignment 7 intacto.
Hash de assignment 7:
df2c697843e0cccdbde8bfd450e4c8fa57b178ead7541bd277e0287d61822cc7

ASISTENCIAS_FORTIA clean. Phase 14, Fortia source y empleados importados intactos.

## Salud y tests

optimize:clear: PASS. Eliminó sólo cachés generadas del framework; regenerables.
No se borraron datos de negocio ni históricos.

demo-preflight: TELEMETRY_STALE, no READY_FOR_LIVE_DEMO.
Heartbeat 62072 s / <180 s. Config STALE server/applied 3/3.
Employees STALE server/applied 5/5. Network last reported ONLINE; outbox 0.
Se confirmó HIGH=DEVICE_OFFLINE; MEDIUM=DEVICE_RETIRED informativa.
No hay otra causa de FAIL. No se fabricó heartbeat ni se alteraron health calculations.

Pruebas antes y después del gate: 207 PASS / 1960 assertions en ambas ejecuciones.
Posterior: 101.68 s, SQLite :memory:, APP_ENV testing, cache/session array.

- tests/Feature/Vending/UserEmployeeIdentityTest.php
- tests/Feature/FieldIdentity (OTP, firmas, revocación, permisos e aislamiento)
- tests/Feature/Support
- tests/Feature/Auth
- tests/Feature/Permissions
- tests/Feature/Vending/MachineAuthorizationServiceTest.php

Sin full suite nueva: no cambia código. Pint no aplica: PHP sin modificaciones.
Git diff --check PASS. No APK, ADB, Secure Storage ni provisioning tocados.

## Rollback y siguiente autorización

down disponible con protección: rechaza rollback si cualquiera de las cuatro
tablas contiene datos. Sólo estando vacías elimina las tablas nuevas en orden
compatible con FK. No se ejecutó rollback ni restore.
MySQL DDL tiene commits implícitos; no asumir reversión transaccional de la migración.
Toda reversión/restauración requiere nueva autorización y revisión de escrituras
posteriores. No restaurar users/migrations completos automáticamente.
Revertir el vínculo también requiere autorización separada.

FINAL: READY_FOR_PHONE_DEMO_VALUE.
La UX/login/transporte y la excepción local de enrolamiento siguen pendientes.
No crear el dispositivo ni un OTP por disponer ya de esquema y vínculo.
No commit, tag, push ni deploy.
