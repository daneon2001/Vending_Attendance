# Fase 13.6C.1 — entorno visual aislado

Fecha: 2026-09-08. Rama: phase/14-biometric-engine-selection.
HEAD conservado: 702b641ef803793d025903435b81a26fc1f48a0c.
PHASE 13.6C.1: PASS, dentro del alcance de revisión aislada.
FINAL: READY_FOR_EXTERNAL_VISUAL_CONFIRMATION.
La fase 13.6C no se declara habilitada en la DB real ni certificada para producción.

## Aislamiento y límites

Se eligió A: fixtures de rendering en memoria. Node sirve los assets reales del build
Vue/Inertia, AuthenticatedLayout y componentes existentes. No se arranca Laravel,
no se carga .env y no existe conexión a DB en el servidor visual.
Database visual: ninguna. Migraciones visuales: ninguna. Seeders reales: ninguno.
Los tests Laravel dirigidos usan SQLite :memory: con APP_ENV=testing explícito.

El servidor escucha exclusivamente 127.0.0.1; valida Host, sirve una lista explícita
de assets del manifest Vite y dos logos, y rechaza rutas ajenas. POST/PATCH/DELETE
no se guardan: respuestas 405. No proxy hacia backend. CSP limita recursos a self;
no fuentes externas, OSM ni llamadas a APIs reales. La prueba del navegador también
bloquea cualquier origen externo. .env devuelve 404; POST de actividad devuelve 405.
Cookies de perfil son sintéticas, HttpOnly y SameSite=Strict; no son credenciales.
No se usa el playwright.config.ts ni auth.setup.ts de la aplicación real.

El entorno permite revisar páginas, filtros y selectores; no simula una escritura
exitosa ni certifica por sí solo el backend. No pulsar guardar/cancelar para una
prueba end-to-end: deliberadamente no hay persistencia.
Las rutas no incluidas en el recorrido responden 404.
La ausencia de fuentes remotas utiliza el fallback tipográfico existente.

## Fixtures versionables, no registros reales

- ACT-000001: ASSIGNED / MAINTENANCE, VM-DEMO-001 sintética, técnico DEMO sin cuenta,
  sin ubicación validada, sin ticket.
- ACT-000002: IN_PROGRESS / DIAGNOSTIC, INSIDE, 13 m de distancia presentada / 8 m
  de precisión sintética, ticket INC-DEMO-000001.
- ACT-000003: COMPLETED / CONFIGURATION, inicio y fin, historial completo.
- ACT-000004: CANCELLED, motivo e historial conservados.
- Búsqueda de técnicos: 23 identidades sintéticas, páginas de 20 y 3 resultados.
- Perfiles: Pilot Admin, Pilot Support, Pilot Viewer y sin permiso; no se crea User.
  Acciones support basadas en SupportPermissionsSeeder::MATRIX. El resto de props
  es deliberadamente acotado al recorrido, no una copia de cuentas/DB reales.
- Variantes ?snapshot=OUTSIDE y ?snapshot=UNCERTAIN: sólo presentación, claramente
  identificadas como sintéticas; no implican que se permita iniciar trabajo fuera de zona.

Nunca se usó SYBI 7, personas corporativas, fotos, coordenadas reales ni cuentas reales.

## Evidencia visual y defectos corregidos

Se usó design-web-frontends para preservar tokens/componentes y exigir evidencia
del navegador antes de corregir. agent-browser no estaba instalado; se usó Playwright
con Chromium local existente, sin instalar dependencias.

1. Encabezado compartido h-16: el subtítulo salía del fondo en creación/detalle/ticket;
   en listado a 1366 el título podía quedar recortado al envolver la acción.
   Cambio mínimo: min-h-16 y padding vertical. Sin cambiar contenido ni tipografía.
   El runner comprueba que el slot queda contenido dentro del header.
2. Resumen dentro del ticket a 1366: cuatro columnas comprimían estado y fecha
   en varias líneas. Modo compacto de SupportActivityList: tres columnas y creación
   bajo técnico. Se aplica sólo a resúmenes; listado principal mantiene cuatro columnas,
   y detalle mantiene inicio/fin. No cambio de consulta, datos o permisos.

Capturas anteriores: directorio temporal vending-support-visual-I08LGh.
Capturas finales: %LOCALAPPDATA%/Temp/vending-support-visual-hSZu5V.
El directorio final contiene 70 PNG y report.json; sólo datos sintéticos.
Se inspeccionaron capturas representativas de listado, filtros, creación/error, los
cuatro estados, snapshot, historial, ticket, máquina, perfiles y foco en ambas resoluciones.
El resto de capturas aporta métricas automatizadas, no aprobación humana externa.

Resultados: 1920×1080 PASS; 1366×768 PASS. Sin overflow horizontal, botones sin nombre
ni errores JS en el recorrido final; encabezados contenidos. Filtros y detalle técnico
colapsados; búsqueda/paginación funcional; fechas CDMX y estados en español.
RBAC visual: CTA para Admin/Support, no CTA ni cancelación para Viewer, sin navegación
de soporte/datos para perfil sin permiso. Es una fixture de presentación, no bypass
ni reemplazo de los tests reales de autorización/IDOR.

Accesibilidad básica: labels, nombres de controles, foco visible por Tab, errores
legibles y contraste visual de tokens existentes. PASS en el alcance revisado.
No es auditoría WCAG completa, prueba de lector de pantalla ni certificación de
contraste de todas las combinaciones. Tema oscuro/móviles quedan fuera de este gate.

## Reproducir sin DB real

Desde PowerShell:

```powershell
Set-Location C:\laragon\www\vending-attendance
node tests/Visual/support-activities-server.mjs
```

Abrir http://127.0.0.1:8136/support/activities. Cambiar perfil desde el aviso amarillo.
Los links del listado abren las cuatro actividades; ACT-000002 enlaza al ticket.
Máquina: http://127.0.0.1:8136/vending-machines/10000000-0000-4000-8000-000000000001.
Detener con Ctrl+C. No login real, no migrations, no conexión a MySQL.

Revisión automatizada independiente:

```powershell
$env:VISUAL_CHROMIUM_PATH = "$env:LOCALAPPDATA/ms-playwright/chromium-1208/chrome-win64/chrome.exe"
node tests/Visual/support-activities-review.mjs
```

VISUAL_CHROMIUM_PATH es opcional cuando la versión de navegador esperada por
Playwright ya está instalada. El runner imprime su directorio temporal de capturas
y siempre cierra Chromium y el servidor. No instala navegadores.

## Validaciones y cleanup

- Browser final: 70 capturas / 0 fallos automatizados, cuatro perfiles y dos resoluciones.
- Frontend: 124 PASS (incluye regresión de resumen compacto).
- Laravel dirigido: 38 PASS / 720 assertions:
  SupportActivityWebTest (13), SupportWebTest (5), GeofenceEditorTest (11),
  FleetMaintenanceAndUiTest (9). Autorización/IDOR y escala real de pruebas preservados.
- Build: PASS, 885 módulos. Aviso no bloqueante de browserslist desactualizado.
- Pint: no aplica; ningún PHP modificado en C.1.
- Git diff --check: PASS; incluye revisión separada de archivos nuevos.
- Suite completa no repetida: sin cambios backend. Referencia de C:
  706 PASS / 1 FAIL conocido OnPremDiagnosticsCommandTest.

Al terminar no quedan servidor ni navegador de esta revisión ejecutándose.
Fixtures runtime: descartadas al terminar procesos; DB temporal: no se creó.
Se conservan los tests versionables y capturas sintéticas como evidencia local,
fuera de Git. No se borraron datos ni archivos del usuario.

Baseline antes/después idéntico por consulta de sólo lectura:
users 5; employees 2507 (2502 MANUAL + 5 DEMO); attendance_logs 0;
vending_attendance_events 19. SYBI 7 RESERVED, DRAFT, sin geofence ni Device;
assignment 7 ACTIVE y updated_at sin cambio. Sin nueva asociación User/Employee real.
ASISTENCIAS_FORTIA clean. Los 22 hashes protegidos de identidad, servicio/acceso,
migraciones y documentos biometric-* coinciden con el baseline.
No commit, tag, push, deploy, Android, asistencia ni implementación de Phase 14.
