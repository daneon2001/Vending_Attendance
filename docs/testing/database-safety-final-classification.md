# Phase 13.7.2.0 ? clasificaci?n final

Fecha: 2026-09-13 UTC. Clasificaci?n exclusivamente; sin cambios ejecutables ni nueva suite completa.

## Decisi?n

PHASE 13.7.2.0 FINAL: PASS_WITH_KNOWN_BASELINE_FAILURE. La falla OnPrem es deuda heredada del fixture y no invalida el aislamiento demostrado. Esta decisi?n sustituye el bloqueo provisional por suite no completamente verde del informe anterior, conforme al criterio autorizado por el usuario. No significa PASS de producci?n y no reanuda Phase 13.7.2.1 autom?ticamente.

## Evidencia hist?rica exacta

- [docs/baseline/phase-1-tests-runtime.md:3](../baseline/phase-1-tests-runtime.md): fecha 2026-09-04; l?neas 5?13 registran 281 PASS / 1 FAIL antes de cambios; l?neas 27?31 identifican el test y la ausencia de clocks.
- [docs/baseline/tests-runtime.txt:12](../baseline/tests-runtime.txt): mismo nombre completo; l?nea 15 documenta SQLite `no such table: clocks` y la omisi?n del fixture. L?nea 18 registra que el diagn?stico pas? contra el esquema MySQL completo de aquel baseline.
- [docs/architecture/pilot-validation.md:7](../architecture/pilot-validation.md): baseline 413 PASS / misma falla heredada; l?nea 9, final 420 PASS / misma falla.
- [docs/operations/phase-13-support-review.md:71](../operations/phase-13-support-review.md): 632 PASS / 1 FAIL heredado, anterior al hardening.
- [docs/operations/vending-field-support-visual-environment.md:118](../operations/vending-field-support-visual-environment.md): 706 PASS / 1 FAIL conocido.

Las referencias anteriores ya exist?an antes de esta clasificaci?n. La afirmaci?n previa de que la sola falla imped?a cerrar safety omiti? contrastar este historial; se corrige aqu? sin cambiar resultados de tests.

## Causa actual y comparaci?n

Clasificaci?n D: deuda del fixture SQLite heredado. OnPremDiagnosticsCommandTest crea devices, device_nonces, locations, employees y attendances_raw; no crea clocks (ensureSchema, l?neas 82?187). FortiaDiagnoseOnPrem::resolveClockId retorna null cuando clocks no existe (l?neas 322?329). OnPremHeartbeatController::resolveClockForHeartbeat consulta clocks por serial (l?neas 165?190) antes de guardar el heartbeat. Esa tabla ausente causa el error que impide e2e.heartbeat.200 y hace retornar exit 1 al diagn?stico; el test espera 0 en la l?nea 54.

El hash actual de cada uno de estos tres archivos coincide tanto con el manifiesto anterior al hardening como con el snapshot beta del 11/09:

| Archivo | SHA256 |
|---|---|
| tests/Feature/Console/OnPremDiagnosticsCommandTest.php | 196d436cdb971a6484d4a51bc8cb13d0110debbf6af773ec0997f7de42132384 |
| app/Http/Controllers/Api/OnPrem/OnPremHeartbeatController.php | 339fde9f0bb14ac18c3bf2f6deb3b51ca06f7bbe7a0b408f4e7de417fdb07189 |
| app/Console/Commands/FortiaDiagnoseOnPrem.php | fc750833c4f54b9de03b9a550303d13954b971e8ab44eb008997918708eaac45 |

Evidencia adicional ejecutada en esta fase: un SELECT parametrizado sobre clocks en una conexi?n SQLite :memory: vac?a con PRAGMA query_only=ON produce `no such table: clocks`, sin Laravel ni el guard. No se crearon tablas, dispositivos ni heartbeat. Esto confirma el mecanismo de tabla ausente, no pretende ser una nueva ejecuci?n end-to-end. La correspondencia con el fallo actual se sustenta en el diagn?stico aislado anterior e2e.heartbeat.200, c?digo sin cambios y reportes hist?ricos.

A (telemetr?a restaurada/stale): NO; este test usa su propio fixture SQLite, no la DB restaurada. B (hardening): NO para este fallo residual. C (nueva regresi?n productiva): NO para este fallo, con los tres archivos iguales al checkpoint. No se extrapola a una garant?a global sobre todo el producto.

## Impacto en safety

Ninguno de los siguientes controles queda invalidado por la falta de clocks en ese fixture: PRE_BOOT_GUARD, CONFIG_CACHE_ISOLATION, TEST_DB_POLICY, DEV_DB_HARD_DENY, GUARD_BEFORE_REFRESH_DATABASE, SQLITE_MEMORY, MYSQL_DISPOSABLE, MYSQL_CLEANUP, MIGRATE_FRESH_SAFETY, DB_WIPE_SAFETY, INCIDENT_REGRESSION_TEST y REAL_DB_14_14_INTEGRITY.

Se conservan las evidencias de la fase anterior: safety 9 pruebas/40 assertions PASS, FieldIdentity 51 PASS, Support 152 PASS, disposable MySQL y cleanup PASS. Full suite conserva exactamente 789 PASS / 1 KNOWN FAIL, no se repiti? y no se marca completamente verde.

La nueva lectura de DB real, en transacci?n READ ONLY y con algoritmo hist?rico, confirma 14/14 MATCH, empleados 2507, asistencia 0, eventos vending 22, actividades 2 y FIELD_MOBILE ACTIVE. Hash de employee_devices completo preserva identidad, owner, clave y versi?n.

## Deuda separada

- KNOWN BASELINE FAILURE: OnPremDiagnosticsCommandTest / e2e.heartbeat.200; fixture clocks pendiente, test activo y expectativa intacta.
- PARALLEL TESTING: DEFERRED; ParaTest no instalado. Dos subprocessos SQLite simult?neos constituyen evidencia parcial, no certificaci?n de ParaTest.
- LEGACY MYSQL HARNESSES: cuatro DISABLED_PENDING_PORT. Aborto antes del bootstrap comprobado; riesgo de ese acceso accidental contenido. No se afirma cobertura funcional de esos helpers.
- MYSQL PRIVILEGE BOUNDARY: NOT_IMPLEMENTED. Guards de repositorio no equivalen a permisos MySQL que impidan PHP arbitrario con credenciales privilegiadas.

## Salida obligatoria

PHASE 13.7.2.0 FINAL: PASS_WITH_KNOWN_BASELINE_FAILURE
ONPREM FAILURE HISTORICAL: YES
ONPREM CURRENT ROOT CAUSE: Fixture SQLite omite clocks; resoluci?n de heartbeat consulta esa tabla.
CAUSED BY HARDENING: NO
HEARTBEAT PRODUCT CODE MODIFIED: NO
SAFETY GATES: PASS
REAL DB: 14/14 MATCH
FULL SUITE: 789 PASS / 1 KNOWN FAIL
PARALLEL SAFETY: DEFERRED
LEGACY MYSQL HARNESSES: DISABLED_PENDING_PORT
MYSQL PRIVILEGE BOUNDARY: NOT_IMPLEMENTED
SECURITY: PASS_WITH_DOCUMENTED_LIMITS
FINAL: READY_TO_RESUME_PHASE_13_7_2_1

Evidencias privadas: classification-code-evidence.json y classification-final-baseline.json en storage/app/private/phase-13.7.2.1-incident/. No cambios productivos, timestamps, heartbeat, dispositivos, expectativas de tests, skips, ParaTest, commits, tags, push ni deploy. No se continu? origin recovery.
