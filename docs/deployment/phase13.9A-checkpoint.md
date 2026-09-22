# Propuesta de checkpoint — Phase 13.9A.1

No se creó commit/tag. Branch preservada: `phase/14-biometric-engine-selection`. HEAD: `ea5852d151151014214e660abfd3032414997d44`. El working tree ya contenía cambios legítimos de beta/branding y documentos Phase 14; no se reseteó, limpió ni stasheó.

Propuesta para revisión; no ejecutar staging ni crear commit/tag sin autorización:

```text
Commit: feat(beta): prepare server-ready internal beta
Tag: internal-beta/1.0.1-beta.2-server-ready
Source actual: 1.0.1-beta.1 / build 11 (sin APK compilada)
Next APK: REBUILD_AFTER_DOMAIN; proponer beta.2 y confirmar build nuevo
Historical physical APK: build 10, unchanged
```

El tag propuesto no existe y conserva el prefijo del tag histórico `internal-beta/1.0.1-beta.1-build.5`. Identifica un checkpoint interno, no una versión productiva ni una APK ya validada. No reutilizar `vending-beta-1.0.1`.

La plantilla CI actual sólo permite deploy manual para tags `vending-beta-X.Y.Z`; por tanto este tag interno no habilitará deploy_beta/healthcheck. Eso es intencional en este checkpoint sin despliegue. Alinear la política de tags de deployment se revisará en Phase 13.10; aquí no se modificó el pipeline ni GitLab remoto. El tag beta.2 propuesto no cambia por sí mismo los metadatos actuales: versionName/versionCode de la próxima APK se confirmarán después del dominio.

Inventario de revisión:

| Grupo | Archivos / finalidad |
|---|---|
| Dependencias | composer.json/lock, package-lock.json; Laravel soportado y correcciones de seguridad |
| Test safety | SafeDatabaseManager, TestEnvironment, phpunit.xml, pruebas testing y lease MySQL existente |
| Beta backend | InternalBeta, BetaHttpBoundary, registry/policy y consumidores FieldIdentity/Support, config/internal_beta.php |
| Storage/readiness | filesystems.php, ReadinessProbe/Controller, bootstrap/app.php, pruebas correspondientes |
| Integraciones | LocalIntegrationMigrations, config/integrations.php y las dos ramas externas de migraciones |
| Web/red | config/app.php, cors.php, sanctum.php, vite.config.js y plantillas env sin secretos |
| Scheduler | routes/console.php, retención/SYBI deshabilitados por defecto en beta |
| Deployment | .gitlab-ci.yml, stubs PowerShell, scripts/deployment y docs/deployment |
| Android/móvil | build.gradle, manifests/policy TLS, recuperación de origen, build/beta-config, Vite/runtime/metadata y tests |
| Beta/branding previo | Revisar conjuntamente los cambios legítimos 13.8 aún no comprometidos: flujos multiusuario, componentes, recursos y manifiesto de branding |
| Diferidos | Documentos Phase 14 y herramientas locales fuera del alcance del deployment; revisar separadamente |

Excluir siempre `.env` privados, registry, backups, logs, storage, APK, node_modules, vendor, caches y material de firma. No incluir herramientas locales mediante un git add global. La plantilla de paquete usa allowlist y no depende de copiar el workspace completo.

Estado final: READY después de Phase 13.9A.1. OnPremDiagnosticsCommandTest pasa con el fixture corregido; full suite 807/807 PASS, 6922 assertions. No hay fallos conocidos. Datos/identidad preservados; auditoría concurrente documentada. La comprobación remota de CI se hará cuando exista el servidor/runner.

[Inventario explícito de staging y exclusiones](phase13.9A.1-staging-inventory.md). Incluye la corrección del fixture OnPrem; no se ejecutó staging.
