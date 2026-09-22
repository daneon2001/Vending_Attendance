> Actualización Phase 13.9A: este documento conserva el descubrimiento/propuesta de 13.9. Para implementación, rutas, guards y resultado vigentes, usar [phase13.9A-implementation.md](phase13.9A-implementation.md) y [phase13.9A-result.md](phase13.9A-result.md). No ejecutar instrucciones históricas sin esos gates.

# Pipeline Vending — diseño para Phase 13.10

No se creó ni activó YAML nuevo en GitLab. El YAML heredado NO es apto para publicar este proyecto: véase discovery. Pipeline propuesto Linux aislado, sin acceso a DB local/Fortia/producción durante CI.

## Etapas y gates

| Stage | Contenido y criterio de salida | Secretos/acceso |
|---|---|---|
| validate | composer validate, lock consistency, sintaxis, diff check, scan secretos y manifiesto branding; rechazo de URLs/rutas/runners Fortia en pipeline/artifact | Ninguno |
| test | Backend en DB desechable, tests safety antes de funciones; mobile unit; comprobar instalación limpia y upgrade del schema aislado | DB CI efímera únicamente |
| build | PHP vendor desde lock y frontend npm ci/build en builder Linux fijado; Android separado y solo tras dominio/firma aprobados | Sin .env servidor |
| package | Tar cerrado por allowlist, manifest de archivos y SHA256, commit SHA, lock hashes, versiones, migraciones | Excluir secretos/datos/keys |
| deploy_beta | Job manual, environment beta protegido, resource_group vending-beta, allow_failure=false, sin interrupt tras activar | SSH beta solamente |
| healthcheck | needs deploy_beta, no puede saltarse el gate manual; HTTPS, versión, readiness, rutas privadas inaccesibles | Credencial de health limitada si necesaria |

Branches/MR: validate/test/build sin secretos de deploy. Releases: tag protegido propuesto `internal-beta/<version>-build.<n>` que apunte al checkpoint revisado; tag no creado. Deploy solo desde ref protegida aprobada, por operador autorizado. No autodeploy main/qa y ningún job production. Antes de primer push, retirar la ejecución de scripts Fortia. El job manual debe fallar si no hay host/domain aprobados o si encuentra placeholders `.invalid`, rutas Windows o remote antiguo.

El paquete backend incluye app/bootstrap/config/database (migraciones aprobadas)/public/resources/routes/vendor/artisan/composer lock+json. Excluye .env*, .git, storage local, tests, node_modules, mobile/android caches, docs privadas, backup SQL, registry, credentials, CA DEMO, APKs. Revisar exclusiones también de scripts heredados. Crear shared/storage vacío en host y migrar solo selección de datos autorizada. Checksums no sustituyen autenticidad: artefactos procedentes de pipeline/ref protegidos.

No usar `composer create-project` ni tests con .env real. `composer install` ejecuta package:discover: hacerlo con config efímera segura y sin dependencias remotas. No tests contra vending_attendance_dev; sin cache de configuración real en runner. TestDatabaseGuard permite instalación normal y protege fresh/wipe/rollback en DB no-test. La nueva suite debe verificar beta explícita aceptada y production rechazada para simulación/registry. Android tests deben probar HTTP/userCA rechazados, transición exacta y ausencia de regeneración.

## Variables propuestas, NO creadas

Todas las variables de deploy PROTECTED y ENVIRONMENT_SCOPED=beta; disponibles solo en jobs confiables. Masking no protege de código malicioso: refs/runners y revisión de scripts son parte del control.

| Nombre | Tipo/visibilidad | Contenido |
|---|---|---|
| BETA_SSH_HOST / PORT / USER | Variable; valor no secreto, protected | Inputs exactos, no defaults Fortia |
| BETA_DEPLOY_PATH | Variable protected | /var/www/vending-attendance propuesto, validar ruta |
| BETA_APP_URL | Variable protected | HTTPS host aprobado |
| BETA_SSH_PRIVATE_KEY | FILE protected, scope beta | Clave dedicada no root; no imprimir; multilinea puede no admitir masked |
| BETA_SSH_KNOWN_HOSTS | FILE protected, scope beta | Host key validada fuera del job, no ssh-keyscan ciego |
| BETA_HEALTH_TOKEN | MASKED/HIDDEN protected si se implementa | Token de health mínimo, no usuario humano |
| VENDING_ANDROID_KEYSTORE | FILE protected en job de firma específico | Ruta a keystore autorizado, no crear en esta fase |
| VENDING_ANDROID_STORE_PASSWORD / KEY_PASSWORD | MASKED/HIDDEN protected | Secretos separados del keystore |
| VENDING_ANDROID_KEY_ALIAS | Variable protected | Alias de firma APK, distinto de alias FIELD_MOBILE |

GitLab documenta variables FILE para SSH y que una clave multilínea puede no cumplir masking. Nunca cat/tee/echo de su contenido ni CI_DEBUG_TRACE; permisos0600 y agente efímero. [GitLab SSH](https://docs.gitlab.com/ci/jobs/ssh_keys/), [variables](https://docs.gitlab.com/ci/variables/).

APP_KEY, DB password, registry telefónico, SMTP y secretos de servicios permanecen server-side/secret store. No duplicarlos en variables de frontend ni en artifacts. Si se aprueba provisión CI del .env, será FILE protegida con scope beta y destrucción temporal; preferencia inicial provisión fuera del pipeline de código.

## SSH y despliegue

SSH/SFTP/rsync hacia carpeta release nueva, nunca --delete sobre shared ni rsync --delete al current. Rechazar ruta vacía, / o fuera de DEPLOY_PATH. set -euo pipefail en scripts Linux y comprobación explícita de checksums/exitcodes. Known host fijado, StrictHostKeyChecking=yes. Solo helper limitado para activar/reload, no root remoto. Escribir evidencia deploy con commit, artifact hash, backup ID, schema antes/después y health sin secretos. Abortado antes de pointer mantiene anterior; fallo después requiere rollback compatible, no migration rollback automático.

## Distribución APK

| Canal | Ventaja | Límite | Propuesta |
|---|---|---|---|
| Artifact GitLab privado | Trazabilidad commit/build/hash | Caducidad y cuenta GitLab | Primeros testers técnicos |
| Descarga interna HTTPS autenticada | Acceso sencillo y hash visible | Requiere permisos, caducidad y catálogo | Beta de compañeros |
| APK Manager corporativo | Inventario/versionado potencial | API/seguridad desconocidas | Discovery posterior |
| MDM | Distribución controlada y revocación | Requiere tenant/licencias/equipos inscritos | Si ya disponible |

No publicar APK ni compartir vínculo en esta fase. Firma, package, versionCode y origen deben constar junto al hash y baseline branding. No incluir registro de testers en APK. Segundo Android físico sigue diferido.
