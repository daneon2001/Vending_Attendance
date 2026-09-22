# vending-attendance — instrucciones propuestas para AGENTS.md

Destino al adoptar esta propuesta: `AGENTS.md` en la raíz. Este archivo es una propuesta; no activa instrucciones globales ni autoriza fases nuevas.

## Método y alcance

Trabaja sobre evidencia del repositorio: DISCOVER → ANALYZE → PLAN → IMPLEMENT → VERIFY → REVIEW → REPORT. Antes de editar ejecuta `git status --short`, `git branch --show-current`, `git log --oneline -n 10`; revisa el diff de los componentes afectados. Presenta un plan breve para cambios no triviales con alcance, pruebas y aceptación. Continúa el trabajo autorizado; solicita decisión sólo para ambigüedades que alteren negocio, seguridad, datos o arquitectura.

No infieras la fase activa del nombre de rama. No sobrescribas, limpies ni incorpores cambios ajenos. No uses `git add .`, reset destructivo, force push ni reescritura de historia. Una auditoría no autoriza commit, tag, deploy o cambios funcionales. Revisa ramas y el checkpoint documental antes de crear otra rama. No ejecutes herramientas de `tools/` por su nombre: algunas operan datos reales.

## Mapa de lectura

- `composer.json`, `package.json`, `mobile/package.json` y sus locks: versiones efectivas; web y móvil tienen instalaciones y builds separados.
- `docs/adr/`: ADR-VEND-001–016 aceptados; 017/018 propuestos; 019 aceptado para implementación local, con gates productivos pendientes. Revisa cambios posteriores antes de asumir vigencia.
- `docs/architecture/vending-domain.md`, `vending-api-v1.md`, `attendance-idempotency.md`, `attendance-geofence-evidence.md`, `vending-device-identity.md` y documentos de soporte según el módulo.
- `docs/integrations/sybi-vending-api.md` y `docs/architecture/employee-integration-discovery.md` para integraciones.
- `docs/testing/database-safety.md` y código `app/Support/Testing/` antes de tests PHP o migraciones.
- Para beta: `docs/deployment/phase13.9A.1-result.md`, `phase13.9A-checkpoint.md`, `phase13.9A.1-staging-inventory.md` y `phase13.10-server-input.txt`.
- `docs/architecture/open-decisions.md` es un índice histórico, no prueba de que todo siga pendiente. Contrasta fecha, alcance, código y decisiones posteriores. Los conteos de una demo son evidencia histórica, nunca invariantes a restaurar.

## Stack y fronteras

Backend Laravel 12/PHP 8.4 según manifests actuales; administración Vue 3/Inertia 2/Vite 6/Tailwind 3 en `resources/js`. Móvil separado Ionic Vue/TypeScript/Capacitor 7/Vite 8 en `mobile/`, con proyectos Android/iOS. Confirma versiones en locks, no actualices dependencias incidentalmente.

Conserva el monolito y sus servicios: `app/Services/Vending`, `Employees`, `FieldIdentity`, `Support`, `Audit`; SYBI en `app/Integrations/Sybi`. Mantén controllers acotados, validación en FormRequests/validadores existentes y reglas de dominio en servicios. No introduzcas microservicios, otro framework móvil o un sistema paralelo de permisos sin una decisión explícita.

## Invariantes del dominio

- `VendingMachine` es independiente de `Location`, `Unit`, `Clock` y sucursales legacy. No reutilices sus IDs ni infieras asociaciones. `Device` de terminal y `EmployeeDevice` FIELD_MOBILE son identidades distintas.
- `Employee.employee_number` conserva el identificador de negocio como texto, incluidos ceros iniciales. El PK local, `fortia_employee_id` y `source_external_id` no son intercambiables. Un import corporativo registrado como MANUAL no se convierte automáticamente a FORTIA ni habilita usuarios.
- Asignaciones N:M con vigencia, revocación y permisos separados de asistencia/enrolamiento/mantenimiento. No reemplaces autorización por tipo de asignación o autenticación.
- SYBI posee el catálogo fuente: `id_sucursal` → `sybi_id`; `identificador_vending` puede repetirse en origen. Proyección fuente y promoción operacional son diferentes. No borrar por ausencia ni promover datos ambiguos/0,0. Nuevas máquinas promovidas son DRAFT.
- Geocercas CIRCLE versionadas, una activa por máquina. POLYGON no está soportado aunque exista enum. Preserva historia, transacciones y constraints. Cambios de coordenadas SYBI requieren revisión; no desplazan silenciosamente la geocerca activa.
- Eventos vending inmutables en `vending_attendance_events`, UUID global y hash de payload ligado al dispositivo. Repetición idéntica → DUPLICATE; payload/dispositivo distinto → conflicto. No proyectarlos automáticamente a `attendance_logs`, nómina o Fortia.
- STORED confirma recepción, no autorización ni pago. Conserva AUTHORIZED/DENIED/UNVERIFIABLE y evaluación GPS independiente. Backend recalcula contra la versión histórica referenciada; falta de ubicación → NOT_EVALUATED, mala precisión → UNCERTAIN. No inventes GPS ni cambies la política de bloqueo sin decisión.

## Seguridad e integraciones

Respeta las fronteras de rutas: web con sesión/RBAC; `/api/v1/device` con provisioning y HMAC por dispositivo; `/api/onprem` legacy; `/api/v1/field-mobile` con transporte de credenciales nativas y pruebas de identidad; soporte externo con principal y scopes propios. `perm` admite fallback `settings.manage`; `perm.strict` no. Evalúa explícitamente cuál corresponde; no cambies permisos existentes silenciosamente.

Preserva firma canónica, timestamp, nonce único, rate limits y autorización por objeto. No amplíes excepciones CSRF/CORS para resolver problemas móviles. Separa sesión humana, credenciales terminal y claves FIELD_MOBILE; no mezcles sus almacenes o ámbitos.

No imprimas `.env`, tokens, teléfonos, biometría, claves ni cuerpos sensibles. Usa nombres de variables y resultados sanitizados. Nunca incorpores registros privados de testers, backups, APK, SQLite, logs o firma a Git. El nombre `embedding_encrypted` no demuestra cifrado. Antes de tocar endpoints biométricos legacy verifica autorización y contrato de protección; no copies sus supuestos al móvil.

HTTP a proveedores sólo mediante adaptadores backend. No inventes contrato Fortia: su cliente HTTP exige aprobación/configuración; el envío de asistencias sigue pendiente. SYBI es lectura; no envíes escrituras. Para nuevos contratos define HTTPS, límites, timeouts, errores sanitizados, reintentos acotados e idempotencia según la operación. Ausencia de contrato/credenciales → BLOCKED — EXTERNAL DEPENDENCY; continúa únicamente con trabajo independiente.

## Móvil, identidad y offline

Asistencia guarda evento + intención outbox en una transacción SQLite antes de red. Conserva UUID y evidencia en reintentos. Aplica manifests completos con hash y transacción; ACK sólo después del commit. Preserva recuperación de SYNCING, backoff y rechazos inspeccionables. No prometas sincronización en background.

Soporte tiene store/outbox y archivos privados separados de asistencia. Mantén ownership por dispositivo y, en FIELD_MOBILE, aislamiento por origen/sesión/contexto. No pierdas borradores al reiniciar/cerrar sesión ni subas evidencia de otro actor. Prueba permisos denegados, red intermitente, respuesta parcial, crash y recuperación de cámara cuando corresponda.

FIELD_MOBILE actual exige Android en `FieldMobileStore`; proyecto iOS presente no demuestra paridad. No desinstales, reprovisiones ni cambies claves/firma/origen del dispositivo real para hacer pasar una prueba. Conserva el flujo de recuperación con challenge.

OTP LOCAL_SIMULATED no verifica posesión telefónica real; conserva límites local/testing/beta y rechazo productivo. No declares PHONE_VERIFIED por esta simulación.

Biometría móvil usa `UnsupportedBiometricProvider`: NOT_SUPPORTED, nunca MATCH simulado. ADR-017/018 y selección de motor son propuestas. No añadas modelos, captura real, umbrales, almacenamiento facial ni distribución biométrica sin cerrar licencia, PAD, privacidad, arquitectura nativa y pruebas físicas pertinentes.

## Pruebas sin afectar datos reales

No uses bases dev/pilot/Fortia para tests ni ejecutes migraciones destructivas sobre ellas. `tests/bootstrap.php`, `TestEnvironment`, `TestDatabaseGuard`, `SafeDatabaseManager` y `TestDatabasePolicy` deben actuar antes de conexiones/RefreshDatabase. Si detectan caché de configuración o destino inseguro, detente: no borres cachés ni desactives el guard para continuar.

Default: SQLite `:memory:`; fixtures Fortia también aislados. MySQL sólo mediante lifecycle propietario `Tests/Support/DisposableMysql`, loopback y nombre exacto `vending_attendance_test_[a-f0-9]{16}`, con cleanup y residual comprobado. No reactivas los cuatro helpers MySQL legacy deshabilitados. PHPUnit default no incluye `tests/Integration` ni `tests/Performance`; un PASS SQLite no acredita concurrencia MySQL.

Comandos desde raíz, seleccionar según el cambio:

```powershell
php vendor/bin/phpunit tests/Unit/Testing tests/Feature/Testing
php vendor/bin/phpunit --filter NombreDePrueba
php artisan test
$frontendTests = @(Get-ChildItem tests/Frontend -Filter '*.test.js' | ForEach-Object FullName)
node --test @frontendTests
npm.cmd run build -- --mode beta
```

Para criptografía PHP en Windows, comprueba `OPENSSL_CONF` en `extras/ssl/openssl.cnf` de la instalación PHP usada y configúralo sólo en el proceso; no contiene claves. No cambies `.env` para ejecutar tests. En incidentes o validaciones de datos aplica los comparadores protegidos documentados, de sólo lectura y sin payloads, cuando el alcance lo requiera.

Desde `mobile/`: `npm.cmd test` y `npm.cmd run build` (incluye `vue-tsc`). Build beta: `npm.cmd run build -- --mode beta`, con orígenes HTTPS aprobados. Desde `mobile/android/`: `./gradlew.bat testDebugUnitTest` para tests nativos. `npm.cmd run android:build` compila y hace cap sync: revisa su diff y distingue debug de artefacto beta firmado. iOS requiere herramientas Apple y validación propia.

`npm.cmd run test:e2e` requiere servidor, datos sintéticos y credenciales de prueba; no lo apuntes a la app real por defecto. `php tests/Support/disposable_mysql.php` y pruebas Integration/Performance son gates separados. Pint está disponible; no apliques formateo masivo. No existe script general de lint web; no inventes uno como resultado ejecutado.

## Revisión y cierre

Revisa `git status`, diff específico y `git diff --check`; verifica secretos, artefactos y cambios ajenos. En archivos nuevos revisa también contenido: el diff ordinario no incluye untracked. Actualiza documentación/ADR cuando cambie contrato, operación o decisión. No eleves una propuesta a APPROVED porque tenga implementación parcial.

Reporta objetivo, archivos propios, comandos y resultado PASS/FAIL/BLOCKED/NOT RUN/NOT APPLICABLE, riesgos, decisiones y siguiente paso. Distingue pruebas actuales de evidencia histórica; build no equivale a validación física ni despliegue. El checkpoint beta y el despliegue tienen gates distintos: el tag interno propuesto no coincide con el patrón de deploy CI. No inventes servidor/dominio/firma para completarlos.
