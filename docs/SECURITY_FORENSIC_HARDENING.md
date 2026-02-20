# Blindaje Forense de Asistencias Biométricas

## Alcance
- Proyecto: `asistencias_fortia`
- Objetivo: integridad, trazabilidad y validez probatoria de registros de asistencia.
- Fecha de evaluación técnica: 2026-02-20 (UTC).

## A) Inventario y Mapa del Sistema
### Stack identificado
- Backend: Laravel 11 (PHP), Eloquent, middleware propio.
- Frontend: Vue 3 + Inertia.
- Auth:
  - Web interna: sesión (`auth`) + middleware `perm`.
  - API externa: Sanctum (`auth:sanctum`) y autenticación de dispositivo (`device.token`, `device.hmac`).
- BD principal: MySQL (migraciones Laravel).
- Sincronización:
  - `POST /api/onprem/attendances` con HMAC y nonce anti-replay.
  - `POST /api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device` (legado con token estático).
- Logging/auditoría:
  - `audit_logs` + servicio `AuditLogger`.
  - `attendance_changes` para incidencias/ajustes.

### Módulos/tablas clave
- Asistencias/checadas:
  - `attendance_logs` (`App\Models\AttendanceRecord`, `App\Models\AttendanceLog`)
  - `attendances_raw` (`App\Models\AttendanceRaw`)
- Incidencias:
  - `attendance_changes` (`App\Models\AttendanceAudit`)
- Usuarios/roles:
  - `users`, `roles`, `permissions`, `role_user`, `permission_role`
- Dispositivos:
  - `devices`, `device_nonces`
- Auditoría:
  - `audit_logs`

### Flujo principal (Device -> API -> DB -> Reportes)
1. Dispositivo envía evento firmado (`device.hmac`) o token legado (`device.token`).
2. API valida autenticidad, ventana de tiempo y replay.
3. Persistencia cruda idempotente en `attendances_raw` (on-prem).
4. Sincronización a `attendance_logs` (registro central).
5. Observer sella hash de integridad por fila/cadena y genera `audit_logs`.
6. Módulo de administración consulta/ajusta (`admin/asistencias`) y genera `attendance_changes` + `audit_logs`.
7. Reportes/exportaciones quedan auditados.

## B) Evaluación de Riesgos por 7 Principios
| Principio | Estado | Riesgo si falla | Evidencia técnica | Acción recomendada exacta |
|---|---|---|---|---|
| Integridad de datos | Parcial | Alteración silenciosa de checadas | Hash por registro/cadena en `attendance_logs` + comando `attendance:verify-integrity` | Ejecutar verificación diaria por tarea programada y bloquear despliegues si hay comprometidos |
| Trazabilidad completa | Parcial | No poder reconstruir quién cambió qué y cuándo | `audit_logs` enriquecido + observers en `attendance_logs` y `attendance_changes` | Completar cobertura de todos los cambios sensibles (incluyendo APIs legadas faltantes) |
| Control de accesos (RBAC) | Parcial | Cambios no autorizados | Middleware `perm`, roles/permisos y matriz en usuario | Endurecer matriz mínima por endpoint y exigir motivo para acciones de edición |
| Registro de auditoría | Parcial | Evidencia insuficiente en litigio | `audit_logs` con actor, acción, entidad, old/new, request_id, device_id | Añadir protección DB (trigger/usuario BD sin DELETE en `audit_logs`) y exportación WORM |
| Disponibilidad | Parcial | Pérdida de operación y pruebas | Operación central + on-prem, pero sin healthcheck integral automatizado | Programar health checks, alertas de sync y pruebas de restore mensuales |
| No repudio | Parcial | Disputa de origen/autenticidad | HMAC + nonce en on-prem; origen en `device_serial`, `auth_key_id`, `ingest_ip` | Retirar endpoint legado con token estático o migrarlo a HMAC/mTLS |
| Evidencia verificable | Parcial | Evidencia impugnable | `verify_integrity(periodo)` + auditoría detallada | Añadir sellado de tiempo (TSA) y custodia en almacenamiento inmutable (WORM) |

## C) Diseño e Implementación de Audit Trail
### Esquema forense aplicado
- Tabla `audit_logs` (además de campos legacy) con:
  - `actor_user_id`, `actor_type`, `actor_identifier`
  - `action`, `entity`, `entity_id`
  - `old_values`, `new_values`, `reason`
  - `request_id`, `correlation_id`, `device_id`
  - `occurred_at_utc`, `occurred_at_local`, `timezone`
- Migración: `database/migrations/2026_02_20_130500_harden_audit_and_attendance_forensics.php`

### Captura de auditoría (modelo híbrido)
- Middleware:
  - `AssignRequestId` crea `request_id/correlation_id`.
- Observers:
  - `AttendanceRecordObserver` para create/update/delete en `attendance_logs`.
  - `AttendanceAuditObserver` para create/update/delete en `attendance_changes`.
- Servicio:
  - `AuditLogger` centraliza payload forense y persiste solo columnas existentes.

### Pros/Cons enfoque de captura
- Middleware:
  - Pro: correlación transversal.
  - Contra: no conoce diff de entidad por sí solo.
- Observer:
  - Pro: captura old/new por entidad con contexto de negocio.
  - Contra: no cubre cambios directos SQL fuera de aplicación.
- Trigger DB (recomendado en fase siguiente):
  - Pro: cobertura total incluso fuera de app.
  - Contra: mayor complejidad operativa/versionado.

### Inmutabilidad de logs
- `AuditLog` bloquea `update/delete` por defecto (append-only lógico).
- Excepción extrema solo con `audit.allow_extreme_modification=true` + permiso `settings.manage`.
- Config: `config/audit.php`.

### Pruebas de auditoría
- `tests/Feature/Security/AttendanceAuditTrailTest.php`:
  - valida before/after en cambio de asistencia.
  - valida bloqueo update/delete en `audit_logs`.

## D) Integridad por Hash (SHA-256)
### Estrategia implementada
- Hash por registro + encadenamiento (`integrity_previous_hash`) en `attendance_logs`.
- Campos de integridad:
  - `integrity_hash`
  - `integrity_previous_hash`
  - `integrity_hash_version`
  - `integrity_verified_at`

### Canonical data del hash
- `attendance_id`
- `employee_id`
- `timestamp` (UTC)
- `device_id`
- `source`
- `payload_original` (`raw_payload` normalizado JSON estable)
- `previous_hash`

### Implementación
- Servicio: `app/Services/Attendance/AttendanceIntegrityService.php`
  - `sealRecord()`
  - `verifyIntegrity()`
  - `verify_integrity(periodo)` (alias requerido)
- Command: `app/Console/Commands/VerifyAttendanceIntegrity.php`
  - `php artisan attendance:verify-integrity --from=2026-02-01 --to=2026-02-20 --json`

### Resultado esperado de verificación
- `ok=true` cuando no hay alteraciones.
- `ok=false` y listado `compromised[]` cuando detecta:
  - `MISSING_HASH`
  - `PREVIOUS_HASH_MISMATCH`
  - `ROW_HASH_MISMATCH`

## E) Control de Tiempo Confiable
### Estado actual
- Tiempos críticos se normalizan a UTC en backend.
- Se agregó persistencia de `ingested_at_utc`.
- Se registra zona local en auditoría (`occurred_at_local`, `timezone`).

### Implementado
- Script de salud NTP (Windows):
  - `scripts/security/ntp_healthcheck.ps1`
- Recomendación de programación:
  - ejecutar cada 15 minutos en Task Scheduler.
  - alerta si `exit code != 0`.

### Política exigida
- No confiar en timestamp de cliente como fuente única.
- Ajustes manuales solo por rol autorizado + motivo obligatorio + auditoría automática.

## F) RBAC y Controles de Edición
### Matriz mínima recomendada
| Acción | Rol mínimo | Regla |
|---|---|---|
| Crear checada automática | `system/device` | Solo APIs de dispositivo autenticado |
| Editar checada | `admin técnico`, `RH` | Motivo obligatorio y `audit_logs` + `attendance_changes` |
| Eliminar checada | Prohibido idealmente | Si se habilita: soft-delete + auditoría obligatoria |
| Exportar reportes | `RH`, `consulta` | Siempre auditar evento de exportación |
| Gestión de roles/permisos | `admin técnico` | Control estricto, doble validación recomendada |

### Estado técnico
- Middleware `perm` activo para módulos web.
- Endpoints sensibles de catálogo/admin protegidos por `auth` + permisos.
- Reforzar APIs legadas para evitar bypass por token estático.

## G) Origen de Registro Verificable
### Campos de origen implementados en `attendance_logs`
- `device_serial`
- `ingest_ip`
- `auth_key_id`
- `request_id`
- `ingested_at_utc`

### Controles de autenticidad
- On-prem:
  - HMAC SHA-256 por request.
  - `device_nonces` anti-replay.
- Recomendación:
  - migrar legado `device.token` a HMAC/mTLS.
  - rotación de secretos por dispositivo.

## H) Logs Técnicos y Retención
### Implementado
- `AssignRequestId` agrega `request_id/correlation_id` a contexto de logs y headers.
- Canal JSON forense:
  - `config/logging.php` channel `forensics_json`.
  - Retención configurable `LOG_FORENSICS_DAYS` (default 540 = 18 meses).

### Config sugerida
- `.env`:
  - `LOG_STACK=daily,forensics_json`
  - `LOG_FORENSICS_DAYS=540`
  - `LOG_LEVEL=info`

## I) Backups y Pruebas de Restauración
### Implementado (Windows)
- Backup cifrado/traslado offsite (por ruta):
  - `scripts/security/backup_mysql.ps1`
- Restore test mensual:
  - `scripts/security/restore_backup_test.ps1`

### Programación recomendada
- Diario 02:00 UTC:
  - `powershell -File scripts/security/backup_mysql.ps1`
- Mensual (1er domingo):
  - `powershell -File scripts/security/restore_backup_test.ps1`
- Ejemplo Task Scheduler (ejecutar en PowerShell Admin):
  - `schtasks /Create /SC DAILY /TN "FortiaBackupMysql" /TR "powershell -NoProfile -ExecutionPolicy Bypass -File C:\laragon\www\asistencias_fortia\scripts\security\backup_mysql.ps1" /ST 02:00`
  - `schtasks /Create /SC HOURLY /MO 1 /TN "FortiaNtpHealth" /TR "powershell -NoProfile -ExecutionPolicy Bypass -File C:\laragon\www\asistencias_fortia\scripts\security\ntp_healthcheck.ps1 -MaxHoursSinceSync 24"`
  - `schtasks /Create /SC MONTHLY /D SUN /MO FIRST /TN "FortiaRestoreDrill" /TR "powershell -NoProfile -ExecutionPolicy Bypass -File C:\laragon\www\asistencias_fortia\scripts\security\restore_backup_test.ps1" /ST 03:30`

### Evidencia generada
- `storage/logs/backup-audit.log`
- `storage/logs/backup-restore-test.log`
- checksum `.sha256` por backup

## J) Monitoreo y Alertas
### Eventos críticos a alertar
- Integridad comprometida (`attendance:verify-integrity` con `ok=false`)
- Login fallido repetido
- Cambios masivos en `attendance_logs`
- Fallas de sync on-prem y rechazos por firma
- Exportaciones inusuales

### Integración sugerida
- Parseo de `forensics_json` hacia ELK/Loki/Grafana.
- Alertas email/Slack/Telegram por:
  - `action=update/delete` en entidades sensibles
  - `event=onprem.punch.rejected` con picos anómalos

## K) Manual Operativo del Sistema
### Catálogo y consumo API
- La UI de catálogo debe consumir:
  - `GET /api/admin/employees`
- Endpoint compacto retorna solo datos necesarios y estado de huella booleano:
  - `has_fingerprint`
  - `fingerprint_status` (booleano para validación rápida)

### Endpoints y responsabilidades
- Ingesta on-prem: `POST /api/onprem/attendances`
- Administración asistencias: `/admin/asistencias/*`
- Verificación de integridad:
  - `php artisan attendance:verify-integrity --from=YYYY-MM-DD --to=YYYY-MM-DD --json`

### Política de modificación de registros
- Todo ajuste/anulación debe incluir motivo.
- Toda modificación genera:
  - `attendance_changes`
  - `audit_logs` con old/new.

## L) Procedimiento de Controversia Laboral (Cadena de Custodia)
1. Congelar periodo:
   - exportar snapshot de `attendance_logs`, `attendance_changes`, `audit_logs` por rango.
2. Verificar integridad:
   - ejecutar `attendance:verify-integrity --from --to --json`.
3. Generar evidencia firmada:
   - calcular SHA-256 del paquete exportado + reporte de integridad.
4. Sellado de tiempo:
   - registrar hash en TSA o servicio de timestamp externo.
5. Custodia inmutable:
   - almacenar evidencia en WORM/offsite con checksum.
6. Trazabilidad de origen:
   - anexar inventario de dispositivos, `auth_key_id`, logs de autenticación y rechazos.
7. Documento técnico:
   - incluir responsables, fecha/hora UTC, método de extracción y hash final.

## Lista Priorizada (P0/P1/P2)
### P0 (inmediato, 1-3 dias)
- Ejecutar migraciones forenses en todos los ambientes.
- Activar `LOG_STACK=daily,forensics_json`.
- Programar `attendance:verify-integrity` diario + alerta.
- Migrar UI de catálogo a `/api/admin/employees` (compacto).

### P1 (corto plazo, 1-2 semanas)
- Endurecer RBAC por endpoint con matriz formal por rol.
- Eliminar dependencia de endpoint legado con token estático.
- Programar backup diario y restore test mensual.
- Consolidar tablero de alertas de seguridad operacional.

### P2 (mediano plazo, 3-6 semanas)
- Añadir trigger DB para auditoría de última línea.
- Implementar sellado de tiempo externo (TSA) para paquetes probatorios.
- Almacenamiento WORM para evidencia legal.

## Checklist de Cumplimiento Probatorio
- [x] Registro de origen (device, IP, request_id, auth_key_id)
- [x] Auditoría de cambios con old/new y actor
- [x] Correlación técnica (`request_id`, `correlation_id`)
- [x] Verificación de integridad por hash/chain
- [x] Comando de auditoría verificable para periodo
- [x] Endpoint de catálogo liviano sin biometría/base64
- [ ] Sellado de tiempo de tercero (TSA)
- [ ] Evidencia WORM obligatoria
- [ ] Política formal firmada de retención legal

## Declaración técnica (para escritos)
> El sistema cuenta con mecanismos de control de integridad, trazabilidad, autenticación y auditoría que garantizan la confiabilidad e inalterabilidad de los registros de asistencia.
