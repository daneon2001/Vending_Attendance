# Asistencia MDM — Server input

Formulario de RELEASE-0-PRE (2026-09-19). Completar por entorno antes de preparar configuración. No autoriza deploy ni creación de infraestructura. Referencia: [production-readiness.md](production-readiness.md).

**No pegar passwords, APP_KEY, tokens, claves privadas, teléfonos ni datos de empleados.** Usar referencias al almacén seguro por canal privado. IPs/accesos reales se entregan por canal operativo restringido, no se incorporan al documento versionado. PENDING significa desconocido, no valor por defecto desplegable.

| Campo | STAGING | PRODUCTION |
|---|---|---|
| CLOUD PROVIDER | PENDING | PENDING |
| SERVER IP (canal privado) | PENDING | PENDING |
| REGION / NETWORK | PENDING | PENDING |
| OS / VERSION / ARCHITECTURE | PENDING | PENDING |
| CPU | PENDING | PENDING |
| RAM | PENDING | PENDING |
| DISK / TYPE / FREE SPACE | PENDING | PENDING |
| STAGING DOMAIN | PENDING | No aplica |
| PRODUCTION DOMAIN | No aplica | PENDING |
| WEB DOMAIN APPROVED | PENDING | PENDING |
| API ORIGIN APPROVED | PENDING | PENDING |
| WEB/API SAME ORIGIN | PENDING | PENDING |
| DNS OWNER / CHANGE PROCESS | PENDING | PENDING |
| SSH USER | PENDING | PENDING |
| SSH PORT / ACCESS METHOD | PENDING | PENDING |
| SSH HOST KEY VERIFICATION | PENDING | PENDING |
| DEPLOY PATH | PENDING | PENDING |
| RUNTIME USER / GROUP | PENDING | PENDING |
| MYSQL HOST (canal privado) | PENDING | PENDING |
| MYSQL PORT | PENDING | PENDING |
| MYSQL VERSION | PENDING | PENDING |
| DATABASE CREATED | PENDING | PENDING |
| DB APPLICATION USER CREATED | PENDING | PENDING |
| DB MIGRATION USER / PROCESS | PENDING | PENDING |
| DB BACKUP USER / PROCESS | PENDING | PENDING |
| DB NETWORK / TLS / CA | PENDING | PENDING |
| DB CHARSET / COLLATION / TIMEZONE | PENDING | PENDING |
| TLS METHOD / CERTIFICATE PROVIDER | PENDING | PENDING |
| TLS TERMINATION (host/LB/proxy) | PENDING | PENDING |
| TRUSTED PROXY RANGES (canal privado) | PENDING | PENDING |
| RENEWAL OWNER / ALERTS | PENDING | PENDING |
| FIREWALL / SSH ALLOWLIST | PENDING | PENDING |
| CI / REPOSITORY / PROTECTED ENVIRONMENT | PENDING | PENDING |
| RUNNER / OS / TOOLCHAIN | PENDING | PENDING |
| DEPLOY APPROVER / TAG POLICY | PENDING | PENDING |
| SECRET STORE REFERENCE (sin valores) | PENDING | PENDING |
| MAIL PROVIDER | PENDING | PENDING |
| MAIL HOST / PORT / TLS METHOD | PENDING | PENDING |
| MAIL FROM / DNS AUTHORIZATION | PENDING | PENDING |
| MAIL SANDBOX / RECIPIENT POLICY | PENDING | PENDING |
| STORAGE PATHS / CAPACITY / OWNER | PENDING | PENDING |
| PRIVATE EVIDENCE RETENTION OWNER | PENDING | PENDING |
| QUEUE DRIVER DECISION | PENDING | PENDING |
| SCHEDULER / CLEANUP POLICY APPROVED | PENDING | PENDING |
| BACKUP DESTINATION (referencia privada) | PENDING | PENDING |
| BACKUP ENCRYPTION / KEY CUSTODIAN | PENDING | PENDING |
| BACKUP FREQUENCY / RETENTION | PENDING | PENDING |
| RPO / RTO | PENDING | PENDING |
| RESTORE TEST EVIDENCE | PENDING | PENDING |
| MONITORING / ALERT DESTINATION | PENDING | PENDING |
| OPERATIONS OWNER / INCIDENT CONTACT | PENDING | PENDING |
| MAINTENANCE WINDOW / ROLLBACK OWNER | PENDING | PENDING |
| APPROVED INITIAL DATA SCOPE | PENDING | PENDING |
| RBAC / FIRST ADMIN PROVISIONING | PENDING | PENDING |
| INTEGRATIONS ENABLED / CONTRACTS | PENDING | PENDING |
| PROD-IDENTITY-01 GATE | PENDING | PENDING |
| MOBILE-STORE-01 / SIGNING GATE | PENDING | PENDING |

## Decisiones previas a STAGING

1. Confirmar si se acepta staging productivo con APP_ENV=staging. El onboarding FIELD_MOBILE actual permanece bloqueado allí hasta PROD-IDENTITY-01. Si se necesita demo beta, declarar un entorno beta separado.
2. Confirmar dominios web/API del mismo origen, Linux/Nginx/FPM, MySQL y aislamiento de DB/storage/secrets. Ningún valor de production se reutiliza.
3. Aportar capacidad, accesos y rutas; datos de SSH/DB por canal seguro, sin credenciales en este archivo.
4. Definir correo sandbox, backup/restore, alertas y política de scheduler/retención antes de configurar servicios.
5. Resolver artefacto consolidado, CI y aprobación; los scripts actuales están restringidos a beta y necesitan adaptación revisada. Proveer inputs no autoriza ejecutarlos.
