> Actualización Phase 13.9A: este documento conserva el descubrimiento/propuesta de 13.9. Para implementación, rutas, guards y resultado vigentes, usar [phase13.9A-implementation.md](phase13.9A-implementation.md) y [phase13.9A-result.md](phase13.9A-result.md). No ejecutar instrucciones históricas sin esos gates.

# Phase 13.9 — preparación del servidor INTERNAL BETA

Fecha: 2026-09-14. Discovery y diseño terminados; readiness operativo PARTIAL. No se desplegó, compiló otra APK, modificó la DB, creó DNS ni modificó GitLab. El servidor es INTERNAL BETA; no es REAL PILOT ni PRODUCTION.

## Decisión y bloqueos demostrados

Arquitectura propuesta: Debian 13 amd64, Nginx + PHP-FPM 8.4, MySQL 8.4 LTS en el mismo servidor para la beta pequeña, assets construidos en CI con Node 24 LTS. Redis no requerido. Un solo dominio en raíz y puerto 443. No implica capacidad certificada para 1000 equipos.

Antes de exposición pública deben cerrarse estos gates, mediante cambios revisados y pruebas aisladas:

| Gate | Evidencia | Trabajo pendiente |
|---|---|---|
| Identidad beta | `DeviceIdentityService` y `LocalOtpProvider` rechazan entornos fuera de local/testing; `EnrollmentIdentity`, `BetaTesterPolicy`, `LocalBetaTesterRegistry`, `LocalDemoPhone` también limitan elegibilidad/lectura | Incorporar modo beta explícito, acotado, denegado en producción. No usar APP_ENV=local en servidor público. Preservar owner A y política B |
| Recuperación de dominio | `FieldOriginRecoveryPolicy.java` solo acepta tres IP LAN y exige debuggable | Transición explícita hacia dominio aprobado, sin habilitar origen arbitrario ni regeneración |
| Variante APK | `internalBeta` es DEBUG only; `preReleaseBuild` la rechaza. MainActivity permite mixed content cuando debuggable | Variante betaDomain no depurable, system PKI, sin cleartext/user CA, metadatos beta y nueva versionCode |
| Firma | build 10: CN=Android Debug | Decidir continuidad de firma A y nueva clave beta antes de distribuir; ver runbook |
| CI heredado | `.gitlab-ci.yml` y ambos deploy PS1 idénticos a Fortia | Sustituir/deshabilitar antes del primer push. No conectar runners corporativos al pipeline heredado |
| Dependencias | Laravel 11.47.0; soporte de seguridad de Laravel 11 finalizó 2026-03-12 | Actualizar a una rama soportada y validar dependencias. No se hizo upgrade en esta fase |
| Migraciones | Dos archivos usan Schema::connection('fortia_mock') | Separar/gatear migraciones legacy antes de instalación limpia; no proporcionar acceso Fortia para hacerlas pasar |
| Private storage | disk local: app/private, serve=true | Revisar y deshabilitar generación/servicio de URLs del registro privado en beta; soporte ya usa disk privado independiente |
| Cache/health | /up existe, readiness profundo no demostrado | Prueba de caches en instalación aislada; readiness privado y supervisión scheduler |

Laravel 11 ya está fuera de soporte a la fecha de esta revisión: [política oficial](https://laravel.com/framework/docs/11.x/releases). No se interpreta esto como autorización para actualizar automáticamente.

## Stack observado

| Componente | Evidencia local |
|---|---|
| PHP CLI / Composer | 8.4.15 / 2.9.2; composer.json exige PHP ^8.4 |
| Laravel / Sanctum / Inertia PHP | lock: 11.47.0 / 4.2.1 / 2.0.14 |
| Web Vue / Inertia Vue / Vite | lock: 3.5.25 / 2.2.21 / 6.4.1; Tailwind 3 |
| Mobile Vue / Ionic / Vite | lock: 3.5.42 / 9.0.2 / 8.2.2 |
| Capacitor | 7.6.9; SecureStorage 7.1.6, SQLite 7.0.3 |
| Node / npm | CLI 20.20.2 / npm 11.12.1 (consulta de versión con permiso de lectura tras EPERM del sandbox) |
| Java / SDK | JBR 21.0.10; minSdk 23, compile/target 35; Gradle wrapper 8.11.1 |
| DB | MySQL local `vending_attendance_dev`; no se requirió consultar versión del daemon para este diseño |
| Migraciones | 105 archivos, inventario adjunto `migration-inventory.txt` |

Se propone Node 24 LTS para CI, con compatibilidad de ambos lockfiles por verificar, no sustituirlos automáticamente. [Calendario oficial Node](https://github.com/nodejs/Release).

## Dimensionamiento propuesto, no benchmark

| Nivel | CPU/RAM | Disco | Alcance |
|---|---|---|---|
| BETA MINIMUM | 2 vCPU / 4 GiB | 60 GiB SSD, ext4, swap 2 GiB | Hasta 10 testers registrados actualmente; build fuera del servidor, monitorizar memoria |
| BETA RECOMMENDED | 4 vCPU / 8 GiB | 100 GiB SSD, swap 2 GiB | Más margen PHP/DB/evidencias y restores; no HA |
| FUTURE PRODUCTION GUIDANCE | Dimensionar con prueba de carga y retención | DB y evidencias separables | 1000 equipos requieren otra evaluación, no multiplicar ciegamente recursos |

Con heartbeat de 60 s, 1000 terminales son aproximadamente 16.7 solicitudes/s sostenidas solo de heartbeat; faltan login, manifests, picos de reconexión, firmas y fotos. El registro beta admite máximo 10 entradas: no es un registro productivo de 1000 usuarios. Medir p95, errores, conexiones DB y disco; luego separar DB, almacenamiento de objetos privado, cache compartida y nodos web según cuello real. No Kubernetes para esta beta.

Debian 13 incluye PHP 8.4 y evita introducir un repositorio PHP adicional: [Debian](https://www.debian.org/News/2025/20250809). Ubuntu 24.04/26.04 LTS son alternativas corporativas soportadas, pero habría que validar su PHP y extensiones contra el lock; no se eligen solo por antigüedad. [Ubuntu](https://ubuntu.com/project/docs/release-team/list-of-releases/). Para MySQL usar repositorio oficial compatible, verificando paquete/arquitectura en instalación, no sustituir por MariaDB sin pruebas: [MySQL APT](https://dev.mysql.com/doc/refman/8.4/en/linux-installation-apt-repo.html).

Nginx + FPM simplifica document root público y releases Linux. Apache + FPM sería válido si operaciones lo exige; Fortia demuestra runner Windows y scripts PowerShell, no identifica concluyentemente IIS/Apache/Nginx del servidor. No hay evidencia que obligue a replicar Windows.

## DB y servicios

| Opción DB | Seguridad/operación | Coste/latencia/escalado | Decisión |
|---|---|---|---|
| Mismo servidor | Bind loopback, backup externo; mismo dominio de fallo | Menor coste, latencia baja, escalar limitado | Beta inicial propuesta |
| Servidor separado | Red privada/TLS, respaldos independientes, más administración | Mayor coste, escalado separado | Siguiente paso según métricas |
| Administrada | Backups/PITR según proveedor, permisos mínimos | Coste recurrente, menos operación; revisar región/latencia | Preferible si TI ya dispone del servicio |

No se detectaron Jobs/ShouldQueue ni dispatch de trabajos de aplicación en app/routes. Composer dev arranca queue:listen y config default es database, pero eso no prueba demanda de workers. Propuesta QUEUE_CONNECTION=sync, sin worker, hasta introducir un job real; Redis NOT_REQUIRED. Sesiones y cache database para un nodo.

Scheduler real en routes/console.php: employees:prune-imports hourly; audit:cleanup --optimize diario 03:00 configurable; device-nonces:prune cada minuto; sybi:sync-vending condicional (mantener deshabilitado). Activarlo solo tras validar retención/impacto de OPTIMIZE y cron bajo usuario dedicado. No crear heartbeat ficticio. Websockets/push no implementados; notificaciones internas. Mail default log: no habilitar recuperación por correo con tokens en logs; SMTP privado aprobado antes de ofrecerla.

Servicios legacy Fortia/lookup/SYBI y credenciales existen como seams de configuración, no son requisito para esta beta. Sin credenciales ni conectividad hacia producción. Phase 14/biometría NOT_IMPLEMENTED, diferida.

## Modelo de configuración

| Variable/capa | LOCAL | INTERNAL BETA objetivo | REAL PILOT / PRODUCTION |
|---|---|---|---|
| APP_ENV / DEBUG | local / true | beta / false, BLOQUEADO hasta adaptar gates | production / false, simulación prohibida |
| APP_URL | LAN | https://dominio aprobado, raíz | Dominio separado |
| ASSET_URL | vacío/local | vacío, mismo host | CDN solo si aprobado |
| VITE_API_BASE_URL | terminal LAN | HTTPS mismo host | HTTPS entorno correspondiente |
| VITE_FIELD_IDENTITY_BASE_URL | HTTPS LAN | mismo HTTPS beta, explícito | Igual origen aprobado |
| VITE_DEPLOYMENT_MODE | development | beta propuesto, actualmente no soportado | pilot/production existentes |
| SESSION_DOMAIN | local | null: host-only | host-only salvo necesidad validada |
| SANCTUM_STATEFUL_DOMAINS | local | solo host web sin esquema | hosts exactos |
| CORS | localhost de Capacitor/dev | https://localhost, sin wildcard | exacto por consumidor |
| URLs storage | locales | descargas autorizadas, sin private URL pública | misma política |

Plantillas `.env.beta.example` y `mobile/.env.beta.example` son diseño no desplegable; no reemplazan .env real. CORS actual solo cubre api/v1/device/* y no usa credenciales. Identidad usa CapacitorHttp nativo y Bearer origin-bound; no ampliar CORS global ni compartir cookies de web con identidad. Web usa Secure+HttpOnly+SameSite=Lax. Trusted hosts/proxies no configurados explícitamente en bootstrap: completar allowlist exacta, solo confiar proxy conocido si se incorpora. No se identificó necesidad de callbacks/webhooks nuevos para beta; bloquear rutas legacy externas no necesarias.

## Inputs para Phase 13.10

REQUIRED_INPUT: host/IP y puerto SSH del servidor nuevo; SO/version y recursos reales; usuario bootstrap con acceso autorizado por canal privado; dominio aprobado y responsable DNS; URL de repositorio GitLab nuevo y disponibilidad de runner Linux protegido; aprobación DB local propuesta o datos del servicio separado; custodio y decisión de firma APK; selección autorizada de datos/personas que saldrán de la laptop; destino/custodio de backups y política de retención.

Propuestas que no necesitan inventarse como definitivas: usuario deploy, ruta /var/www/vending-attendance, DB vending_attendance_beta, host vending-beta.<dominio-corporativo>. Confirmar restricciones corporativas, no pedir contraseñas en chat. Antes de deploy cerrar los gates de código anteriores. FINAL: WAITING_FOR_SERVER_AND_DOMAIN_INPUT; los inputs no eliminan los bloqueos técnicos.

## Trazabilidad de gates

0,32–38: `gitlab-deployment-discovery.md`, `gitlab-vending-plan.md`. 1–4,15: `branding-beta-baseline.md`. 5–14,18–20,35,45: este documento e instalación. 16,39–43: `domain-transition-runbook.md`. 17,21–31: instalación. 44: los seis documentos presentes. 46: esta fase no ejecutó SQL ni alteró ADB/identidad; baseline de Phase 13.8E preservado por ausencia de escrituras (no nueva certificación de conteos en vivo).
