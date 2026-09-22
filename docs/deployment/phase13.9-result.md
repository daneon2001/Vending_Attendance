# Resultado Phase 13.9

2026-09-14. Diseño/discovery completados; readiness de ejecución PARTIAL. No ejecutar las plantillas hasta cerrar los gates descritos en beta-server-readiness.md. No se cambió código runtime, APK, bases ni infraestructura.

```text
PHASE 13.9: PARTIAL
BRANDING BASELINE: MEDICAL_LIFE_ONE_APPROVED
BUILD 10: PHYSICALLY_VALIDATED (Phase 13.8E)
SERVER ARCHITECTURE: READY (diseño; no desplegable aún)
SERVER OS: Debian 13 amd64, PROPOSED
SERVER MINIMUM: 2 vCPU / 4 GiB / 60 GiB SSD
SERVER RECOMMENDED: 4 vCPU / 8 GiB / 100 GiB SSD
WEB SERVER: Nginx + PHP-FPM
PHP: 8.4; parche soportado al instalar; local 8.4.15
NODE: 24 LTS propuesto para CI; local 20.20.2
DATABASE STRATEGY: MySQL 8.4 LTS loopback, mismo servidor beta, PROPOSED
REDIS: NOT_REQUIRED
QUEUE: sync propuesto; sin jobs/worker de aplicación detectados
SCHEDULER: REQUIRED, cron por minuto tras revisar tareas de retención
DOMAIN: REQUIRES_INPUT
PUBLIC HTTPS: READY_TO_CONFIGURE por diseño; no emitir aún
LOCAL CA AFTER MIGRATION: NOT_REQUIRED por diseño
ANDROID DOMAIN BUILD: REQUIRES_POLICY_AND_VARIANT_CHANGES
NEXT APK: REBUILD_AFTER_DOMAIN y gates firma/policy
APK BRANDING: MEDICAL LIFE ONE
DISPENSER ASSET: REAL MEDICAL LIFE
GENERIC VENDING: NONE
APP CONFIG MODEL: READY (plantillas de diseño, runtime beta pendiente)
PRIVATE STORAGE: READY por diseño; serve=true del disk local requiere revisión
BACKUP STRATEGY: DB+archivos privados cifrados off-host; 7 diarios/4 semanales; restore mensual
FIREWALL: SSH restringido, 80 ACME/redirect, 443 beta, 3306/8443 no públicos
DEPLOY USER: deploy, no root, PROPOSED
DEPLOY PATH: /var/www/vending-attendance/{current,releases,shared}, PROPOSED
HEALTHCHECK: /up existente; readiness privado DB/storage/cache/scheduler pendiente
ROLLBACK: pointer código/assets/config compatible; DB restore separado y autorizado
BETA TESTER REGISTRY SERVER: archivo privado regular, beta-only pendiente
OTP: LOCAL_SIMULATED (solo INTERNAL BETA)
PHONE VERIFIED: false
BIOMETRY: NOT_IMPLEMENTED

ASISTENCIAS_FORTIA DISCOVERY: PASS (local, solo lectura)
CURRENT PIPELINE: deploy QA/main automático; scripts Windows heredados
DEPLOY METHOD: Fortia robocopy /MIR local; Vending propuesto SSH a release nuevo
REUSABLE COMPONENTS: flags Composer/lock, concepto environment y respaldo previo
ADAPTATIONS: Linux, manual gate, CI aislado, shared privado, DB backup, health, rollback
VENDING GITLAB REMOTE: REQUIRED
PROPOSED PIPELINE: validate -> test -> build -> package -> deploy_beta -> healthcheck
DEPLOY_BETA: MANUAL
GITLAB VARIABLES: plan FILE/protected/scope beta, masked cuando compatible; ninguna creada
NEW SERVER INPUTS: host/SSH/SO/recursos, dominio/DNS, repo/runner, DB, firma, selección de datos/backups

CURRENT CHECKPOINT: internal-beta/1.0.1-beta.1-build.5
CURRENT HEAD: ea5852d151151014214e660abfd3032414997d44
POST-CHECKPOINT CHANGES: origin recovery, multi-tester, aislamiento, branding10, docs13.8, Phase14 diferida
NEW CHECKPOINT REQUIRED: YES
BETA DATA STRATEGY: subset aprobado; no copia DB completa; baseline remoto por definir
FIELD_MOBILE DOMAIN TRANSITION: RECOVER_EXISTING después de adaptar allowlist y backend
APK SIGNING: actual Android Debug; decisión de continuidad A/nueva firma beta requerida

REAL DB: UNCHANGED por esta fase (no SQL ejecutado)
ATTENDANCE: 0 (baseline Phase13.8E, no recontado)
VENDING EVENTS: 22 (baseline Phase13.8E, no recontado)
SUPPORT ACTIVITIES: 2 (baseline Phase13.8E, no recontado)
FIELD_MOBILE: 1 ACTIVE (baseline Phase13.8E, no challenge/ADB ejecutado)
SYBI 7: PRESERVED, no acceso ni sincronización
ASISTENCIAS_FORTIA: clean, verificado Git
SECURITY: PASS para esta ejecución de discovery; gates de exposición pública pendientes

FINAL: WAITING_FOR_SERVER_AND_DOMAIN_INPUT
```

Inputs de infraestructura no sustituyen los gates técnicos: Laravel soportado, modo beta explícito, variante Android TLS estricto y recuperación, estrategia de firma, migraciones fortia_mock, pipeline sin destinos corporativos heredados y validación de caches/health/storage.

Comprobaciones: manifests8/8 hash/bytes/dimensiones, firma APK debug identificada sin private material, archivos CI/deploy iguales a Fortia por hash, working tree Fortia limpio, diff --check correcto (advertencias CRLF existentes), plantillas example visibles en Git y registry privado ignorado. No tests/backend suite/build/install ni consulta a GitLab remoto. Sin commit, tag, push, deploy, mutaciones DNS/GitLab o claves nuevas.
