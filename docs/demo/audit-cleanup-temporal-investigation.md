# AuditCleanupModuleTest — investigación temporal

Fecha de ejecución: 2026-09-06 CDMX / 2026-09-07 UTC.

## Dictamen

AUDIT CLEANUP FAILURE: FLAKY_FIXED

La dependencia de hora real ya está presente en el código de
`vending-phase-9-pass`. No es una regresión introducida por los cambios de
Fase 10. Se corrigió exclusivamente el test y se añadieron casos deterministas
de límites; no se cambió lógica productiva.

Esto demuestra que el código del tag puede fallar según la hora de ejecución.
No afirma que la ejecución histórica que reportó 462 PASS / 1 FAIL hubiera
fallado también en AuditCleanup.

## Comparación aislada y preservación

- Tag y HEAD actual: `a246f0b659d3f2e103a7641c93138e19b791066d`.
- Worktree detached temporal:
  `C:/Users/1000ARQSW01/AppData/Local/Temp/vending-audit-review-0a66d28b5f2d47de8d5a9f550c38b0ee/baseline`.
- Se copiaron las dependencias instaladas a un vendor independiente, sin
  copiar `.env`, instalar paquetes ni modificar el árbol actual.
- `composer.json` y `composer.lock` coinciden con el tag. SHA-256 de
  `composer.lock`:
  `9548727800FA3D2124487303469D38B6E750B9DFD6E47807293CF57898AB92D9`.
- La reflexión del arnés confirmó que test, servicio y base de aplicación
  se cargan del worktree baseline, no del código actual.
- Antes de corregir, `git diff vending-phase-9-pass` no mostró diferencias
  en el test, servicios Audit, AuditCleanupSetting, TestCase, phpunit.xml,
  configuración app/operations/audit/database ni Composer.
- El test original corresponde al blob `cddc0b7`; su última modificación
  registrada es `db1e0a3cae13c2e63edc2075df87c62f1e6627a6`, del 2026-07-01.
- Huella agregada de los 1003 archivos versionados y nuevos, antes y después
  de las reproducciones, idéntica:
  `450DE3F8B657AE3B28B7D897A15725E1719F6AD4324EC932D491472CDFEE6F6F`.
- Tras la corrección, la misma comprobación, sustituyendo en memoria sólo
  el test por su contenido original y excluyendo este nuevo informe,
  conserva esa huella. El trabajo preexistente está preservado.
- El worktree baseline permanece limpio. Se conserva temporalmente como
  evidencia; no se creó commit, tag ni push.

## Reproducciones sin modificar el test original

Comando, en cada raíz:

```powershell
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --colors=never tests/Feature/AuditCleanupModuleTest.php
```

Ambos entornos usaron PHP 8.4.15 / PHPUnit 11.5.46, APP_ENV=testing,
APP_TIMEZONE=UTC, OPERATIONS_TIMEZONE=America/Mexico_City y SQLite
`:memory:`. La clave de aplicación del entorno comparativo fue aleatoria,
efímera, sólo en variables del proceso y no se imprimió.

| Código | Inicio de las tres ejecuciones, UTC | Resultado de cada ejecución |
| --- | --- | --- |
| Actual, antes de corregir | 01:30:50, 01:30:53, 01:30:55 del 2026-09-07 | 2 PASS / 1 FAIL; 22 aserciones |
| Tag baseline intacto | 01:32:42, 01:33:48, 01:33:50 del 2026-09-07 | 2 PASS / 1 FAIL; 22 aserciones |

Falla idéntica en las seis ejecuciones:

```text
test_cleanup_preview_and_execute_preserve_critical_history
Failed asserting that 3 is identical to 2.
tests/Feature/AuditCleanupModuleTest.php:69
data.records_to_delete
```

El arnés externo `AuditCleanupClockProbeTest.php`, ubicado junto al worktree,
hereda los métodos originales sin cambiar sus aserciones. Después del setUp
fija Carbon con `travelTo`, según `AUDIT_REVIEW_NOW`, e informa la procedencia
de clases, reloj, zonas y conexión. Su guardia exige testing + SQLite en memoria.

Se repitió dos veces cada instante en cada código: 18 ejecuciones del método
original por árbol, 36 en total. Los resultados coinciden en todos los casos:

| Instante fijado UTC | Fecha/hora CDMX | Baseline, 2 repeticiones | Actual original, 2 repeticiones |
| --- | --- | --- | --- |
| 2026-09-06 23:59:59 | 2026-09-06 17:59:59 | PASS / PASS | PASS / PASS |
| 2026-09-07 00:00:00 | 2026-09-06 18:00:00 | FAIL / FAIL | FAIL / FAIL |
| 2026-09-07 01:14:22 | 2026-09-06 19:14:22 | FAIL / FAIL | FAIL / FAIL |
| 2026-09-07 05:59:59 | 2026-09-06 23:59:59 | FAIL / FAIL | FAIL / FAIL |
| 2026-09-07 06:00:00 | 2026-09-07 00:00:00 | FAIL / FAIL | FAIL / FAIL |
| 2026-09-07 06:09:59 | 2026-09-07 00:09:59 | FAIL / FAIL | FAIL / FAIL |
| 2026-09-07 06:10:00 | 2026-09-07 00:10:00 | PASS / PASS | PASS / PASS |
| 2026-09-07 06:10:01 | 2026-09-07 00:10:01 | PASS / PASS | PASS / PASS |
| 2026-09-07 18:00:00 | 2026-09-07 12:00:00 | PASS / PASS | PASS / PASS |

Por árbol: 8 PASS y 10 FAIL controlados. Todos los FAIL son 3 frente a 2,
no errores de preparación ni de conexión.

## Causa exacta

1. El test original no fija Carbon/testNow y usa varias llamadas a `now()`.
   TestCase tampoco fija el reloj. El arnés verificó testNow=false antes
   de inyectar cada instante.
2. PHP y Laravel están en UTC; `operations.timezone` es
   America/Mexico_City y `operations.storage_timezone` es UTC.
3. El test usa `now()->toDateString()` como fecha de selección, es decir,
   una fecha UTC, aunque la selección representa un día de operación local.
4. `AuditCleanupService::normalizeFilters` interpreta esa fecha en
   `operations.timezone`, aplica startOfDay y convierte a UTC.
   Para selección con sólo before_date, candidateIdsQuery compara
   `created_at < cutoff`, sin incluir el instante del corte.
5. A 2026-09-07 01:14:22 UTC, el test envía 2026-09-07 aunque en CDMX
   todavía es 2026-09-06. El corte resultante es 2026-09-07 06:00:00 UTC.
   El registro de hace diez minutos, 01:04:22 UTC, sí está antes del corte:
   resulta correcto seleccionarlo con ese payload, pero contradice la
   expectativa del fixture de conservarlo.
6. Entre 06:00 y 06:09:59 UTC hay un segundo caso: las fechas ya coinciden,
   pero el registro de hace diez minutos aún pertenece al día local anterior.
   Corregir únicamente la zona de la fecha, sin fijar la hora del escenario,
   no elimina toda la dependencia temporal.
7. El cambio de resultado en 06:10:00 demuestra además el operador exclusivo:
   el registro reciente queda exactamente en el corte y se conserva.

La semántica productiva coincide con la prueba de límites ya existente en
AuditLogControllerTest y con los nuevos casos exactos. Esta investigación
no demostró un bug productivo que justifique cambiar el servicio.

## MySQL, NOW y CURRENT_TIMESTAMP

Se ejecutaron exclusivamente SELECT de relojes y metadatos sobre MySQL local,
sin limpiar registros reales ni modificar configuración.

Observación a 2026-09-07 01:31:46 UTC:

- MySQL 8.0.44; zona de sesión y global: SYSTEM.
- NOW() y CURRENT_TIMESTAMP: 2026-09-06 19:31:46.
- UTC_TIMESTAMP(): 2026-09-07 01:31:46.
- Carbon UTC: 2026-09-07T01:31:46+00:00.
- Carbon CDMX: 2026-09-06T19:31:46-06:00.
- PHP ini, PHP antes/después del bootstrap y app.timezone: UTC.
- audit_logs.created_at y updated_at: TIMESTAMP, default NULL, sin EXTRA.

El test inserta explícitamente ambos timestamps. El servicio obtiene fechas
de Carbon y las pasa como parámetros; no usa NOW() ni CURRENT_TIMESTAMP para
el corte de limpieza. La falla se reprodujo íntegramente con SQLite en memoria,
por lo que el reloj de MySQL no es su causa. No se certifica aquí toda la
semántica de almacenamiento/conversión TIMESTAMP de MySQL.

## Corrección

Archivo de código modificado: `tests/Feature/AuditCleanupModuleTest.php`.

- Se fija operations.timezone en el test.
- Se fija el reloj a 2026-09-07 01:14:22 UTC, dentro de la ventana que
  fallaba; no se desplazó artificialmente el escenario al mediodía.
- Los fixtures almacenan fechas UTC explícitas.
- La fecha de selección del escenario de conservación se obtiene en CDMX.
- Se conservan exactamente las aserciones de dos registros eliminados y de
  conservación del historial crítico y del registro reciente.
- Se añaden nueve casos con reloj fijo y selección de fecha explícita:
  antes del corte (05:59:59 UTC), exactamente en él (06:00:00) y después
  (06:00:01), conservando siempre el registro crítico.
- Laravel limpia Carbon y CarbonImmutable en el teardown; se verificó
  además regresión conjunta con AuditLogControllerTest y orden aleatorio.
- No se ampliaron tolerancias, cambiaron operadores, omitieron tests ni
  alteraron backend productivo, UX, RBAC, HMAC, integraciones o attendance.

## Validación final

| Comprobación | Resultado |
| --- | --- |
| `php artisan test --filter=AuditCleanupModuleTest` | 12 PASS / 111 aserciones |
| Cinco repeticiones PHPUnit, orden aleatorio, seeds 101/202/303/404/505 | 12 PASS / 111 aserciones en cada ejecución |
| AuditCleanupModuleTest + AuditLogControllerTest, seed 606 | 18 PASS / 170 aserciones |
| `php artisan test tests/Feature/Vending` | 108 PASS / 835 aserciones |
| `php artisan test --filter=Vending` (incluye Unit y API) | 165 PASS / 1233 aserciones |
| `php artisan test` | 479 PASS / 1 FAIL / 3791 aserciones |
| Pint scoped al test | PASS |
| `git diff --check` y revisión de espacios del informe nuevo | PASS |

Única falla remanente: OnPremDiagnosticsCommandTest, línea 54, espera exit 0
y recibe 1. También se reprodujo aisladamente en el tag intacto con el mismo
error. No se modificó ese test ni se ocultó su resultado.

La suite suma nueve casos nuevos y recupera el caso antes fallido:
469 PASS / 2 FAIL pasa a 479 PASS / 1 FAIL. Nuevas regresiones: 0.

No se repitieron build ni frontend: no hubo cambios en esos ámbitos.
ASISTENCIAS_FORTIA permanece intacto.

FASE 10 UX/DEMO: PARTIAL. Este informe no declara PASS global de la fase.
