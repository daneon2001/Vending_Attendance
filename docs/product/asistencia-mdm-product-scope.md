# Asistencia MDM

## Propósito

Plataforma interna de Medical Life para la operación de Máquinas Dispensadoras de Medicamentos (MDM). «Asistencia» comprende la dimensión laboral y la operativa: no identifica únicamente un checador. Nombre visible aprobado en BRAND-MDM-01: **Asistencia MDM**; composición de marca: **ASISTENCIA MDM**, con **Medical Life** como referencia corporativa secundaria.

## Dos dimensiones de Asistencia

### Asistencia laboral

| Capacidad | Estado actual | Evidencia y límite |
|---|---|---|
| Captura de eventos, GPS/evidencia y geocerca versionada | IMPLEMENTED | `mobile/src/views`, `app/Services/Vending`; [eventos](../architecture/vending-attendance-events.md), [geocercas](../architecture/attendance-geofence-evidence.md). La evaluación espacial y la autorización son resultados distintos. |
| Persistencia SQLite y outbox, reintentos y recepción backend | IMPLEMENTED | `mobile/src/storage`, `mobile/src/services`; [idempotencia](../architecture/attendance-idempotency.md). No promete sincronización en background. |
| Administración laboral completa de eventos MDM | NOT IMPLEMENTED | `vending_attendance_events` no se proyecta automáticamente a `attendance_logs`. Las pantallas legacy de asistencias y tarjetas no constituyen una consolidación laboral completa de MDM. |
| Horarios, turnos, calendarios, festivos e incidencias laborales del flujo MDM | NOT IMPLEMENTED | Alcance futuro; no confundir tickets operativos con incidencias laborales. No se declara inexistente toda capacidad heredada por esta clasificación. |
| Prenómina, revisión/aprobación/cierre y envío laboral a Fortia | NOT IMPLEMENTED | El ciclo completo MDM sigue pendiente; contratos y decisiones de negocio deberán cerrarse antes de implementarlo. |

**STORED confirma recepción; no equivale a asistencia oficial, autorización laboral, pago ni prenómina.** La integración de empleados Fortia es una capacidad distinta del envío futuro de asistencias. Su disponibilidad externa no queda acreditada por este cambio de marca.

### Asistencia operativa

| Capacidad | Estado actual | Evidencia y límite |
|---|---|---|
| Identidad personal y autorización de dispositivo Android | IMPLEMENTED | `mobile/src/fieldIdentity`, `app/Services/FieldIdentity`; [identidad](../architecture/vending-device-identity.md). Independiente de la identidad de terminal. |
| Soporte, tickets, verificaciones y seguimiento | IMPLEMENTED | `mobile/src/support`, `app/Services/Support`; [soporte](../architecture/support-ticket-domain.md). |
| Actividades y mantenimiento autorizado por assignment | IMPLEMENTED | `mobile/src/fieldSupport`; [actividades](../architecture/vending-support-activity-domain.md), [soporte personal](../architecture/vending-field-support.md). El tipo TECHNICIAN por sí solo no concede autorización. |
| Operación offline | PARTIAL | Implementada en los flujos que tienen store/outbox, no en toda acción administrativa; [sincronización](../architecture/support-offline-sync.md). |
| Diagnóstico sanitizado | IMPLEMENTED | `mobile/src/diagnostics/collect.ts`; consulta estados, sin generar checadas ni reprovisionar. |
| Paridad física iOS de FIELD_MOBILE | BLOCKED | Existe proyecto iOS; la implementación del store personal exige Android. Cambiar el display name no acredita paridad ni validación iOS. |
| Biometría móvil | NOT IMPLEMENTED | Provider no soportado; no se incorpora captura ni matching en esta fase. |

## Usuarios y roles

La administración web usa sesión y RBAC por módulo/acción. `resources/js/presentation/navigation.js` determina visibilidad y las rutas backend exigen permisos: `vending_machines` para operación, `employees` para empleados, `support` para soporte y `employee_device` para dispositivos personales. `asistencias.view` pertenece a las pantallas heredadas. `perm` admite el fallback existente `settings.manage`; `perm.strict` no.

El nombre de un rol administrativo no implica automáticamente todos los permisos. FIELD_MOBILE utiliza usuario ligado a empleado, política piloto cuando aplica, identidad del dispositivo y autorización por objeto/assignment. Las terminales mantienen credenciales propias. No se cambian grants, usuarios, roles, bindings ni registros privados. Habrá una fase posterior de depuración formal RBAC.

## Identidad histórica y compatibilidad

**Vending Attendance / Medical Life One → Asistencia MDM** es una evolución de identidad de producto, no una migración del dominio técnico.

Se conservan repositorio `vending-attendance`, namespaces, modelos, tablas, migraciones, APIs, `com.medicalife.vendingattendance`, esquemas URL, UUID, claves y nombres de almacenes. No se altera Build 12 ni `internal-beta.json`. HONOR y Motorola permanecen como baseline físico histórico; el nuevo nombre se prepara para un APK posterior expresamente autorizado. Esta fase no genera ni instala APK.

Las evidencias de builds, LAN, MD-02, auditorías y checkpoints conservan sus nombres y hashes. **Nombre histórico del producto; la identidad vigente posterior es Asistencia MDM.** Esta precedencia se documenta aquí sin reescribir esos archivos.

## Auditoría de superficies y clasificación

| Clasificación | Superficies encontradas | Tratamiento |
|---|---|---|
| PRODUCT-NAME | Título Inertia (`resources/js/app.js`), Blade, GuestLayout/login, AuthenticatedLayout, Dashboard | Asistencia MDM; sidebar, footer y encabezados. El panel de operación hereda el layout. |
| PRODUCT-NAME | `mobile/index.html`, BrandIdentity, HomePage, diagnóstico `collect.ts` | Asistencia MDM; BrandIdentity propaga el cambio a recuperación, diagnóstico, Mi dispositivo, actividades y SupportLayout. |
| PRODUCT-NAME | Capacitor appName, Android app_name/title_activity_main, iOS CFBundleDisplayName | Nombre visible actualizado, identificadores conservados. |
| CORPORATE-BRAND | Medical Life en login, banners, textos alternativos e imágenes de dispensadoras | Se conserva. Colores y CSS corporativo no cambian. |
| CORPORATE-BRAND | Logos completos con texto rasterizado Medical Life One | Originales preservados. La composición activa web/móvil usa el símbolo existente sin texto y tipografía HTML; no requiere regenerar imágenes. Favicons de símbolo se conservan. |
| BUSINESS-DOMAIN | Operación vending, máquina vending, catálogo SYBI y descripción de terminal personal | Se conserva donde describe el dominio; no se sustituye automáticamente por nombre de producto. |
| TECHNICAL-IDENTIFIER | Rutas vending, imports/clases, paquetes, SQLite, Secure Storage, Gradle, contratos | Sin cambios. No se cambia APP_NAME local ni claves de configuración que puedan afectar sesiones. |
| HISTORICAL-DOCUMENTATION | Docs de builds, LAN, auditorías, checkpoints y evidencia física | Sin cambios; aplica la precedencia histórica anterior. |

`mobile/src/diagnostics/presentation.ts`, temas, navegación y páginas consumidoras sin nombre propio no requieren cambios. No se incorpora ningún cambio pendiente de beta, identidad, deployment, LAN o Phase14.

## UX-01 pendiente

**RECORDED / PRESERVED.** En `mobile/src/views/HomePage.vue`, «Registrar asistencia» despliega `attendance-employees` después del banner y puede quedar fuera del viewport. BRAND-MDM-01 sólo cambia textos de marca en esa página; no mueve el selector, no altera eventos, scroll ni condiciones de visibilidad. Puede resolverse como unidad posterior independiente con pruebas de descubribilidad y viewport. No queda corregido por el cambio de nombre.
