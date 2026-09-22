# Phase 13.9A — implementación y límites

Actualización 13.9A.1: el fixture OnPrem quedó corregido, full suite 807/807 PASS y checkpoint READY. Las referencias siguientes al fallo de OnPrem describen el gate histórico; ver [cierre vigente](phase13.9A.1-result.md). El checkpoint propuesto ahora es `internal-beta/1.0.1-beta.2-server-ready`, separado de la política de deploy actual.

El inventario previo está en phase13.9A-blockers.md. Resultado y pruebas finales en phase13.9A-result.md. Esta fase no despliega ni cambia DNS, GitLab remoto, datos de negocio, teléfono o claves. Build 10 conserva su APK y firma originales. La fuente prepara build 11; no se generó una nueva APK.

## Laravel

La auditoría npm web posterior detectó 15 avisos, incluyendo 9 altos y 2 críticos. Se corrigieron mediante `npm audit fix --ignore-scripts`, sin `--force` ni cambio de restricciones en package.json: 29 paquetes actualizados y 4 dependencias añadidas. Resultado final web: cero vulnerabilidades; móvil: cero. El pipeline exige también npm audit. La web beta usa `npm run build -- --mode beta`, API relativa y raíz `/`; no incorpora URLs de la `.env` local. Los bundles beta resultantes no contienen IPs LAN.

11.47.0 → 12.69.2, PHP 8.4.15. Rama 12 elegida por menor superficie respecto a 13 y compatibilidad con Sanctum 4, Inertia 2 y los paquetes existentes. Seguridad hasta 2027-02-24; ya está en mantenimiento de seguridad: planificar siguiente major antes de esa fecha. No implica soporte indefinido. Fuentes: [política oficial](https://laravel.com/framework/docs/12.x/releases) y [upgrade 12](https://laravel.com/framework/docs/12.x/upgrade).

Laravel Excel 3.1.56 bloqueaba Laravel 12; actualizado a 3.1.70 dentro de la misma rama. Se conservaron restricciones Sanctum/Inertia. Composer actualizó lo necesario con --minimal-changes y --no-scripts. Después se corrigieron avisos de PHPUnit, PsySH y Symfony YAML con sus dependencias mínimas; composer audit final sin avisos. No se ejecutaron scripts Composer contra la app real.

Cambios de compatibilidad revisados: Carbon 3, UUIDv7 por defecto, inyección de parámetros con valor predeterminado, nombres de esquema, validación de SVG y precedencia de rutas. No hubo cambio funcional deliberado en identidad, asistencia o soporte. El cambio concreto de integración fue DatabaseManager: Laravel 12 resuelve el nombre por defecto antes de parseConnectionName. SafeDatabaseManager ahora valida esa misma conexión efectiva antes de abrir PDO. SafeConnectionFactory y guardas de comandos destructivos siguen activas. Tests aíslan config, rutas y eventos; nunca cargan la caché de rutas local. OpenSSL de PHP requiere OPENSSL_CONF correcto en esta consola Windows; se usó el archivo de la instalación PHP 8.4, sin generar claves de dispositivos reales.

## Deployment independiente

.gitlab-ci.yml sustituye el pipeline heredado. scripts/deploy_qa.ps1 y deploy_production.ps1 fallan inmediatamente y no contienen destinos anteriores. Se preservan integraciones de negocio Fortia/SYBI, comandos de diagnóstico y documentación histórica.

Clasificación de referencias: BUSINESS_INTEGRATION = app/config Fortia, lookup/SYBI y catálogos propios; DEPLOYMENT_LEAK = antiguo YAML/PowerShell, eliminado; TEST_FIXTURE = direcciones reservadas/LAN en tests; DOCUMENTATION_REFERENCE = descubrimiento previo e incidentes. No hay host Fortia en pipeline ni scripts de despliegue. LOCAL_DEBUG = localhost/loopback o host explícito de desarrollo. Runtime beta no contiene IP LAN fija.

Pipeline: validate → test → build → package → deploy_beta → healthcheck. Runner Linux dedicado vending-linux-beta. Requiere PHP 8.4/extensiones Composer, SQLite, Node 24/npm, Git, tar, Bash, SSH, curl y Composer. Las pruebas nativas se verificaron localmente; incorporar runner Android cuando exista. El test job no permite fallos ni omite OnPrem: si la deuda histórica persiste, el pipeline completo queda rojo y bloquea deploy. No se presenta esa plantilla como pipeline remoto verde.

Deploy manual únicamente en environment beta, tag protegido vending-beta-X.Y.Z; serialización resource_group y flock remoto. Variables sin valores reales: BETA_SSH_HOST, BETA_SSH_PORT, BETA_SSH_USER, BETA_SSH_PRIVATE_KEY (FILE), BETA_SSH_KNOWN_HOSTS (FILE), BETA_DEPLOY_PATH, BETA_APP_URL. Host keys se verifican por canal independiente; nunca StrictHostKeyChecking=no. Paquete por allowlist, sin .env, storage, SQLite/SQL, claves, node_modules, cachés PHP ni assets Android locales.

## Layout efectivo del script Linux

La raíz permitida es /srv/vending-beta o un único sufijo aprobado. No usar el layout propuesto anteriormente /var/www sin adaptar/revisar el guard.

```
/srv/vending-beta/
  current -> releases/<sha>
  releases/<sha>/
    .env -> shared/.env
    storage/app/private -> shared/private
    storage/app/support-private -> shared/support-private
    storage/app/public -> shared/public
    storage/logs -> shared/logs
    storage/framework/{cache/data,sessions,views}  # por release
    bootstrap/cache/                             # por release
  shared/
    .env
    private/beta-onboarding/testers.json
    support-private/
    public/
    logs/
    hooks/backup-db
```

Registry regular 0640, directorios 0750, propietario deploy y grupo dedicado de lectura web; el archivo no debe ser editable por web. Evidencia y logs sí requieren escritura web. Mantener permisos en host antes de ejecutar. No compartir framework/cache ni bootstrap/cache entre releases. Sesiones/cache son database en plantilla; no dependen de archivos descartados.

Script futuro: release nuevo → symlinks shared → composer install --no-dev --no-scripts → check-platform-reqs → assert-beta.php → frontend manifest → backup hook obligatorio → package:discover → migrate --force → optimize → switch current atómico → /up y /ready. Nginx debe usar realpath para SCRIPT_FILENAME y raíz current/public; revisar FPM/opcache antes del primer despliegue. Ningún paso fue ejecutado remotamente.

Contrato backup-db: helper privado propiedad operador, ejecutable, sin credenciales en argumentos; recibe commit SHA. Debe respaldar DB remota consistente y evidencia según ventana, verificar restaurabilidad/integridad y registrar path/timestamp/bytes/SHA256 fuera de Git. Salida 0 únicamente si todo fue exitoso; cualquier otro estado detiene migración. El hook no se proporciona con un éxito ficticio. Sin helper no despliega. Respaldo local de laptop no satisface este contrato.

Healthcheck exige HTTP 200 por HTTPS sin redirecciones ni TLS bypass en /up y /ready. Si falla después del switch, job FAIL y current queda para intervención; no se ejecuta rollback DB. Retorno de symlink sólo tras revisar compatibilidad de migraciones. Las releases existentes no se sobrescriben ni se borran automáticamente.

## Beta, privacidad y red

InternalBeta exige APP_ENV=beta + INTERNAL_BETA_ENABLED=true + APP_DEBUG=false. Se conserva local/testing para DEMO y fixtures existentes; production + LOCAL_SIMULATED queda deshabilitado, aun con flag true. phoneVerified sigue false: no hay proveedor SMS productivo. No se generó OTP real en esta fase.

BETA_TESTER_REGISTRY_PATH apunta al archivo privado compartido. Lector fail-closed ante ausencia, JSON inválido, tamaño >32KiB, estructura/duplicados inválidos, archivo symlink, ruta pública o permisos world en POSIX. No lee archivo real en testing. Policy exige asociación exacta user/employee, source explícito MANUAL/DEMO en beta, habilitación y expiración. DEMO A necesita entrada propia aprobada para el servidor; no se copia silenciosamente el teléfono local. No se alteraron usuarios/registry reales. El precheck de deploy registra sólo SHA256 del registry; cambios operativos deben llevar approval_reference y timestamps, backup privado y registro de operador, nunca contenido sensible en logs.

Ambos disks privados tienen serve=false. Descargas siguen por controllers con permisos/contexto y validación de rutas; tests de SupportEvidence/FieldIdentity pasan. public/storage sólo puede enlazar app/public.

/ready es público con salida genérica y Cache-Control no-store: SELECT 1 y escritura/lectura/borrado de archivo aleatorio en cada disk privado. 503 ante error, sin rutas/credenciales/stack/versiones. /up sigue liveness. No crea asistencia, actividad, sesión ni heartbeat. No hay trabajos de cola funcionales que requieran worker; QUEUE_CONNECTION=sync.

BetaHttpBoundary limita host a BETA_APP_URL, HTTPS y proxies explícitos. Sólo confía X-Forwarded-For/Proto/Port desde BETA_TRUSTED_PROXIES; no X-Forwarded-Host ni wildcard. CORS lista explícita BETA_ALLOWED_ORIGIN (https://localhost es el origen WebView, no servidor). Sanctum usa SANCTUM_STATEFUL_DOMAINS configurable sin fallback LAN en beta. SESSION_SECURE_COOKIE=true, SESSION_DOMAIN configurable; no secreto en VITE_*.

## Scheduler

| Trabajo | Beta | Motivo |
|---|---|---|
| device-nonces:prune cada minuto | ENABLE_BETA | Limpieza de nonces vencidos de autenticación; sin datos de negocio |
| employees:prune-imports hourly | DISABLE_BETA por defecto | Retención de staging; requiere revisión antes de BETA_CLEANUP_ENABLED |
| audit:cleanup --optimize diario | DISABLE_BETA por defecto | Retención/bloqueos SQL; revisar antes de activarlo |
| sybi:sync-vending | DISABLE_BETA | No sincronizar integración externa durante beta |
| Support/notificaciones | Sin job programado nuevo | No se añadió actividad/telemetría artificial |

No cron remoto activado. Comandos manuales de administración no equivalen a tareas programadas.

## Android y firma

Build types debug / beta / release. Beta no depurable, sólo system CAs y HTTPS, sin user CA ni cleartext. Debug genera XML según LOCAL_DEBUG_CA_HOST y limita user CA exactamente a ese host, sin editar IP en source. Release conserva gate de firma/HTTPS. BETA_API_BASE_URL y BETA_FIELD_IDENTITY_BASE_URL se validan al compilar web; Gradle exige las mismas variables y deployment.json coincidente después de cap sync. Rechaza bundle viejo de LAN. Los dominios example.test de pruebas son sintéticos; no son el dominio elegido ni un candidato de distribución.

Recuperación: FIELD_RECOVERY_PREVIOUS_ORIGIN_SHA256 contiene hashes SHA256 de orígenes anteriores exactos, separados por coma. Target beta = BETA_FIELD_IDENTITY_BASE_URL; debug = LOCAL_FIELD_IDENTITY_BASE_URL. Release deja autorización de transición vacía. La autorización nativa sólo permite inspeccionar/usar clave existente; el flujo conserva challenge backend y comparación de identidad. Ausencia/target distinto/origen malformado falla cerrado. No nuevo enrolamiento, UUID, OTP o keypair automático.

Fuente nueva: 1.0.1-beta.1/build11, sin APK generada/instalada. Build10 histórica: 30,783,135 bytes; SHA256 a58c8f5ec103f0dcf222e665fee12dd4e9041f7d57e729b75cd89ea3dbe501a8; certificado 2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec, CN=Android Debug,O=Android,C=US.

Recomendación A: transición temporal del HONOR con el mismo certificado de firma APK, aunque la variante beta sea no depurable y TLS estricto. Requiere decisión explícita para el siguiente artefacto (-PbetaUseExistingDebugSigning=true) y comprobar fingerprint antes de adb install -r. No distribuir públicamente esta clave debug. B: nueva clave beta con uninstall/re-enrolamiento pierde continuidad y no está autorizada. C: rotación con signing lineage/compatibilidad Android sólo tras estudio específico y prueba; no asumir que una clave distinta actualiza la instalación. Para distribución futura/producción usar clave gestionada con backup y política aprobada. [Documentación Android](https://developer.android.com/studio/publish/app-signing).

Clave de firma APK identifica al publicador y condiciona update-in-place; clave ECDSA FieldIdentity reside en Android Keystore y prueba identidad del dispositivo. Son claves diferentes. Ninguna fue generada/reemplazada aquí. Segundo Android sigue diferido.
