# Discovery GitLab — Fortia y Vending

2026-09-14. Lectura de archivos locales, Git y hashes; sin conexión al GitLab remoto, SSH ni servidores. No se ejecutaron scripts de deploy. `asistencias_fortia` permaneció sin cambios.

## Estado observado

Fortia: rama dev, HEAD `0329291908caf77df3aedc2f4365acf2d321551c`, tag `split-enterprise-v1`, working tree limpio. Remote origin HTTP corporativo `http://200.66.78.170:58001/crm/biometrico-medicallife.git`; sin credenciales incorporadas observadas. No usar HTTP sin protección para futuros secretos de CI; solicitar URL GitLab HTTPS/SSH segura. No se inspeccionaron variables remotas, permisos efectivos del runner ni su servicio: no pueden inferirse del YAML.

Vending: rama `phase/14-biometric-engine-selection`, HEAD `ea5852d151151014214e660abfd3032414997d44`; último checkpoint `internal-beta/1.0.1-beta.1-build.5` apunta al mismo commit. `git remote -v` vacío: repositorio GitLab/remote destino REQUIRED. Tags previos incluyen vending-phase-12-ux-pass, vending-phase-13-5-geofence-pass y vending-sybi-projection-pass.

Post-checkpoint: origin recovery/LAN, multi-tester policy y RBAC/registro privado B, aislamiento, branding web/APK10, documentos 13.8A/B/C/E; documentos Phase14 sin consolidar. Cambios/untracked legítimos preservados. Provisioning B es delta de datos, no se reproduce publicando código. Nuevo checkpoint YES, separando selección de archivos beta y Phase14 diferida. No commit/tag en esta fase.

## Pipeline real

`.gitlab-ci.yml`: solo stage deploy. Sin includes, test/build/package separados, artifacts, needs, manual jobs, resource_group ni healthcheck. QA: rama qa, runner tag qa-windows, script deploy_qa.ps1, environment qa (`https://desarrollo.sybiml3.com/biometrico`). Producción: main, tags windows/prod/biometrico, deploy_production.ps1, environment production (`https://biometrico.sybiml.com`). Jobs automáticos por rama, no estrategia de tags para desplegar.

Scripts locales Windows usan CI_PROJECT_DIR y robocopy /MIR, no SSH/scp/rsync. QA destino D:\biometrico, backup D:\backups\biometrico; producción D:\biometrico\biometrico, backup D:\backups\biometrico_production. PHP producción D:\PHP\php-8.4\php.exe, Composer C:\Composer\composer.phar. Ambos composer install --no-dev --optimize-autoloader y npm ci/build con fallback npm install. Ambos migrate --force. QA limpia config/cache/route/view y cachea config; producción optimize:clear y cache config/route/view.

Backup de archivos timestamped excluye vendor, node_modules, logs/cache; no backup DB ni restore test. Deploy excluye .env/logs y algunos directorios, pero no preserva explícitamente TODO storage privado frente a /MIR. No release symlink, rollback implementado ni healthcheck. `$ErrorActionPreference=Stop` no garantiza fallo ante exit no-cero de todos los ejecutables nativos en Windows PowerShell; solo robocopy tiene verificación explícita >7. No se concluye que el despliegue actualmente falle: se identifican límites del script.

Variable referenciada explícitamente: CI_PROJECT_DIR. PATH/entorno Windows implícitos. No hay credenciales SSH en estos archivos. Las versiones efectivas de runtime/runner remoto, web server, DB, firewall, cron y variables GitLab son UNKNOWN, no se inventan.

## Riesgo en Vending

Los tres archivos son byte-idénticos a Fortia, hashes SHA256:

| Archivo | Hash |
|---|---|
| .gitlab-ci.yml | aa7f0d048fda0548634742937ccfcf7c4617ddca3ec35308ab591b68441fef97 |
| scripts/deploy_qa.ps1 | 9c3dfae52212b734b11e48b259ee71bd82432192889d5977f5a8e82b6076bab8 |
| scripts/deploy_production.ps1 | 9506a402ad085aeabb6c886649025a9f4048c3f711ec7c9aed2c7671a45306d2 |

NO reutilizar destinos, URLs ni runner tags. Antes del primer push, reemplazar el YAML heredado por pipeline Vending revisado y excluir los scripts heredados del artefacto. Hasta entonces deshabilitar CI/runners del repositorio nuevo por control de creación. No se ha modificado el pipeline activo local en esta fase de diseño.

## Matriz de adaptación

| Componente | Fortia | Vending | Clasificación |
|---|---|---|---|
| PHP | ^8.4, binario Windows | FPM8.4 Linux, parche soportado | ADAPT |
| Composer | install --no-dev optimizado | conservar flags, lock e imagen fijada | REUSE_AS_IS para flags; IMPROVE verificación |
| Node/assets | build en destino, fallback install | npm ci en CI, artefacto por SHA | IMPROVE |
| Migraciones | force sin backup DB | backup+gate schema, separar fortia_mock | IMPROVE |
| Storage | /MIR con exclusiones parciales | shared privado fuera de release | IMPROVE |
| Queue | no gestión visible | no jobs encontrados, sin worker inicial | NOT_APPLICABLE |
| Scheduler | no gestión visible | cron explícito y observable | ADAPT |
| Layout | copia in-place Windows | releases/current/shared Linux | ADAPT |
| Health | ninguno | liveness+readiness+postdeploy | IMPROVE |
| Rollback | backup archivos | pointer y compatibilidad DB explícita | IMPROVE |
| Variables | CI_PROJECT_DIR/paths fijos | variables protegidas scope beta | ADAPT |
| Server path/URL | destinos Fortia | ruta/host nuevos confirmados | NOT_APPLICABLE a destinos originales |
| SSH | no hay | clave deploy y known_hosts verificado | ADAPT |
| Manual gate | no | deploy beta manual obligatorio | IMPROVE |

Fortia discovery PASS dentro del alcance de archivos locales. No es auditoría de infraestructura remota ni aprobación del pipeline de producción.
