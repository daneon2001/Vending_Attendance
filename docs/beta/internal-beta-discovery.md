# Phase 13.7 — descubrimiento previo a cambios

11 septiembre 2026. Alcance DEMO / beta interna, no producción.
Skill utilizada: design-web-frontends. Sin AGENTS.md ni .codex en este repositorio;
no se trasladan reglas de dominio del proyecto DSSIA vecino.

## Gate 0

Rama phase/14-biometric-engine-selection, HEAD 702b641ef803793d025903435b81a26fc1f48a0c.
Working tree con cambios de fases previas, conservados. git diff --check PASS.
DB local: attendance_logs=0, vending_attendance_events=22, activities=2 COMPLETED,
employees=2507, users=5, assignments=8. Evento 22: Entrada DEMO manual del
10/09/2026 a 15:03:43 CDMX, confirmada por el operador como demostración de APK.
Se adopta 22, sin eliminar ni corregir filas. Las primeras 21 mantienen SHA256
1e39d37782dc3c789a9de3f1bc15561124965d02da8e4278437aa43185734378.
SYBI 7 DRAFT sin geocerca/Device; assignment 7 intacto. Fortia limpio.

## Matriz — evidencia de código/activos, no PASS visual

| Componente | Web | Android | Inconsistencia / evidencia | Severidad | Corrección mínima propuesta |
| --- | --- | --- | --- | --- | --- |
| Identidad | Logo Medical Life en public/images y layout | Icono/splash PNG genérico Capacitor inspeccionado | Activos nativos no corresponden al producto | HIGH | Reusar marca corta intacta, sin generación de logo |
| Documento base | Español/producto en Web | index.html tenía Ionic App, lang=en y zoom deshabilitado | Naming/lenguaje accesible incorrectos | MEDIUM | Español, nombre oficial, favicon del activo y permitir zoom |
| Inicio | Navegación por tareas | Home abre selector de empleados antes de acciones personales | HomePage.vue prioriza terminal en todo caso | HIGH | Acciones por capacidades; asistencia sólo con terminal y empleados efectivos |
| Instalación nueva | Cuenta individual | main.ts redirige sin identidad terminal a /provision | Onboarding FIELD inaccesible desde instalación limpia | BLOCKER | Entrada personal sin aprovisionar; terminal como opción explícita |
| Diagnóstico | Versiones y estados en módulos | Sólo detalles de terminal | No hay diagnóstico seguro unificado para tester | HIGH | Pantalla de consulta sin firmas ni mutaciones de negocio |
| Identidad personal | employee_device con RBAC | Registro individual y Keystore | RegisteredPhoneSource sólo permite DEMO exacto actual | BLOCKER para otros testers | Documentar requisito de identidad/phone source autorizados; no ampliar excepción |
| Versionado | SemVer/build, canal DEV/PILOT/PRODUCTION | Android 1.0/1, npm 0.0.1 | Metadata no alineada para beta | MEDIUM | Candidata aprobada 1.0.1-beta.1 / 2 opt-in DEBUG; no publicar en DB |
| Navegación | Grupos por tarea; identidad dentro administración | Volver/Ionic y componentes existentes | Terminal y personal pueden confundirse | MEDIUM | Naming explícito y grupo identidad, rutas existentes y mismos permisos |
| Biometría | Shell con datos reales, captura futura | Pendiente de habilitación | No existe motor seleccionado | NO DEFECTO | Mantener no habilitada; no borrar texto futuro verdadero |
| Offline | captured/received y evidencia privada | Cola, confirmación, caché vacía diferenciadas | Flujo 13.6E validado físicamente | NO DEFECTO | Conservar semántica; revisión visual externa |
| Estados/errores | Helpers españoles y detalle técnico | operationLabels, soporte y FieldMobileError | Mensajes de red no distinguen causa TLS de transporte | MEDIUM | Diagnóstico seguro de accesibilidad HTTPS; no afirmar causa sin evidencia |
| Dashboard/máquinas/geocercas | Tarjetas, filtros y detalles existentes | Detalle de zona técnico | No hay nueva evidencia de defecto | PENDING VISUAL | Revisar 1920/1366, no cambios ornamentales |
| Empleados/dispositivos/versiones | Catálogos con tablas y filtros | Terminal/registro individual | Compresión o clipping no demostrado en esta ejecución | PENDING VISUAL | Checklist externa por rol |
| Tickets/verificaciones/actividades | Layout/cards/tablas responsive existentes | Ionic, fotos y listas | No repetir E2E ni asumir revisión global aprobada | PENDING VISUAL | Revisar pantallas actuales sin registros nuevos |
| Accesibilidad | Focus/labels y controles 44px | Controles 48px y safe-area inferior | Keyboard/gestos/contraste requieren observación real | MEDIUM | Conservar tamaños; comprobar en HONOR, no declarar WCAG |
| Espaciado/colores | Tailwind indigo/slate, radius/tokens existentes | Azul Medical, Roboto, radio 10px | No se exige pixel-perfect | LOW | Mantener ambos sistemas, reutilizar marca y jerarquía; no rediseño |

Fuentes: main.ts, HomePage.vue, App.vue, theme/variables.css,
FieldMobilePage.vue, FieldMobileFlow.ts, FieldOfflineSupport.ts, operationLabels.ts,
SupportHomePage.vue, TerminalStatus.vue, AndroidManifest.xml, styles.xml,
ic_launcher.png, splash.png; AuthenticatedLayout.vue, navigation.js,
FieldIdentity/Index.vue, Support/Activities/Show.vue, SupportNotificationFeed.vue,
VendingFleet/Releases.vue, docs/architecture/mobile-release-management.md,
RegisteredPhoneSource.php y EnrollmentIdentity.php.

Revisión externa pendiente: todos los gates visuales de esta fase; no confundir
las capturas aprobadas de 13.6E con validación del nuevo inicio/diagnóstico/icono.
Máximo tres iteraciones. No descargar logos ni añadir dependencias.
