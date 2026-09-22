# Phase 13.9A — resultado

Fecha: 2026-09-14. Reclasificada PASS tras [Phase 13.9A.1](phase13.9A.1-result.md): fixture OnPrem corregido sin cambios productivos, suite 807/807 PASS y baseline preservado. NEW CHECKPOINT: READY. Las secciones históricas inferiores conservan los resultados y deltas de la ejecución original de 13.9A.

## Salida obligatoria

```text
PHASE 13.9A: PASS
CURRENT LARAVEL: 11.47.0 al inicio; 12.69.2 instalado al cierre
TARGET LARAVEL: 12.69.2 / rama ^12.0
LARAVEL UPGRADE: PASS
TEST DB SAFETY: PASS
FORTIA DEPLOYMENT LEAK: REMOVED
VENDING GITLAB PIPELINE: READY_TEMPLATE
FORTIA SERVER REFERENCES: 0 deployment runtime; BUSINESS_INTEGRATION preservada
MIGRATIONS: BETA_CLEAN
DISPOSABLE MYSQL MIGRATION: PASS; 105 migrations; residual 0
BETA TESTER REGISTRY: SERVER_READY; archivo real del servidor aún no provisionado
BETA MODE GUARD: PASS
OTP LOCAL_SIMULATED: BETA_ONLY en servidor; local/testing conservados; production denegado
PHONE VERIFIED: false
PRIVATE STORAGE: PASS
READINESS: PASS en pruebas aisladas
SCHEDULER: nonces ENABLE_BETA; cleanup/import retention/SYBI DISABLE_BETA
ANDROID ENVIRONMENTS: LOCAL_BETA_PROD_SEPARATED
BETA NETWORK SECURITY: PASS arquitectónico/nativo; no APK pública ni servidor aún
ORIGIN RECOVERY: PRESERVED mediante origen explícito, clave existente y challenge
APK SIGNING: build10 Android Debug preservado; ninguna clave nueva
RECOMMENDED SIGNING PATH: mismo certificado APK temporalmente para HONOR; decisión del siguiente artefacto pendiente
BRANDING: MEDICAL_LIFE_ONE_PRESERVED; 8/8 hashes/bytes MATCH
RUNTIME HARDCODED LAN IPS: 0 en source/bundles beta; fixtures/local tools históricos separados
CORS/SANCTUM: PARAMETERIZED
TESTS: guard/readiness/beta 14 PASS, 61 assertions; matriz funcional 484 PASS, 4252 assertions
TESTS MOBILE: 340 PASS; comprobación final dirigida 23 PASS
TESTS ANDROID NATIVE: 11 PASS
FRONTEND: web beta build PASS; mobile typecheck/beta build PASS
FULL SUITE: 807 tests, 6922 assertions, 807 PASS, 0 known failures; exit 0 (13.9A.1)
REAL DB: BUSINESS/IDENTITY UNCHANGED, 18/18; 3 conjuntos de auditoría/challenges con delta concurrente
SECURITY: PASS; Composer/npm web/npm mobile sin vulnerabilidades reportadas
NEW CHECKPOINT: READY; inventario explícito de 128 rutas preparado, sin staging
SERVER INPUT FORM: phase13.10-server-input.txt
FINAL: READY_FOR_CHECKPOINT_AUTHORIZATION
```

No deploy, DNS, GitLab remoto, push, commit ni tag. No suite completa repetida: se ejecutó una vez; los últimos ajustes de configuración/build se comprobaron con pruebas dirigidas y builds. No se instaló APK nueva ni se abrió una sesión del teléfono desde estas herramientas.

## Fallo histórico original — resuelto en 13.9A.1

`Tests\Feature\Console\OnPremDiagnosticsCommandTest::test_onprem_diagnostics_command_passes_and_generates_json_report` sigue esperando exit 0 y recibiendo exit 1. Su fixture local no construye `clocks`, deuda identificada antes del upgrade. Se conservó el test y no se introdujo skip ni allow_failure. No hubo otra regresión en los 807 tests. Las pruebas funcionales FieldIdentity, soporte, Vending, auth/RBAC y APIs pasaron.

Esta fue la causa del bloqueo original. Phase 13.9A.1 corrigió únicamente el fixture y volvió a ejecutar la secuencia completa: 807/807 PASS. El gate local queda cerrado; la ejecución remota de CI y el despliegue siguen pendientes de configuración y autorización.

## Preservación y deltas concurrentes

Se capturaron hashes semánticos en transacciones SQL READ ONLY antes y después. Coinciden exactamente los 18 conjuntos de negocio/identidad, excluyendo únicamente los timestamps/token de sesión previamente definidos en la comparación. Los snapshots completos cubren 21 conjuntos; no se afirma 21/21 MATCH.

| Dato | Antes | Cierre |
|---|---:|---:|
| employees | 2507 | 2507 |
| users | 6 | 6 |
| attendance_logs | 0 | 0 |
| vending_attendance_events | 22 | 22 |
| vending_support_activities | 2 | 2 |
| employee_devices | 1 | 1 |
| audit_logs | 2831 | 2834 |
| field_device_challenges | 66 | 71 |
| field_device_audit_events | 187 | 202 |

La revisión de eventos muestra challenges ACTOR consumidos por el dispositivo A, con CHALLENGE_ISSUED, PROOF_ATTEMPT y ACTOR_PROVED para User 4 / Employee 5. La auditoría general contiene eventos device.bootstrap. Son compatibles con actividad concurrente de la aplicación conectada; no se atribuyen a las pruebas aisladas ni se borran para forzar el baseline. No se inspeccionaron mensajes de challenge, teléfonos, contraseñas o claves privadas.

FIELD_MOBILE A sigue ACTIVE: mismo id 1, UUID, fingerprint, key version 1, owner y activated_at. FIELD_MOBILE B no se creó. SYBI 7 y assignments se preservan por hash. Fortia permanece con working tree limpio y HEAD `0329291908caf77df3aedc2f4365acf2d321551c`.

## Artefacto físico histórico

```text
Filename: VendingAttendance-1.0.1-beta.1-build10-medical-life-dispenser.apk
Size: 30783135 bytes
SHA256: a58c8f5ec103f0dcf222e665fee12dd4e9041f7d57e729b75cd89ea3dbe501a8
Signing certificate SHA256: 2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec
```

El hash APK se verificó nuevamente. La fuente prepara build 11, sin producir ni instalar APK. No se reutilizó versionCode 10. Assets de la dispensadora real y logos: 8/8 MATCH, sin rediseño.

## Evidencia privada y documentos de revisión

Evidencia fuera de Git en `storage/app/private/phase139a/`: before.json, after-final.json, full-suite.txt/xml, backend-matrix.txt, final-safety.txt, mysql-migrations.txt, android-native.txt, mobile-tests.txt, mobile-final-targeted.txt, web-build.txt, mobile-build.txt y auditorías Composer/npm. Backups previos de composer.json/lock y package-lock.json en el mismo directorio; sin credenciales impresas.

- [Implementación, firma y límites](phase13.9A-implementation.md).
- [Clasificación de 105 migraciones](migrations-beta-classification.md).
- [Propuesta de checkpoint](phase13.9A-checkpoint.md).
- [Formulario exacto de servidor](phase13.10-server-input.txt).

Laravel 12 se eligió por compatibilidad y menor cambio, con soporte de seguridad hasta 2027-02-24; planificar otro major antes del vencimiento. [Soporte oficial](https://laravel.com/framework/docs/12.x/releases).
