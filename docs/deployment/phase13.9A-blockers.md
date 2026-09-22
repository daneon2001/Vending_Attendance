# Phase 13.9A — inventario previo a cambios

2026-09-14. Build10 es histórico inmutable. No deploy/DNS/GitLab remoto/commit/tag/push. Fortia solo referencia de lectura. No pruebas contra vending_attendance_dev.

| Bloqueo | Archivos/dependencias afectadas | Cierre previsto |
|---|---|---|
| Deploy Fortia | .gitlab-ci.yml, scripts/deploy_qa.ps1, scripts/deploy_production.ps1 | pipeline Vending y scripts Linux, stubs Windows sin ejecución |
| Beta solo local | config/internal_beta.php, FieldMobileTransport, DeviceIdentityService, LocalOtpProvider, DeviceAdminController, BetaTesterPolicy, LocalBetaTesterRegistry | guard central beta explícito, registry privado, producción denegada |
| Android LAN/debug | build.gradle, MainActivity, FieldOriginRecoveryPolicy, FieldDeviceKeyPlugin, runtime.ts y build config | beta no depurable PKI sistema, endpoints y transición parametrizados |
| Firma | certificado APK10 Android Debug, Gradle release signing | decisión documentada, ninguna clave nueva |
| Migraciones | 105 archivos; dos acceden fortia_mock | boundary integration opt-in local/test, prueba MySQL efímera |
| Laravel11 EOL | composer.json/lock/vendor, bootstrap/app, providers, middleware/auth, test harness | rama12 compatible con PHP8.4 y dependencias; verificar con solver y tests |
| Storage | config/filesystems.php, SupportEvidenceService/controllers | serve=false, autorización/path safety existentes + tests |
| Readiness | routes/web.php, nuevo servicio/controller | /ready genérico 200/503, DB y storage privado |

Dependencias de validación: tests/bootstrap.php, TestEnvironment, TestDatabaseGuard/Policy, SafeDatabaseManager/ConnectionFactory, tests/Support/DisposableMysql. Snapshot SQL READ ONLY previo/post; suite final SQLite memory; migración MySQL solo nombre generado y borrado por propietario. No cambiar guardas para hacer pasar tests.

Branding: manifest/hash y artefacto10 protegidos; versión futura diferente si se construye. Cambios scheduler/cors/proxy/config/layout/pipeline se verifican sin activar servicios remotos. Phase14 permanece diferida.
