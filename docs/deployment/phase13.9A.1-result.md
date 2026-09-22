# Phase 13.9A.1 — cierre del fixture histórico

Fecha: 2026-09-14. Se corrigió exclusivamente `tests/Feature/Console/OnPremDiagnosticsCommandTest.php`, además de la documentación de cierre/checkpoint. No se modificaron lógica productiva, migrations, helpers compartidos, configuración, dependencias, Android ni pipeline durante esta subfase.

```text
PHASE 13.9A.1: PASS
ONPREM ROOT CAUSE: FIXTURE_MISSING_CLOCKS
PRODUCT HEARTBEAT CODE MODIFIED: NO
FIXTURE: FIXED
CLOCK SCHEMA: mínimo SQLite compatible con columnas usadas; ver detalle abajo
ONPREM TEST: PASS; 1 test, 19 assertions
HEARTBEAT RELATED TESTS: PASS; 25 tests, 127 assertions
TEST DB SAFETY: PASS; 9 tests, 40 assertions
BACKEND MATRIX: PASS; 484 tests, 4252 assertions
FULL SUITE: 807/807 PASS; 6922 assertions; exit 0; una ejecución completa
KNOWN BASELINE FAILURES: 0
DISPOSABLE MYSQL: NOT_REQUIRED; ninguna migration/helper compartido cambió
REAL DB: BUSINESS/IDENTITY UNCHANGED; 20/21 conjuntos MATCH; auditoría concurrente +1
EMPLOYEES: 2507
USERS: 6
ATTENDANCE: 0
VENDING EVENTS: 22
SUPPORT ACTIVITIES: 2
FIELD_MOBILE: 1 ACTIVE, mismo binding
LARAVEL: 12.69.2
PHASE 13.9A FINAL: PASS
NEW CHECKPOINT: READY
FILES PROPOSED FOR STAGING: 128 rutas explícitas en phase13.9A.1-staging-inventory.md
FILES EXCLUDED: secretos/runtime/artefactos/Phase 14/herramientas temporales; lista explícita adjunta
PROPOSED COMMIT: feat(beta): prepare server-ready internal beta
PROPOSED TAG: internal-beta/1.0.1-beta.2-server-ready
NEXT APK: REBUILD_AFTER_DOMAIN
FINAL: READY_FOR_CHECKPOINT_AUTHORIZATION
```

## Causa y esquema mínimo

El test preparaba devices, device_nonces, locations, employees y attendances_raw, pero omitía clocks. `OnPremHeartbeatController::resolveClockForHeartbeat` consulta Clock por `id` o `serial_number`; la inexistencia de la tabla impide el flujo normal. Se revisaron el modelo Clock, las migraciones base/monitoring/program-status y el fixture compartido OnPremApiTestCase. Reutilizar ese fixture completo o las migraciones introduciría tablas y relaciones ajenas al contrato acotado de este test.

El esquema explícito contiene `id` PK autoincremental, `clock_name` requerido, `serial_number` nullable sin unique añadido, `last_heartbeat_at` timestamp nullable con índice, `last_status_message` nullable, `monitoring_status` varchar(20) default offline, `program_status` varchar(30) default offline y timestamps. Los tipos/defaults reflejan las migraciones reales. No modela company_id/location_id ni sus FK porque este contrato no los necesita; no se inventaron restricciones externas ni se relajaron columnas obligatorias del subconjunto.

La preparación valida que existen las columnas esenciales y crea exactamente un reloj sintético. El diagnóstico usa ese registro existente en su transacción. La nueva assertion exige que `extra.json.device.clock_id` del check `e2e.heartbeat.200` sea el id exacto del fixture. Otra assertion verifica que el estado del reloj permanece offline y su heartbeat nulo después del rollback del diagnóstico. Se mantienen exit 0 y todos los checks PASS. Cleanup incluye clocks y sólo elimina la tabla si este fixture la creó.

No hay skip, todo, incomplete, allow_failure ni expectativa exit 1. El cambio utiliza Schema/DB y assertions actuales sobre Laravel 12.69.2, sin compatibilidad artificial con Laravel 11.

## Validación y seguridad

Orden cumplido: test específico → relacionados → safety → matriz → full suite una sola vez. Todas las pruebas usaron APP_ENV=testing y SQLite `:memory:` bajo tests/bootstrap.php, TestEnvironment, TestDatabaseGuard y las conexiones seguras existentes. Los snapshots READ ONLY son comprobaciones separadas autorizadas; el fixture no consulta la base real.

No se repitió MySQL porque no se cambiaron migrations ni helpers compartidos. Esta subfase no creó DBs temporales, por lo que no dejó residual; la prueba MySQL de 13.9A conserva su PASS/residual 0.

Los hashes semánticos comparan 21 conjuntos antes/después: 20 coinciden. El único delta es audit_logs 2834 → 2835: evento `device.bootstrap` a las 20:04:26 UTC, compatible con la actividad concurrente ya documentada de la aplicación. No se borró para forzar coincidencia. Los 18 conjuntos de negocio/identidad, además de challenges y auditoría de identidad, coinciden. FIELD_MOBILE conserva owner, UUID, fingerprint, key version y activated_at; sigue ACTIVE. No se creó FIELD_MOBILE B.

Código productivo preservado y sin diff:

- OnPremHeartbeatController.php SHA256: `339fde9f0bb14ac18c3bf2f6deb3b51ca06f7bbe7a0b408f4e7de417fdb07189`.
- FortiaDiagnoseOnPrem.php SHA256: `fc750833c4f54b9de03b9a550303d13954b971e8ab44eb008997918708eaac45`.

Evidencia privada fuera de Git: `storage/app/private/phase139a1/` contiene logs A–E, JUnit completo, before/after/comparison y revisión de auditoría. No se imprimieron contraseñas, teléfonos, secretos de firma ni material de claves.

## Checkpoint y siguiente APK

[Inventario exacto de 128 rutas y exclusiones](phase13.9A.1-staging-inventory.md). [Propuesta de commit/tag](phase13.9A-checkpoint.md). No se ejecutó git add; índice vacío. HEAD permanece `ea5852d151151014214e660abfd3032414997d44` en `phase/14-biometric-engine-selection`.

El tag interno propuesto conserva el prefijo histórico y evita parecer producción. Es un checkpoint, no una APK validada. No coincide con la regla actual de deploy `vending-beta-X.Y.Z`, de modo que no habilita despliegue remoto; revisar el naming del deployment en Phase 13.10. No se modificó esa política en esta subfase.

Metadatos actuales: versionName 1.0.1-beta.1/build 11. Build 10 es el artefacto histórico aprobado y no representa la fuente actual. El siguiente versionName/build se confirmará tras elegir dominio, proponiendo beta.2 con build nuevo; no se compiló APK.

[Formulario de servidor](phase13.10-server-input.txt) preservado sin valores inventados. No commit, tag, push, deploy, DNS ni GitLab remoto.
