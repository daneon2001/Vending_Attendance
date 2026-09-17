# SEC-01 — Autorización de sincronización FaceID legacy

SEC-01 STATUS: **CONFIRMED**. Corrección mínima implementada y verificada en código; no desplegada.

## Preflight y alcance

- Se leyó AGENTS.md y se ejecutaron status, rama y los diez commits recientes antes de modificar archivos.
- Rama: `phase/14-biometric-engine-selection`.
- HEAD: `ea5852d151151014214e660abfd3032414997d44`.
- El working tree ya tenía cambios beta/LAN/branding/fase 14 y AGENTS.md sin staging. `routes/api.php` no tenía diff previo.
- No se consultaron ni modificaron bases operativas; tampoco se usaron cuentas, tokens o biometría reales. No se abrieron endpoints del servidor real.
- No se limpió ninguna caché ni se modificaron guards, configuración de pruebas o esquema productivo. `bootstrap/cache/config.php` no existía. La caché de rutas existente se conservó: tests/bootstrap.php usa una ruta aislada por proceso.

## Camino inspeccionado

`POST /api/faceid/templates/sync` → grupo API → `auth:sanctum` → `token.expiration` → `FaceIdTemplateSyncController::sync(FaceIdTemplateSyncRequest)`.

En bootstrap, API agrega AssignRequestId, EnsureFrontendRequestsAreStateful y SubstituteBindings; la frontera beta global valida transporte/configuración, no permisos biométricos. Authenticate delega en autenticación Laravel; CheckTokenExpiration exige usuario/token vigente, sin RBAC. El controller base no añade autorización. El FormRequest devuelve authorize=true y valida datos, no capacidad del actor.

El controller no delega la escritura a otro servicio: resuelve Employee por identificadores del request, busca el hash de template, rechaza conflicto entre empleados, abre transacción, desactiva templates anteriores, guarda EmployeeFaceTemplate y llama Employee::markFaceEnrolled. Este último actualiza campos mediante saveQuietly. No se encontró un Gate/Policy/global scope de autorización del actor en este camino. Los observers de Employee atienden cambios de scope/manifests, no autorizan este request. AuditBiometricAccess no estaba en la ruta y su función es auditoría, no autorización.

## Evidencia antes de corregir

Se creó [FaceIdTemplateSyncAuthorizationTest.php](../../tests/Feature/Api/FaceIdTemplateSyncAuthorizationTest.php) con RefreshDatabase sobre SQLite `:memory:`, migraciones reales, middleware activo y tokens Sanctum reales creados sólo para el test. No se usó Sanctum::actingAs ni se omitieron middlewares. Http::preventStrayRequests impide llamadas HTTP no simuladas. El payload contiene un marcador artificial sin información biométrica; no es un modelo, embedding ni ciphertext real.

Primera ejecución de caracterización, antes de modificar la ruta:

| Caso | Resultado observado | Efecto comprobado |
|---|---|---|
| A: no autenticado | 401 | 0 templates; empleado sin nuevo enrolamiento |
| B: token válido, usuario sin permiso biométrico | 200, success=true | 1 template asociado al empleado; has_face_enrollment=true |
| C: administrador con permiso existente face.manage | 200, success=true | 1 template asociado al empleado; has_face_enrollment=true |

Comando: `php vendor/bin/phpunit tests/Feature/Api/FaceIdTemplateSyncAuthorizationTest.php --testdox`. Resultado de caracterización: PASS, 3 tests / 22 assertions. El PASS describe la reproducción, no un control de seguridad correcto.

Después se convirtió B a una expectativa de rechazo y se ampliaron regresiones, todavía sin tocar código funcional. Mismo comando: **FAIL**, 12 tests / 68 assertions / 6 fallos. B y cinco variantes de permisos/roles devolvían 200 donde se esperaba 403. Las seis pruebas restantes pasaron. Esto demuestra que las pruebas finales detectan el defecto anterior. Para repetir el estado anterior, usar una copia aislada con la ruta previa de HEAD y este test; nunca revertir la protección en un servidor operativo.

## Root cause y decisión

Faltaba autorización de la operación de escritura: autenticación/expiración permitían llegar a un FormRequest sin chequeo de capacidad y a una transacción que confía en el empleado seleccionado. La reproducción descarta que un mecanismo indirecto bloqueara B.

Se reutiliza el RBAC existente, sin nuevos permisos ni grants:

- `config/permissions.php` incluye `biometrics.face.manage`.
- La migración `2026_03_19_120100_add_biometric_face_manage_permission.php` describe administración de estatus y FaceID por empleado y lo asigna a roles administrativos existentes.
- Las rutas PATCH/DELETE de `admin/employees/{employee}/face-profile` requieren roles `administrador,admin,superadmin` y `perm.strict:biometrics,face.manage`.
- EnsureRole normaliza aliases; EnsureStrictPermission consulta User::hasPermission. No admite el fallback `settings.manage` de EnsurePermission. Se conserva la semántica ya existente de `manage` dentro del propio módulo; no se concede ese permiso aquí.

Cambio funcional exclusivo en [routes/api.php](../../routes/api.php): añadir ambos middlewares a la ruta de sync después de auth/token.expiration. Controller, FormRequest, payload, respuesta exitosa y resolución de identificadores permanecen iguales. No se agregó AuditBiometricAccess porque no autoriza y sus clasificaciones actuales se orientan a otros endpoints; ampliar auditoría o cifrado no pertenece a SEC-01.

## Alcance por empleado y compatibilidad

La administración facial existente es global al catálogo para el actor administrativo con permiso. EmployeeFaceProfileController tampoco exige relación user.employee_id, assignment vending o sucursal para administrar un perfil. Esas relaciones no definen un alcance administrativo biométrico en el modelo inspeccionado. No se importó la autorización de asistencia/enrolamiento terminal a este endpoint administrativo legacy.

Se comprueba que un administrador sin vínculo user.employee_id puede sincronizar por fortia_employee_id al empleado seleccionado, sin cambiar a otro empleado, y que incluso autorizado no puede reutilizar el hash de un template perteneciente a otro empleado (409). No se promete autorización por sucursal/tenant que no existe en este flujo. La prioridad histórica de resolución cuando llegan varios identificadores se conserva; no se redefine ese contrato.

Riesgo de regresión: clientes legacy/WinAdmin que usen cuentas sin rol administrativo o sin permiso pasarán a recibir 403 deliberadamente. Cuentas administrativas autorizadas conservan contrato y operación. El código del cliente Windows es externo; su homologación real y las asignaciones de permisos operativas no se consultaron. No se concedieron permisos automáticamente. No se modificó ningún otro endpoint.

## Verificación ejecutada

| Comando | Resultado |
|---|---|
| `php vendor/bin/phpunit tests/Unit/Testing tests/Feature/Testing` | PASS: 9 tests / 40 assertions |
| Test específico, caracterización inicial de A/B/C | PASS: 3 / 22; B escribe, confirma hallazgo |
| Test específico, regresión antes del fix | FAIL esperado: 12 / 68, 6 fallos por 200 en lugar de 403 |
| `php vendor/bin/phpunit tests/Feature/Api/FaceIdTemplateSyncAuthorizationTest.php --testdox` después del fix | PASS: 12 tests / 85 assertions |
| `php vendor/bin/phpunit tests/Feature/Api tests/Feature/Permissions tests/Feature/Auth tests/Feature/Database/FaceAdministrationMigrationTest.php tests/Unit/EmployeeFaceAdministrationTest.php --no-progress` | PASS: 192 tests / 1232 assertions; incluye los 12 nuevos |
| `git diff --check` y whitespace de archivos nuevos | PASS |
| Builds web/móvil, MySQL, pruebas físicas, suite PHP total | NOT RUN: no necesarios para el alcance de esta ruta; sin cambios de frontend, mobile o esquema |

La matriz cubre no autenticado, token inválido/expirado, usuario sin permisos, admin/superadmin sin permiso, settings.manage, permiso de lectura, rol no administrativo con face.manage, administrador autorizado, preservación de template previo, lookup Fortia y conflicto entre empleados. Los rechazos no crean templates ni desactivan el existente ni marcan enrolamiento.

## Git y cierre

Archivos propios de SEC-01 únicamente:

1. `routes/api.php` — modificación de autorización de una ruta.
2. `tests/Feature/Api/FaceIdTemplateSyncAuthorizationTest.php` — nuevo test.
3. `docs/security/SEC-01-faceid-sync-authorization.md` — evidencia y contrato.

No staging, commit, tag ni deploy. AGENTS.md y su propuesta mantienen su hash; LAN, checkpoint beta, fase 14 y cambios ajenos no se incorporaron. La revisión del diff funcional de SEC-01 muestra exclusivamente los dos middlewares nuevos.

NEXT: SEC-01 puede cerrarse como reproducción y corrección de código verificadas. No falta una decisión de negocio para este parche, porque replica la autorización administrativa existente. La activación en un servidor requiere el despliegue autorizado y regenerar su caché de rutas según el proceso aplicable; no se realizó ni se comprobó el endpoint operativo. El hallazgo de protección criptográfica de templates permanece fuera de este cierre. No se continúa ninguna fase funcional.
