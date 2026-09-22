# Asistencia MDM — Server input

> Actualización vigente: RELEASE-0-STAGING-PREP, 2026-09-22, al final de este documento. La captura RELEASE-0-INPUT siguiente se conserva como evidencia histórica; los nuevos datos no acreditan infraestructura instalada.

## RELEASE-0-INPUT: captura de STAGING

Fecha: 2026-09-21. HEAD auditado: `38e1d95a47a0617e1ce45bb63e7529934f180da7`.
Baseline canónico: [production-readiness.md](production-readiness.md), consolidado en ese HEAD.
Resultado: **PARTIAL — captura documental completa; infraestructura real PENDING.**
Cloud readiness: **4/20 gates = 20%**. Deployment: **NO-GO**.

CONFIRMED acredita sólo el dato y alcance expresados, no instalación remota. PENDING significa desconocido o decisión pendiente; BLOCKED significa que falta una dependencia; NOT APPLICABLE requiere una condición explícita. Requisitos, defaults y propuestas no se convierten en datos de servidor confirmados.

El usuario confirmó en esta fase que no están definidos proveedor/servidor oficial, OS, recursos, dominio/DNS, MySQL, deploy path, TLS ni runner/manual. El servidor de otros sistemas no está acreditado como STAGING de Asistencia MDM. MySQL local y la LAN de desarrollo no se reutilizan como inputs cloud.

No pegar passwords, APP_KEY, tokens, claves privadas, teléfonos ni datos de empleados. Las IPs, usuarios y accesos operativos se entregarán por canal restringido; aquí se registra su estado, sin valores privados. EXTERNAL SECRET describe custodia requerida, no un secreto ya provisionado. No se leyó .env ni el registry privado; no se consultaron DB ni infraestructura externa.

## Fuentes y límites de evidencia

- Configuración/manifests versionados: composer.json, package.json, mobile/package.json, ambos package-lock.json, config/database.php, config/mail.php, seeders y app/Actions/EnsureSuperAdmin.php.
- Working tree pendiente: .gitlab-ci.yml, routes/console.php y ejemplos .env.example/.env.beta.example. Su contenido no equivale al artefacto de HEAD ni a una instalación.
- Inventario local read-only: sin remotes Git configurados; Node v20.20.2. Metadata local de npm 10.8.2 leída de su package.json; npm.cmd --version no pudo ejecutarse por EPERM del sandbox, no se acredita ejecución npm.
- Los locks son formato 3 y no fijan una versión npm exacta. Engines Vite web: ^18.0.0 || ^20.0.0 || >=22.0.0; Vite móvil: ^20.19.0 || >=22.12.0. Compatibilidad de engine no acredita soporte vigente de una rama.
- El formulario histórico docs/deployment/phase13.10-server-input.txt sigue vacío. No prevalece sobre los documentos canónicos ni aporta servidor.
- Sin pruebas funcionales nuevas, SSH, DNS, emisión TLS, migraciones, usuarios, cron, APK ni cambios de teléfonos. La fase no aprueba desplegar.

## Server input

| Campo | Estado | Valor / evidencia |
|---|---|---|
| CLOUD_PROVIDER | PENDING | Sin proveedor oficial confirmado |
| ENVIRONMENT | CONFIRMED | STAGING como objetivo; APP_ENV=staging en el contrato canónico, aún no instalado |
| SERVER_PUBLIC_IP | PENDING | Referencia por canal operativo restringido |
| SERVER_PRIVATE_IP | PENDING | No declarar N/A hasta resolver topología |
| REGION / NETWORK | PENDING | Región, red y firewall |
| OS | PENDING | Linux es propuesta del baseline, no servidor observado |
| OS_VERSION / ARCHITECTURE | PENDING | Debian 13 amd64 es propuesta, no inventario remoto |
| CPU_VCPU | PENDING | Recursos reales; no confundir con dimensionamiento propuesto |
| RAM_GB | PENDING | Capacidad real |
| DISK_GB / TYPE / FREE SPACE | PENDING | Capacidad, tipo y reserva reales |
| SSH_USER | PENDING | Cuenta operativa por canal restringido |
| SSH_PORT | PENDING | No asumir 22 desde defaults de scripts beta |
| SSH_ACCESS / HOST_KEY_VERIFICATION | PENDING | Método y huella verificados por canal seguro |
| DEPLOY_PATH | PENDING | No heredar rutas de beta/servidores ajenos |
| RUNTIME_USER / GROUP | PENDING | Propiedad y permisos |
| WEB_SERVER | PENDING | Nginx + FPM propuesto; selección real por confirmar |
| PHP_VERSION | CONFIRMED | Requisito ^8.4 en composer.json; objetivo baseline 8.4.x CLI/FPM; instalado PENDING |
| COMPOSER | CONFIRMED | Required en proceso de construcción/instalación; versión exacta y ubicación PENDING; baseline 2.x |
| NODE_VERSION | PENDING | Toolchain STAGING no fijado; engines arriba; Node 24 propuesto en baseline, local 20.20.2 no es target cloud |
| NPM_VERSION | PENDING | Pin STAGING pendiente; metadata local 10.8.2 no equivale a builder remoto |
| BUILD_LOCATION | PENDING | CI o builder controlado; Node no requerido en runtime si se entregan assets compilados |

## Domain input

| Campo | Estado | Valor / criterio |
|---|---|---|
| STAGING_DOMAIN | PENDING | No definido |
| STAGING_WEB_URL | PENDING | Derivar HTTPS sólo tras dominio aprobado |
| STAGING_API_ORIGIN | PENDING | Mismo origen propuesto, no aprobado |
| PRODUCTION_DOMAIN | PENDING | No definido |
| PRODUCTION_WEB_URL | PENDING | Sin derivación inventada |
| PRODUCTION_API_ORIGIN | PENDING | Sin derivación inventada |
| DNS_OWNER / CHANGE_PROCESS | PENDING | Confirmar responsable y control; sin consulta externa |
| WEB_API_SAME_ORIGIN | PENDING | Propuesta canónica, decisión operativa pendiente |

No hay dominios reales suministrados. Los ejemplos del baseline están marcados como sintéticos; no se convierten aquí en valores reales. No se modifica DNS.

## Database input y separación mínima

| Campo | Estado | Valor / criterio |
|---|---|---|
| MYSQL_HOST | PENDING | Ubicación local al futuro host o servicio privado gestionado; no reutilizar desarrollo |
| MYSQL_PORT | PENDING | 3306 propuesto por default config/database.php y ejemplo beta; puerto remoto no verificado |
| MYSQL_VERSION | PENDING | 8.4 LTS es propuesta del baseline, no versión instalada |
| STAGING_DATABASE_CREATED | PENDING | No hay evidencia; no afirmar NO como inventario remoto |
| STAGING_DB_NAME | PENDING | Schema propio separado |
| STAGING_DB_APPLICATION_USER_CREATED | PENDING | Sin consulta ni creación |
| STAGING_DB_MIGRATION_USER_CREATED | PENDING | Sin consulta ni creación |
| PRODUCTION_DATABASE_CREATED | PENDING | Sin evidencia |
| BACKUP_USER / PRIVILEGES | PENDING | Según herramienta y versión aprobadas |
| DB_NETWORK / TLS / CA | PENDING | Acceso restringido y transporte según topología |
| DB_CHARSET / COLLATION / TIMEZONE | PENDING | Objetivo utf8mb4/utf8mb4_unicode_ci/UTC; verificar en host |

Modelo propuesto, no grants ejecutados:

- APPLICATION USER: SELECT, INSERT, UPDATE, DELETE según tablas de aplicación, incluidas sesiones/cache/nonces cuando corresponda. Sin CREATE DATABASE, DROP DATABASE, GRANT ni privilegios globales; sin DDL ordinario.
- MIGRATION USER: temporal, limitado a la base de aplicación, con DML/DDL estrictamente necesarios para el plan de migraciones revisado. No fijar sentencia GRANT definitiva antes de conocer versión/schema; sin acceso a Fortia/mock ni administración global.
- BACKUP USER: independiente; mínimos según herramienta/versión, sin reutilizar runtime.
- Separar entornos y custodia. Scripts beta actuales aún usan la configuración DB de runtime para migrate: separación operativa pendiente.
- No ejecutar seed general ni normalizar datos/grants. Backup/restore y ensayo de migraciones siguen siendo gates antes del despliegue.

## TLS / proxy

| Campo | Estado | Valor / criterio |
|---|---|---|
| TLS_TERMINATION | PENDING | Host directo, proxy o LB por decidir |
| TLS_PROVIDER | PENDING | Certificado público; no trasladar CA local |
| REVERSE_PROXY | PENDING | No inferido |
| LOAD_BALANCER | PENDING | NOT APPLICABLE sólo si se aprueba arquitectura sin LB |
| TRUSTED_PROXIES | PENDING | Rangos/headers exactos si hay proxy; nunca wildcard |
| HSTS | PENDING | NOT ENABLED YET por esta fase; estado remoto desconocido; no habilitar sin dominio/TLS validado |
| RENEWAL_OWNER / ALERTS | PENDING | Responsable y renovación |

BETA_TRUSTED_PROXIES no configura por sí mismo staging/production. Debe resolverse la configuración real antes de exponer la app detrás de un proxy.

## CI/CD

| Campo | Estado | Valor / evidencia |
|---|---|---|
| SOURCE_REPOSITORY | PENDING | Checkout local confirmado; git remote vacío, URL remota no configurada |
| CI_PLATFORM | CONFIRMED | GitLab como formato de .gitlab-ci.yml; no servicio/pipeline remoto validado |
| RUNNER_AVAILABLE | PENDING | Tag vending-linux-beta en plantilla no prueba runner |
| RUNNER_OS | PENDING | Plantilla espera Linux; inventario remoto desconocido |
| DEPLOY_CREDENTIAL | PENDING | EXTERNAL SECRET; acceso restringido, no provisionado aquí |
| DEPLOY_BRANCH/TAG_POLICY | PENDING | Política STAGING sin definir; tags protegidos vending-beta y job manual actuales sólo aplican a beta |
| RUNNER_OR_MANUAL | PENDING | Decisión explícitamente pendiente por usuario |
| CI_APPROVER / TOOLCHAIN | PENDING | Responsable, entorno protegido e imagen/versiones |
| SECRET_STORE_REFERENCE | PENDING | Canal seguro, sin valores |

La plantilla pendiente no constituye pipeline STAGING listo. Una decisión manual/controlada no elimina revisión de artefacto, credenciales, backup ni aprobación.

## Mail

| Campo | Estado | Valor / criterio |
|---|---|---|
| MAIL_PROVIDER | PENDING | Sin proveedor |
| MAIL_TRANSPORT | PENDING | SMTP/proveedor aprobado, sandbox para staging |
| MAIL_FROM_ADDRESS | PENDING | Remitente por confirmar |
| MAIL_FROM_NAME | CONFIRMED | Asistencia MDM, objetivo canónico; no configuración remota observada |
| MAIL_CREDENTIALS | PENDING | EXTERNAL SECRET |
| PASSWORD_RECOVERY | BLOCKED | Hasta entrega real, URL y pruebas de recuperación |
| MAIL_SANDBOX / RECIPIENT_POLICY | PENDING | Destinatarios sintéticos autorizados y DNS de correo |

MAIL_MAILER=log no es solución productiva y puede registrar enlaces sensibles. Mail no bloquea preparar infraestructura base sin usuarios, pero sí validar recuperación y apertura del servicio.

## Backup y monitoring

| Campo | Estado | Valor / criterio |
|---|---|---|
| DATABASE_BACKUP | PENDING | Herramienta y frecuencia |
| PRIVATE_STORAGE_BACKUP | PENDING | Consistencia con DB y evidencias |
| BACKUP_DESTINATION | PENDING | Referencia privada off-server |
| RPO | PENDING | Objetivo real aprobado; 24h del baseline sólo propuesto |
| RTO | PENDING | Objetivo real aprobado; 4h del baseline sólo propuesto |
| RETENTION | PENDING | Política y custodio, no aprobación por defecto |
| ENCRYPTION | PENDING | Cifrado y custodia externa |
| RESTORE_TEST | BLOCKED | NOT RUN; sin destino/backup |
| UPTIME_MONITORING | PENDING | Proveedor y alertas |
| ERROR_MONITORING | PENDING | Captura sanitizada y responsable |
| LOG_AGGREGATION | PENDING | Destino y retención |
| DISK_ALERT | PENDING | Espacio/inodos |
| DB_ALERT | PENDING | Disponibilidad/conexiones |
| QUEUE_ALERT | PENDING | NOT APPLICABLE sólo si se aprueba sync; driver STAGING no confirmado |
| SCHEDULER_ALERT | PENDING | Heartbeat/último éxito |
| OPERATIONS_OWNER / INCIDENT_CONTACT | PENDING | Responsable operativo |
| STORAGE_PATHS / CAPACITY / RETENTION | PENDING | Privado/público, permisos y política |
| MAINTENANCE / ROLLBACK_OWNER | PENDING | Ventana y responsable |

## Scheduler policy: propuesta sin autorización de ejecución

Inventario completo de llamadas Schedule de aplicación encontrado en routes/console.php del working tree. Ninguna tarea fue ejecutada. Todas usan withoutOverlapping; necesitan locks/cache y reloj coherentes. **No instalar cron todavía. BETA_CLEANUP_ENABLED=false no desactiva las limpiezas fuera de beta.**

ENABLE-STAGING / ENABLE-PRODUCTION son propuestas condicionadas, no aprobaciones. DECISION recoge la resolución efectiva pendiente.

| COMMAND | PURPOSE | ENVIRONMENT | DEFAULT CURRENT BEHAVIOR | PROPOSED STAGING | PROPOSED PRODUCTION | DECISION |
|---|---|---|---|---|---|---|
| device-nonces:prune | Borra nonces HMAC vencidos, batch default 5000 | Todos | Cada minuto, sin gate beta | ENABLE-STAGING tras prueba sintética de expiración/locks | ENABLE-PRODUCTION tras validación de reloj/retención | DECISION-REQUIRED: aprobar cron, TTL y observabilidad |
| employees:prune-imports | Elimina filas de import expiradas, conserva metadata y marca previews expirados | No beta; beta sólo cleanup_enabled=true | Cada hora en staging/production; TTL de config | DECISION-REQUIRED: acordar ventana de revisión de imports | DECISION-REQUIRED: retención aprobada por custodio | DECISION-REQUIRED; no asumir consentimiento para borrar |
| audit:cleanup --optimize | Retención de auditoría y optimización de tablas | No beta; beta sólo cleanup_enabled=true | Diario, hora default 03:00; settings pueden prevalecer | DISABLE hasta política y ensayo seguro | DISABLE hasta backup, privilegios y ventana | DECISION-REQUIRED: separar evaluación de cleanup y OPTIMIZE; no cambiado |
| sybi:sync-vending | Lectura y proyección SYBI | No beta y sync_enabled=true | Flag default false; si true evaluación cada minuto según intervalo default 60 | DISABLE sin sandbox/contrato aprobado | DISABLE sin autorización de integración | DECISION-REQUIRED: mantener apagado hasta contrato |

inspire y dev:templates:* son comandos manuales, no scheduled tasks. No añadirlos al cron; no programar soporte/Fortia/OTP cleanup por suposición. Al instalar cron se activaría todo lo que permita el código: las propuestas DISABLE anteriores exigen configuración o cambio separado aprobado; esta fase no implementa esos controles.

## RBAC input

Inventario de definiciones de código, no de roles/grants de una DB remota. Fuentes: RolePermissionSeeder, VendingPilotUsersSeeder, SupportPermissionsSeeder, VendingDemoSeeder y EnsureSuperAdmin. Sin consultas ni modificaciones de usuarios.

| ROLE | CURRENT PURPOSE | PRODUCTION CANDIDATE | PILOT ONLY | DEMO ONLY | LEGACY | REVIEW REQUIRED |
|---|---|---|---|---|---|---|
| Administrador | Catálogo all en seeder; nombre no otorga permisos por sí solo | Sí, condicionado | No | No | Matriz heredada | Sí: custodio y grants mínimos |
| Capturista | Catálogos y edición/export legacy | Condicionado | No | No | Sí | Sí |
| Supervisor | Supervisión, edición y export legacy | Condicionado | No | No | Sí | Sí |
| Consulta | Lectura legacy | Condicionado | No | No | Sí | Sí |
| admin / superadmin / super admin | Alias reconocidos por acción administrativa | No aprobado | No | No | Sí | Sí: existencia real desconocida |
| Vending Pilot Admin | Administración piloto, lectura asistencias TA-0C y soporte | No | Sí | No | No | Sí |
| Vending Pilot Operator | Operación piloto y soporte acotado | No | Sí | No | No | Sí |
| Vending Pilot Support | Atención/asignación/resolución piloto | No | Sí | No | No | Sí |
| Vending Pilot Viewer | Consulta piloto | No | Sí | No | No | Sí |
| Vending Demo Admin | Fixture local/testing | No | No | Sí | No | Sí |

RBAC_STATUS: PENDING. INITIAL_ADMIN_PROVISIONING, APPROVED_INITIAL_DATA_SCOPE e INTEGRATIONS_ENABLED/CONTRACTS: PENDING. No asumir grants finales ni que Administrador evita autorización. Evitar seeders generales, fallback al primer usuario y EnsureSuperAdmin sin selección/revisión explícita. No incorporar usuarios piloto a producción.

## Mobile dependencies y alcance

| Campo | Estado | Valor / límite |
|---|---|---|
| DOMAIN_READY | CONFIRMED | YES en código CP-C06, no DNS/TLS remoto |
| MOBILE_STAGING_BUILD | BLOCKED | Hasta dominio/TLS real; sin APK ni cambios de build |
| OTP | CONFIRMED | LOCAL_SIMULATED actual, no verifica posesión telefónica real |
| PRODUCTION_OTP | BLOCKED | Simulación fail-closed; PROD-IDENTITY-01 pendiente, también limita FIELD_MOBILE en staging |
| STORE | PENDING | MOBILE-STORE-01 sin iniciar |
| SIGNING | PENDING | Custodia y artefacto de release, no inferir desde Build12 |

STORED sigue siendo sólo recepción del evento; no asistencia laboral oficial, prenómina, pago ni envío a Fortia. No generar eventos ni ampliar alcance laboral durante preparación de infraestructura.

## MINIMUM REQUIRED TO BEGIN STAGING

Inputs prioritarios para RELEASE-0-STAGING-PREP; todos **PENDING** tras confirmación del usuario:

1. Proveedor y servidor oficial, región/capacidad, referencia IP/ruta de acceso, usuario/puerto/método SSH y verificación de host key por canal restringido. Si aún no existe, especificar el destino y autorización de provisión; no asumir un host de otro sistema.
2. OS, versión/arquitectura y recursos reales o aprobados (CPU/RAM/disco).
3. Dominio STAGING aprobado y decisión web/API mismo origen o separados.
4. Responsable/control DNS y procedimiento de cambios.
5. Ubicación, versión y puerto MySQL; estado de creación y schema previsto, proceso de cuentas runtime/migración. No exigir passwords en el documento.
6. Deploy path y modelo de propiedad/usuario runtime.
7. Modelo de terminación TLS (directo/proxy/LB), emisor público previsto y red de confianza si aplica.
8. Decisión runner GitLab o manual/controlado; repositorio/artefacto fuente y acceso disponible para esa modalidad.

No son prerequisitos para redactar la preparación base: SMTP, plataforma de monitoring, dominio de producción o store móvil. Siguen siendo gates antes de habilitar los flujos correspondientes. No instalar cron ni habilitar flujos destructivos sólo por completar estos inputs. Identidad real bloquea onboarding productivo, no el diseño de la infraestructura base. Preparación no equivale a autorización para crear recursos o desplegar.

## Release gates recalculados

Misma evidencia que baseline: ningún gate nuevo cerrado. Estados normalizados a PASS/PARTIAL/BLOCKED/NOT RUN/NOT APPLICABLE; INPUT REQUIRED y NOT CONFIGURED del baseline se expresan aquí como BLOCKED por inputs ausentes. Denominador fijo 20; sólo PASS suma. No gates NOT APPLICABLE acreditados.

| Gate | Estado | Evidencia / pendiente |
|---|---|---|
| 01 Stack/monolito | PASS | Manifests/locks identificados, no host |
| 02 Mock externo bloqueado | PASS | Guard consolidado, sin reapertura del hallazgo |
| 03 Sanctum stateful | PASS | Hardening consolidado |
| 04 Dominio móvil | PASS | CP-C06 en código |
| 05 Host/OS/red | BLOCKED | Inputs 1–2 |
| 06 DB/grants/timezone | BLOCKED | Input 5 y validación |
| 07 Domain/TLS/proxy | BLOCKED | Inputs 3–4 y 7 |
| 08 Artefacto consolidado | BLOCKED | Deployment/config aún pendientes |
| 09 Config/secrets/session/cache | PARTIAL | Diseño, sin instalación ni pruebas remotas |
| 10 Migraciones MySQL target | NOT RUN | Sin target ni ensayo |
| 11 Storage/permisos | PARTIAL | Diseño, sin roundtrip remoto |
| 12 Cron/queue/retención | PARTIAL | Inventario, decisiones sin aprobar |
| 13 Mail/recovery | BLOCKED | Entrega no configurada |
| 14 Backup/restore | BLOCKED | Destino/estrategia pendientes, restore NOT RUN |
| 15 Monitoring/alertas | BLOCKED | Sin servicio ni responsables confirmados |
| 16 Deploy/rollback | PARTIAL | Diseño beta insuficiente para staging |
| 17 RBAC | PARTIAL | Inventario, sin matriz productiva aprobada |
| 18 Identidad/OTP | BLOCKED | LOCAL_SIMULATED no productivo |
| 19 Mobile distribution/signing | BLOCKED | Store/signing pendientes |
| 20 Alcance laboral aceptado | BLOCKED | Falta aceptación operativa de límites STORED/Fortia |

Resultado: **4 PASS + 5 PARTIAL + 10 BLOCKED + 1 NOT RUN = 20; 20%.**
production-readiness.md conserva el baseline histórico sin modificación: los estados reales no cambiaron. Esta fase sólo actualiza este formulario. Próximo paso: aportar los ocho bloques mínimos, sin secretos; RELEASE-0-STAGING-PREP bloqueado por esos inputs. No deploy, staging Git, commit ni inicio automático de otras fases.

## RELEASE-0-STAGING-PREP — 2026-09-22

HEAD: `0dff0c2a150b90cbf7c454c9dc8ef2489b36fb9f`. Resultado actual: **PARTIAL; STAGING-INSTALL BLOCKED**. Esta sección prevalece para los inputs actualizados. Fuentes: confirmación del usuario, consultas DNS de esta fecha, contrato CP-C06 y lectura de código/configuración actual. Sin acceso SSH, cambios remotos, lectura de claves o .env, instalación ni deployment.

### Dominio y certificado: evidencia nueva

| Campo | Estado | Evidencia |
|---|---|---|
| STAGING_DOMAIN | CONFIRMED como hostname suministrado | mdf.antlia.mx; confirmación de exactitud/DNS pendiente tras NXDOMAIN |
| STAGING_WEB_URL | Derivación HTTPS, no servicio acreditado | https://mdf.antlia.mx; puerto 443 y raíz como candidato, ruta real no suministrada |
| STAGING_API_ORIGIN | PENDING | https://mdf.antlia.mx sólo candidato si se confirma mismo origen |
| DNS IPv4 / IPv6 | BLOCKED | Consultas A y AAAA: NXDOMAIN tanto resolver local como consulta explícita a 1.1.1.1 |
| TARGET / PUBLIC IP | PENDING | Ninguna dirección obtenida; no se puede comprobar host objetivo ni descartar destino LAN |
| TLS_CERTIFICATE | AVAILABLE según usuario | Material público no suministrado; no validado |
| TLS_PRIVATE_KEY | EXTERNAL SECRET | NO LEER / NO VERSIONAR; no solicitada |
| TLS CERT | INCOMPLETE | Conexión TLS a hostname:443 detenida en resolución DNS, sin handshake |
| Subject / SAN / issuer / inicio / expiry | PENDING | No certificado público inspeccionable |
| CHAIN / HOST MATCH | NOT RUN | No afirmar FAIL criptográfico ni PASS sin certificado |
| DNS CONTROL / CHANGE AUTHORIZATION | PENDING | No se modificó DNS |

La disponibilidad declarada del certificado no prueba cadena, vigencia, SAN ni confianza Android. No se imprimió PEM ni se buscaron claves. Para continuar: confirmar hostname exacto/DNS y aportar sólo ubicación del certificado público y cadena, o servirlo en el endpoint correcto. La clave privada permanece fuera del alcance.

### Servidor, deployment, runtime y DB

Provider, IP pública/privada, OS/versión/arquitectura, CPU/RAM/disco, SSH user/port y autorización/acceso concreto: **PENDING**. No se infieren del equipo local ni de otros sistemas. Sin SSH no se ejecutaron uname/os-release/nproc/free/df ni comandos de versiones en host remoto.

DEPLOY_PATH: PENDING; existencia, owner, group, filesystem y espacio: NOT RUN. No se inventa /var/www ni se copia el proyecto.
WEB_SERVER: PENDING. Diseño requerido: document root del release activo limitado a public; si DEPLOY_PATH es raíz de releases, sería DEPLOY_PATH/current/public, mientras que si designa el release activo sería DEPLOY_PATH/public. Resolver ese contrato antes de crear vhost. Laravel front controller public/index.php, assets estáticos y FPM/FastCGI con HTTPS correcto; no PHP arbitrario subido ni archivos privados publicados.

| Runtime / extensión | Requisito del proyecto | Estado remoto |
|---|---|---|
| PHP | ^8.4, CLI/FPM coherentes | PENDING, no versión instalada verificada |
| pdo_mysql, mbstring, openssl, fileinfo, zip | REQUIRED | NOT RUN, no afirmar MISSING |
| xml, dom, libxml, simplexml, xmlreader, xmlwriter | REQUIRED por framework/importación | NOT RUN |
| gd con JPEG/PNG/WebP | REQUIRED por PhpSpreadsheet y SupportImageSanitizer | NOT RUN |
| curl (PHP) | NOT REQUIRED universalmente; recomendado según transporte | NOT RUN; binario curl sí requerido por healthcheck |
| intl | NOT REQUIRED en baseline; polyfills, revisar funciones opcionales | NOT RUN |
| bcmath | NOT REQUIRED como dependencia obligatoria observada | NOT RUN |
| Composer | REQUIRED en pipeline de instalación; versión exacta pendiente | NOT RUN |
| Node/npm | Builder, no runtime web obligatorio | Target PENDING; no ejecutar builds |

Clasificar INSTALLED/MISSING requiere inventario remoto; no sustituirlo por extensiones de la laptop.
MYSQL_HOST/PORT/VERSION: PENDING; 3306 es sólo default propuesto. DB, application user y migration user: PENDING, no evidencia para CREATED/NOT CREATED. Sin consulta DB, root, creación ni migración. Mantener separación runtime CRUD/migración/backup del modelo anterior; grants finales sujetos a versión/schema.

### App config matrix (objetivo, no .env listo)

Valores candidatos no aplicados; hostname aún sin DNS. SECRET=NO significa que el valor documentado no es secreto, no que toda configuración esté completa.

| VARIABLE | EXPECTED STAGING VALUE | SOURCE | SECRET |
|---|---|---|---|
| APP_ENV | staging | Contrato canónico | NO |
| APP_DEBUG | false | Contrato canónico | NO |
| APP_NAME | Asistencia MDM | Producto | NO |
| APP_URL | https://mdf.antlia.mx candidato; confirmar raíz/puerto | Host suministrado + config/app.php | NO |
| APP_BASE_PATH / VITE_APP_BASE_PATH | Vacío si raíz confirmada | Baseline; subpath requiere revisión | NO |
| API_BASE_URL / VITE_API_BASE_URL (web) | Vacío para API relativa si mismo origen | config/app.php / baseline | NO |
| SESSION_DOMAIN | null, cookie host-only | config/session.php / baseline | NO |
| SESSION_SECURE_COOKIE / SESSION_HTTP_ONLY | true / true | config/session.php | NO |
| SESSION_ENCRYPT / SESSION_SAME_SITE | true / lax | Baseline | NO |
| SESSION_DRIVER / CACHE_STORE | database / database propuesto | Baseline | NO |
| SESSION_COOKIE / CACHE_PREFIX | Exclusivos STAGING, nombres PENDING | Aislamiento requerido | NO |
| SANCTUM_STATEFUL_DOMAINS | mdf.antlia.mx candidato; puerto si no estándar, sin scheme/path | config/sanctum.php | NO |
| VENDING_CORS_ALLOWED_ORIGINS | https://localhost sólo para cliente Android correspondiente; lista exacta por validar | config/cors.php; NO es URL backend | NO |
| BETA_ALLOWED_ORIGIN / BETA_APP_URL | No definidos para staging; no gobiernan APP_ENV=staging | config/cors.php, config/app.php | NO |
| INTERNAL_BETA_ENABLED / INTERNAL_BETA_TESTERS_ENABLED | false / false | Contrato staging | NO |
| BETA_TRUSTED_PROXIES | No usar como configuración de proxy STAGING; mecanismo/rangos pendientes | BetaHttpBoundary sólo actúa en beta | NO |
| BETA_CLEANUP_ENABLED | false; NO desactiva limpieza en staging | routes/console.php | NO |
| QUEUE_CONNECTION | sync propuesto, decisión pendiente | Sin jobs de aplicación encontrados | NO |
| DB_CONNECTION / DB_PORT | mysql / 3306 propuesto, endpoint real PENDING | config/database.php | NO |
| DB_HOST / DB_DATABASE / DB_USERNAME | PENDING; acceso real por canal restringido | Inputs ausentes | NO, metadata restringida |
| FORTIA_MOCK_MIGRATIONS_ENABLED | false | Guard consolidado | NO |
| SYBI_VENDING_SYNC_ENABLED | false hasta contrato | Scheduler | NO |
| APP_KEY / DB_PASSWORD / MAIL_PASSWORD | EXTERNAL SECRET, sin valores | Custodia por entorno | YES |

APP CONFIG: PARTIAL, no desplegable. No copiar variables locales ni cache. No ampliar CORS/CSRF: el CORS observado sólo cubre api/v1/device/* y FIELD_MOBILE usa transporte nativo. No habilitar simulación para hacer funcionar staging; OTP real sigue bloqueado.

### Mobile origin y TLS termination

BETA_API_BASE_URL / BETA_FIELD_IDENTITY_BASE_URL: **BLOCKED para asignación final**. Candidato de ambas: https://mdf.antlia.mx, sólo si API e identidad comparten origen confirmado. CP-C06 exige HTTPS, DNS concreto, sin IP/localhost/wildcard/userinfo/query/fragment ni subpath. El nombre pasa forma sintáctica por inspección, no validación DNS/TLS.

El modo mobile no es APP_ENV: deployment-origins.mjs acepta development/beta/pilot/production, no staging. Build beta usa las dos variables BETA explícitas; otros modos usan VITE_DEPLOYMENT_MODE, VITE_API_BASE_URL y VITE_FIELD_IDENTITY_BASE_URL. Selección del modo y signing siguen pendientes; no configurar development como atajo. No se modificó mobile/.env, no cap sync/APK ni origin migration. Release confía en CA del sistema; certificado disponible no implica confianza válida.

TLS_TERMINATION / REVERSE_PROXY / LOAD_BALANCER / TRUSTED_PROXIES: PENDING. Si termina TLS en web server, FPM debe recibir HTTPS real. Si hay proxy/LB: fijar rangos exactos, restringir acceso directo, controlar X-Forwarded-Proto y Client IP sin confiar en headers de Internet; preservar/validar Host del dominio aprobado. No trusted proxies=* ni asumir que BETA_TRUSTED_PROXIES protege staging. HSTS no habilitado por esta fase; estado remoto desconocido.

### Storage, scheduler, queue, readiness y backup

Storage requerido: storage/app/private, storage/app/support-private y storage/logs persistentes; bootstrap/cache escribible por proceso autorizado. Código sólo lectura para FPM salvo rutas necesarias; owner/grupo reales pendientes. Referencia 0750/0640 con grupo/umask revisados, nunca chmod777. No exponer privados; public/storage sólo puede apuntar a storage/app/public. Permisos/disco: NOT RUN.

| COMMAND | CURRENT BEHAVIOR | STAGING DECISION | PRODUCTION DECISION |
|---|---|---|---|
| device-nonces:prune | Cada minuto, batch expirados, withoutOverlapping | DECISION-REQUIRED; candidato tras validar reloj/locks | DECISION-REQUIRED |
| employees:prune-imports | Horario fuera beta; beta exige cleanup_enabled=true | DECISION-REQUIRED sobre TTL y borrado | DECISION-REQUIRED |
| audit:cleanup --optimize | Diario default03:00 fuera beta, settings pueden prevalecer | DISABLE propuesto hasta política/backup/ventana | DISABLE propuesto, no aplicado |
| sybi:sync-vending | Fuera beta y flag true; evaluación minuto/intervalo default60 | DISABLE hasta contrato | DISABLE hasta contrato |

CRON: **NOT INSTALLED por esta fase**; estado remoto NOT RUN. No instalarlo hasta aprobar las cuatro tareas y materializar controles de las propuestas DISABLE. BETA_CLEANUP_ENABLED=false no controla limpiezas en staging/production.

QUEUE WORKER: NOT REQUIRED CURRENTLY para jobs de aplicación observados: app/Jobs ausente y búsqueda de ShouldQueue/dispatch/onQueue sin jobs de aplicación. sync es propuesta suficiente para ese inventario, no driver remoto confirmado. Mail síncrono afecta latencia; revalidar si se añaden jobs. No Redis ni worker instalado.

- /up: ruta health en HEAD, acredita arranque Laravel, no DB ni esquema; puede responder durante maintenance. NOT RUN en STAGING.
- /ready: route/controller/probe aún pendientes fuera de HEAD. Necesita artefacto consolidado, configuración DB para SELECT1 y directorios local/support_private con creación/lectura/borrado de archivo temporal. No es comprobación puramente read-only del filesystem, no se invocó. No acredita migrations/cache/mail/cron.
- Antes de declaración READY: despliegue autorizado del artefacto exacto, runtime/config/permisos, DB y discos; luego comprobar HTTP200/payload sin secretos, validación funcional separada de schema/cache/session.
- DB backup mechanism y decisión de backup de privados: PENDING; restore no ensayado. **MIGRATION DEPLOYMENT: BLOCKED**. No backup ejecutado.
- DEPLOY METHOD: PENDING runner/manual. Manual controlado es alternativa permitida para decidir, no seleccionada automáticamente ni ejecutada.

### STAGING PREP GATES (operativos)

Esta lista G01–G20 solicitada mide preparación operativa, distinta de los 20 gates de código/diseño del baseline. No se sustituye una métrica por otra: baseline cloud permanece **4/20 = 20%**; gates operativos STAGING abajo **0/20 PASS = 0%**. Un hostname o certificado declarado disponible no cierra un gate operacional.

| Gate | Estado | Evidencia / bloqueo |
|---|---|---|
| G01 SERVER | BLOCKED | Target/acceso pendientes |
| G02 OS | BLOCKED | Inventario remoto ausente |
| G03 RUNTIME | NOT RUN | Sin host; requisitos identificados |
| G04 DATABASE | BLOCKED | Host/versión/cuentas/esquema pendientes |
| G05 DOMAIN | PARTIAL | Host suministrado; servicio/raíz y exactitud tras NXDOMAIN pendientes |
| G06 DNS | BLOCKED | NXDOMAIN A/AAAA en consultas realizadas |
| G07 TLS | BLOCKED | Disponible según usuario, material público no validado |
| G08 WEB SERVER | BLOCKED | Selección y configuración real pendientes |
| G09 APP CONFIG | PARTIAL | Matriz candidata, dependencias pendientes |
| G10 STORAGE | NOT RUN | Diseño sin owner/disco/permisos remotos |
| G11 MIGRATIONS | BLOCKED | Artefacto/ensayo target/backup pendientes |
| G12 BACKUP | BLOCKED | Mecanismo y restore no disponibles como evidencia |
| G13 READINESS | NOT RUN | Sin despliegue, /ready aún pendiente de consolidar |
| G14 SCHEDULER | PARTIAL | Inventario completo, aprobación/controles pendientes |
| G15 EMAIL | BLOCKED | Sin entrega real |
| G16 MONITORING | BLOCKED | Sin configuración/responsable confirmado |
| G17 RBAC | PARTIAL | Catálogo, no matriz ni provisión remota aprobada |
| G18 MOBILE DOMAIN | PARTIAL | Código listo, dominio sin DNS/TLS verificados |
| G19 DEPLOYMENT | BLOCKED | Método/artefacto/accesos pendientes |
| G20 ROLLBACK | BLOCKED | Sin ensayo ni backup/restore |

Conteo operativo: 0 PASS, 5 PARTIAL, 12 BLOCKED, 3 NOT RUN, 0 NOT APPLICABLE. No aumento de readiness por certificado.

### Bloqueantes mínimos antes de STAGING-INSTALL

1. Confirmar hostname exacto y DNS A/AAAA/CNAME hacia target aprobado; responsable DNS. Sin cambios DNS en esta fase.
2. Inspeccionar certificado público/cadena: Subject/SAN/issuer/fechas, hostname y confianza; elegir terminación TLS.
3. Servidor oficial, OS/arquitectura/recursos, target y acceso administrativo autorizado por canal restringido.
4. Deploy path, web server/FPM y ownership/storage aprobados con capacidad suficiente.
5. MySQL endpoint/versión/schema, estado de DB/cuentas y mecanismo seguro de provisión/grants, sin root runtime.
6. Runner o manual/controlado, artefacto consolidado incluyendo readiness/config requeridos y configuración STAGING final.
7. Mecanismo DB backup, decisión de privados y plan restore/rollback antes de habilitar migración.
8. Alcance inicial aprobado: cron apagado hasta decisión, integraciones apagadas; RBAC explícito. Identidad real, mail y monitoring conservan sus gates para los flujos correspondientes.

RELEASE-0-STAGING-INSTALL: BLOCKED. No se modifica production-readiness.md porque ningún gate del baseline se cerró. HONOR, Motorola y Build12: UNTOUCHED. Cambios funcionales propios: 0; sin staging Git/commit/deploy/push/tag.
