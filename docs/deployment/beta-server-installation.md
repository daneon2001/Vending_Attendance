> Actualización Phase 13.9A: este documento conserva el descubrimiento/propuesta de 13.9. Para implementación, rutas, guards y resultado vigentes, usar [phase13.9A-implementation.md](phase13.9A-implementation.md) y [phase13.9A-result.md](phase13.9A-result.md). No ejecutar instrucciones históricas sin esos gates.

# Instalación beta — procedimiento propuesto, NO ejecutado

Depende de los gates de `beta-server-readiness.md`. No ejecutar esta guía contra la laptop, Fortia ni producción. Dominio, host, usuarios y ruta son propuestas hasta aprobación.

## Host y layout

Debian 13 amd64, Nginx, PHP-FPM/CLI 8.4 con versiones de parche soportadas; Composer 2 en CI. Extensiones iniciales: curl, mbstring, xml/DOM, zip, mysql/PDO, gd, intl, bcmath, opcache; verificar `composer check-platform-reqs --no-dev` contra el artefacto. CI añade SQLite para tests aislados. Node 24 LTS/npm fijado en imagen CI, Java 21 y SDK35 solo en runner Android futuro; no SDK en servidor web.

Ext4, filesystem con espacio libre >=20%, UTC en SO/DB, NTP supervisado (firmas sensibles a reloj); presentación America/Mexico_City. Actualizaciones automáticas de seguridad y reinicio planificado. Swap 2 GiB como margen, no sustituto de RAM. Backups fuera del disco/host. Monitorizar conexiones PHP/DB antes de ampliar pools; propuesta inicial FPM dynamic max_children=8 con 4 GiB o 16 con 8 GiB, ajustar por RSS medido y reservar RAM para MySQL/OS.

```text
/var/www/vending-attendance/
  releases/<commit-sha>/       # código/ vendor/ public/build del artefacto
  current -> releases/<sha>   # solo cambia tras validación
  shared/
    .env                     # secreto
    storage/
      app/private/beta-onboarding/testers.json
      app/support-private/   # imágenes y miniaturas de soporte
      framework/             # sesiones/cache si se usan archivos
      logs/
```

Usuario SSH `deploy`, sin root interactivo en pipeline; usuario FPM `vending-beta`, grupo dedicado. Código propiedad deploy, lectura FPM; escritura FPM solo en storage y bootstrap/cache de cada release. Directorios privados 0750, archivos 0640 con grupo mínimo; sin 0777. La ruta testers.json debe ser archivo regular (el lector rechaza symlink del archivo); se puede enlazar el directorio storage completo. Dar lectura al registry, no edición desde web. Instalar .env fuera del checkout, permisos mínimos. No copiar storage local completo al shared.

sudo limitado a un helper root-owned con argumentos validados para recargar FPM/Nginx y tareas operativas aprobadas; nunca sudo shell arbitraria, rsync root o chmod global. SSH con clave dedicada, host key confirmada por canal independiente, sin password login tras probar acceso alternativo.

## Firewall y HTTPS

22 (o puerto SSH aprobado): solo IP/VPN de administración y runner de deploy; 80: ACME y redirección; 443: web/API beta. 3306 solo loopback/red privada autorizada, nunca Internet. No 8443 público. Deshabilitar puertos sobrantes. No poner la app detrás de Basic Auth global sin diseñar compatibilidad Android Bearer; aplicar autenticación propia, rate limits y restricciones de ingreso que no rompan testers móviles.

Un host: `vending-beta.<dominio-corporativo>` PROPOSED. Alternativas `mlone-beta.<dominio-corporativo>` o `vending.<subdominio-beta>`. DNS A al servidor; AAAA solo si IPv6 funciona y tiene firewall. ACME/Let's Encrypt con HTTP-01 sobre puerto80; DNS-01 si corporación exige ingress distinto. Certificado corporativo aceptable solo con cadena públicamente confiable por Android. TLS1.2/1.3, fullchain, renovación automatizada con recarga y alerta de expiración. [Let's Encrypt](https://letsencrypt.org/docs/challenge-types/).

Diseño Nginx: document root `current/public`, `try_files $uri $uri/ /index.php?$query_string`, ejecución PHP únicamente front controller existente vía socket FPM, SCRIPT_FILENAME con realpath del release. Denegar dotfiles salvo ACME, directorios privados, archivos de configuración/backups y scripts PHP subidos. HTTP redirige a HTTPS salvo challenge. HTTPS host desconocido rechazado. Sin alias a storage/app/private ni app/support-private. public/storage, si realmente hace falta, enlaza exclusivamente app/public. Upload límite inicial 8 MiB, PHP upload_max_filesize 5M/post_max_size 8M, application evidence 5MiB, max_count5; contrastar transporte multipart/base64 antes de fijarlo. No elevar límites arbitrariamente.

## Instalación y despliegue, orden con gates

1. Verificar host nuevo, inventario, DNS/TLS autorizados y accesos. Completar cambios beta-only, migrations legacy y rama Laravel soportada en checkout aislado antes de exponer app.
2. Crear layout, usuarios, FPM, MySQL y permisos. DB runtime limitada a su schema; cuenta temporal de migración separada con DDL solo allí. No root DB ni credenciales Fortia. Nginx solo public. Pruebas de rutas prohibidas.
3. Preparar artefacto CI inmutable identificado por commit y SHA256: Composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader, npm ci y npm run build en builder aislado. No composer update/npm install flexible en servidor.
4. Copiar a release nuevo; verificar hash y manifiesto, enlazar shared/.env y storage, crear bootstrap/cache escribible. `composer check-platform-reqs --no-dev`. No scripts sobre current aún.
5. Backup DB + archivos consistentes y restore ensayado antes de migración. Si cambio incompatible, ventana de mantenimiento (`artisan down`) y pausar ingresos/cron antes del snapshot. Registrar backup, tamaño, timestamp, SHA256 fuera de Git.
6. Inventariar `migrate:status`, revisar SQL/impacto en copia aislada. Solo tras resolver migraciones externas ejecutar `migrate --force` con credencial acotada. NO migrate:fresh, wipe, seeders generales ni migration rollback automática. Guardas de tests no restringen migrate ordinario, pero sí rollback/reset/fresh fuera de DB de testing autorizada.
7. Config/route/view cache sobre release candidato con configuración beta; no cachear rutas/config locales. Verificar cierres/rutas usando el framework objetivo; aún no probado en esta fase. La documentación oficial exige public como raíz y optimización en despliegue: [Laravel deployment](https://laravel.com/framework/docs/11.x/deployment).
8. Healthcheck candidato mediante socket/host local privado. Cambiar current atómicamente, recargar FPM para opcache coherente, `artisan up` si se activó mantenimiento, readiness. Workers solo si se introducen jobs reales; actualmente no se prevén.
9. Validar logins/HTTPS y recuperación autorizada, baseline e imágenes; habilitar cron tras revisar retención. Conservar release anterior. Deploy GitLab manual y serializado; abortar al primer código de error, sin activar release incompleto.

## Migrations y scheduler

Inventario de 105 archivos en migration-inventory.txt. En particular `2025_12_13_000000_create_fortia_employees_table.php` y `2026_03_17_130000_add_can_check_all_branches_to_employees_tables.php` usan conexión fortia_mock. No intentar compensarlo con permisos sobre otra DB. Separar migraciones legacy de Vending, o gated no-op revisado para beta, conservando compatibilidad; comprobar instalación desde cero y actualización del snapshot en DB efímera sin red a DB real.

Scheduler propuesto: cada minuto, bajo vending-beta, `cd /var/www/vending-attendance/current && /usr/bin/php artisan schedule:run`, con flock externo, salida a log rotado y señal observable de ejecución exitosa. No ocultar toda salida con /dev/null. `withoutOverlapping` necesita cache funcional. Revisar audit cleanup/OPTIMIZE, retención y privilegios en MySQL; ejecutar fuera de horas de prueba. SYBI sync=false. No cron Fortia, no worker database sin jobs. Si luego se necesita queue, unidad systemd dedicada con restart y límites, queue:work con timeout menor que retry_after; no agregarla ahora.

## Health y observabilidad

Liveness actual `/up`: demuestra boot de Laravel, no disponibilidad de DB/storage. Readiness propuesto privado o protegido: SELECT1 de DB, acceso al disk privado, prueba de escritura/borrado de un archivo efímero solo en health scratch (autorización de instalación), cache, edad de última ejecución scheduler; queue solo si activa. Devolver estado genérico y versión, no host DB, rutas, secretos ni excepciones. Respuesta pública503 durante mantenimiento. Readiness no debe escribir Attendance, Activities ni heartbeats.

Monitorizar HTTP5xx/latencia, PHP slowlog sin payload, Laravel daily logs, disco/inodos, CPU/RAM, MySQL conexiones/errores, backups, cron y TLS (<30 días alerta). Redactar Authorization/cookies/password/OTP/phones; no registrar cuerpos de login ni URL con tokens. Rotación inicial14 días logs, acceso restringido. API Control Center corporativo: interfaz futura de health/versión/alertas sin credenciales de usuario ni DB directa; no integración en esta fase.

## Backups, privacidad y rollback

Propuesta beta: snapshot nocturno DB consistente + archivos privados, cifrado externo, 7 diarios/4 semanales; RPO24h y RTO4h objetivos a demostrar. Snapshot adicional antes de migración/importación. Restore mensual y antes del primer despliegue en DB/directorio aislados; comparar filas, IDs, fingerprints, relaciones y hashes de evidencias, no basta import exit0. Retención evidencias durante beta y hasta30 días después de cierre como propuesta sujeta a aprobación del custodio; no activar borrado automático sin ella.

APP_KEY y secretos servidor en almacén privado separado; incluir versiones necesarias para descifrar datos migrados, no en artifact. Signing APK separado de backup DB y de Android Keystore no exportable del teléfono. Claves de backup fuera del servidor respaldado. El registry server-side simple es opción inicial: archivo privado auditable (metadata sin teléfonos), edición por operador, máximo10, expiración vigente. Tabla cifrada añadiría UI/migrations/rotación; secret manager útil si corporación ya lo opera. No migrar registro a tabla ahora.

Rollback código: verificar compatibilidad DB, repuntar current al release anterior junto con su configuración versionada compatible, recargar FPM y verificar health. Assets están en cada release. No regresar secretos revocados. DB no vuelve atrás al cambiar symlink: migrations destructivas requieren restore consistente DB+archivos con pérdida de cambios posteriores aceptada, ventana y aprobación específicas. Con schema expand/contract preferir corrección hacia adelante. No prometer rollback transparente de DB.
