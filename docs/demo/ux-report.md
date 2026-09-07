# Fase 10 UX/DEMO — informe de implementación

FASE 10 UX/DEMO: PARTIAL
DEMO: NOT_READY
Fecha: 2026-09-06
Repositorio: C:\laragon\www\vending-attendance
Baseline aprobado: vending-phase-9-pass

## UX AUDIT
Diagnóstico previo en [ux-audit.md](ux-audit.md). Se corrigieron navegación mezclada, acciones sin filtro de capacidad, anglicismos, errores crudos, fechas ISO y carga excesiva de filtros/columnas en el recorrido principal.
La skill design-web-frontends guio la reutilización de tokens, componentes, permisos reales y divulgación progresiva. No se añadieron dependencias.

## SPANISH
- Device Registry → Dispositivos; Health → Estado; Lifecycle → Estado del dispositivo; Heartbeat → Última conexión; Outbox → Registros pendientes; Clock drift → Diferencia de hora.
- Releases → Versiones de aplicación; rollout → distribución gradual; target → destinatario; provisioning → activación.
- Estados centralizados con etiquetas y colores, sin modificar values enviados: ACTIVE, PENDING, RETIRED, SUSPENDED, REVOKED, DEGRADED, UNKNOWN, STALE, SYNCED, OFFLINE, ONLINE y catálogos SYBI/versiones/asignaciones.
- auth.failed → Correo o contraseña incorrectos. Mensajes SYBI y códigos desconocidos tratados con explicación amigable; detalle conserva código.
- Autenticación, recuperación, confirmación y perfil localizados. Título y bienvenida Medical Life / Vending Attendance, sin cifras ni promesas heredadas.
- Fechas del recorrido vending: es-MX, America/Mexico_City. No se cambió almacenamiento UTC, campos de fecha enviados ni zona de cálculo de informes heredados.
- Permanecen nombres propios y formatos: Medical Life, Vending Attendance, SYBI, Fortia, Android, iOS, CSV, XLSX, UUID y SHA-256. Códigos de diagnóstico y metadatos especializados están en detalle técnico; columnas de archivo conservan el encabezado recibido para revisión.
- Welcome.vue conserva la plantilla técnica Laravel, sin ruta activa. No se certifica la localización de futuros mensajes arbitrarios provenientes de integraciones.

## NAVIGATION
Antes: operación técnica, catálogos heredados y administración mezclados; panel/relojes visibles sin filtro.
Después:
- OPERACIÓN: Resumen, Dispositivos, Alertas; asistencias/tarjetas sólo con permiso real.
- PERSONAL: Empleados.
- VENDING: Máquinas (detalle contiene asignaciones/geocercas), Catálogo SYBI.
- ADMINISTRACIÓN: Versiones de aplicación; configuración, roles, usuarios y auditoría según permiso.
- OTRAS HERRAMIENTAS: panel anterior, corporativo, relojes, empresas y unidades, con capacidad correspondiente.

No se inventaron rutas independientes para asignaciones/geocercas. Se mantuvo permiso estricto de empleados y la excepción settings.manage sólo donde el middleware existente la admite.
Inicio piloto: acceso claro a Resumen, sin montar las consultas del panel anterior. El panel anterior se conserva en Dashboard/LegacyDashboard.vue para usuarios autorizados.
Menú de tableta: diálogo nativo, cierre al cruzar 1024 px. Comportamiento físico del foco pendiente de observación.

## DEVICE REGISTRY
Antes: 11 filtros abiertos, 13 columnas, mínimo 105 rem.
Después: Buscar (código/UUID/serie), Estado del dispositivo y Máquina.
Avanzados cerrados: plataforma, versión, canal, sincronización, última conexión, diferencia de hora, registros pendientes, geocerca. Contador y Limpiar incluyen filtros ocultos.
Columnas: Máquina, Dispositivo, Estado, Última conexión, Sincronización, Versión, Acciones.
Detalle conserva UUID, activación, motivos, versión/compilación, canal/grupo, espacio, red, registros pendientes, geocerca, fechas, versiones servidor/dispositivo, confirmaciones, métricas de recepción/demora y errores. Cambios de canal/grupo sólo con canManage, manteniendo el comportamiento existente.

## LOGIN
PASS en compilación y prueba de renderizado: marca adecuada y auth.failed traducido.
Prueba interactiva de credenciales en navegador: pendiente. No se introdujeron ni restablecieron contraseñas reales.

## DASHBOARD
Cinco indicadores del servidor y una tarjeta de acceso a Alertas. Resto de indicadores y criterios disponibles en paneles desplegables.
No se inventó un total de alertas: el backend limita la lista. Máquinas operativas mantiene el criterio actual de activas o en mantenimiento. Empleados asignados son personas distintas con asignación vigente. Hoy conserva el cálculo del servidor.
Sin cambios del motor de salud, métricas, umbrales ni alertas.

## EMPLOYEES / IMPORT
Número, nombre, estado, origen y última sincronización legibles.
Asignaciones vigentes consultables bajo permiso estricto mediante GET existente /api/v1/employees/{employee}/vending-machines. No se agregaron campos ni endpoints.
Indicador Fortia mock visible para todos los lectores.
Wizard: seleccionar archivo → revisar columnas → validar información → confirmar cambios → resultado. Volver, cambio de archivo y actualización de vista previa invalidan confirmación. No se aplica información sin aceptación explícita.
La carga genera staging temporal existente; no se presenta como operación sin escrituras. No se ejecutó importación real en esta fase.

## SYBI / MÁQUINAS / GEOCERCAS / VERSIONES
- Máquinas: tabla de seis columnas frente a diez; ubicación, estado, geocerca, asignaciones y acceso a dispositivos. No se inventó una cantidad de dispositivos ausente en props.
- SYBI: cinco columnas frente a diez; validación amigable y problemas explicados; coordenadas/fechas/códigos en detalle.
- Detalle de máquina: dispositivos de once a cinco columnas; asignaciones/geocercas conservadas; formularios bajo apertura explícita y permiso.
- Versiones: seis columnas frente a nueve; formularios administrativos colapsados, lectura accesible sin botones mutables a roles no administradores.
- No se muestran URLs completas de artefactos en el listado. No se generaron códigos de activación.

## ROLE UX
| Rol piloto | Lectura vending | Importar / consultar Fortia | Asignar | Administrar máquinas / geocercas / dispositivos / versiones / SYBI |
| --- | --- | --- | --- | --- |
| Admin | Sí | Sí | Sí | Sí |
| Operator | Sí | Sí | Sí | No |
| Support | Sí | No | No | No |
| Viewer | Sí | No | No | No |

Pruebas de presentación PASS. RBAC, roles y permisos almacenados intactos. La aplicación de Fortia además respeta write_enabled existente.
Revisión visual de cada rol: pendiente.

## VISUAL REVIEW
MANUAL_VISUAL_REVIEW_REQUIRED.
La herramienta de navegador devolvió lista vacía. Ninguna pantalla fue observada en navegador.
Pendientes: Login, Inicio/Dashboard, Fleet, Employees, Import, Machines/detalle, SYBI, Device Registry y Releases en 1366×768, 1920×1080 y tableta.
Renderizado Node usa stubs de navegación/HTTP/modales; verifica condiciones y texto, no CSS, clics, red real ni foco. La prueba del layout sí ejecuta su inicialización real.

## TESTS / BUILD
| Validación | Resultado |
| --- | --- |
| php artisan test --filter=Vending | PASS: 157 pruebas, 1123 aserciones, 9.88 s |
| node --test tests/Frontend/employeeImport.test.js tests/Frontend/presentation.test.js | PASS: 32 pruebas |
| php artisan test | 462 PASS / 1 FAIL heredado, 3600 aserciones, 25.64 s |
| npm run build | PASS |
| git diff --check | PASS |
| Archivos nuevos, diff --no-index --check | Sin errores de formato |
| Pint scoped | No aplica: esta fase no modificó PHP |
| Android | Sin cambios; no se recompiló |

El fallo heredado es Tests\\Feature\\Console\\OnPremDiagnosticsCommandTest:54 (exit code esperado 0, recibido 1), documentado antes de esta fase en docs/architecture/pilot-validation.md. No se corrigió fuera de alcance. No se afirma PASS de suite completa ni ausencia de toda regresión visual.

## SECURITY / REGRESSION
PASS del alcance de cambios: sólo presentación, pruebas frontend y documentación.
No se editaron backend, permisos, contratos, enums persistidos, HMAC, motor de asistencia, manifests, SYBI/Fortia ni biometría en esta fase.
No se modificó .env ni se introdujeron secretos. No se ejecutó reset, importación, asignación, sincronización o administración contra la BD real.
Los cambios de backend/seeders/rutas/.env.example visibles en git status ya existían al iniciar y se conservaron.
ASISTENCIAS_FORTIA: git status --porcelain vacío; sin escrituras.
Sin commit, tag, push, merge ni deploy.

## FILES CREATED
- `docs/demo/ux-audit.md`
- `docs/demo/demo-walkthrough.md`
- `docs/demo/demo-checklist.md`
- `docs/demo/ux-report.md`
- `resources/js/Components/AdvancedFilters.vue`
- `resources/js/Components/RecordPagination.vue`
- `resources/js/Components/StatusBadge.vue`
- `resources/js/Components/TechnicalDetails.vue`
- `resources/js/presentation/labels.js`
- `resources/js/presentation/navigation.js`
- `tests/Frontend/presentation.test.js`
- `tests/Frontend/vueRender.mjs`

## FILES MODIFIED
- `resources/js/Pages/VendingMachines/Index.vue`
- `resources/js/Pages/VendingMachines/Show.vue`
- `resources/js/Pages/VendingFleet/Devices.vue`
- `resources/js/Pages/VendingFleet/Dashboard.vue`
- `resources/js/Pages/VendingFleet/Releases.vue`
- `resources/js/Pages/Employees/VendingCatalog.vue`
- `resources/js/Pages/Employees/Partials/StagedEmployeeImport.vue`
- `resources/js/Pages/Employees/importState.js`
- `resources/js/Layouts/AuthenticatedLayout.vue`
- `resources/js/Layouts/GuestLayout.vue`
- `resources/js/Pages/Auth/Login.vue`
- `resources/js/Components/InputError.vue`
- `resources/js/Pages/Auth/Register.vue`
- `resources/js/Pages/Auth/ForgotPassword.vue`
- `resources/js/Pages/Auth/ResetPassword.vue`
- `resources/js/Pages/Auth/VerifyEmail.vue`
- `resources/js/Pages/Auth/ConfirmPassword.vue`
- `resources/js/Pages/Profile/Edit.vue`
- `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue`
- `resources/js/Pages/Profile/Partials/UpdatePasswordForm.vue`
- `resources/js/Pages/Profile/Partials/DeleteUserForm.vue`
- `resources/css/app.css`
- `resources/js/Pages/Clocks/Index.vue`
- `resources/js/Pages/Companies/Index.vue`
- `resources/js/Pages/Units/Partials/UnitDetailDrawer.vue`
- `resources/js/Pages/Units/Partials/UnitCard.vue`
- `resources/js/app.js`
- `resources/js/Pages/Dashboard/CorporateRecruitment.vue`
- `resources/js/Pages/Settings/AuditCleanup/Index.vue`
- `resources/js/Pages/Dashboard.vue`

## ARCHIVO CONSERVADO POR TRASLADO
- resources/js/Pages/Dashboard.vue → resources/js/Pages/Dashboard/LegacyDashboard.vue (panel completo conservado, etiquetas de conectividad localizadas; Dashboard.vue ahora es la entrada).
- Los tres archivos de catálogo/importación de empleados ya existían sin seguimiento al iniciar: se modificaron, no se atribuye su creación a esta fase.

## RISKS
- HIGH: no certificar DEMO READY/producción sin validación visual y física aplicable; no hay evidencia nueva Android/offline.
- MEDIUM: suite completa mantiene un fallo heredado; recorridos de clics, foco, errores de red y revisión responsive pendientes.
- LOW: aviso de Browserslist/caniuse-lite desactualizado, sin actualizar dependencias en esta fase. Casos de textos extremadamente largos requieren observación.
- Herramientas heredadas conservan sus capacidades y filtros especializados; esta fase prioriza el recorrido operativo vending, sin rediseñar pantallas/motor de asistencia.

## DEMO
NOT_READY para certificación final. Implementación web y material preparados.
Seguir [demo-walkthrough.md](demo-walkthrough.md) (14 minutos) y completar [demo-checklist.md](demo-checklist.md) antes de presentar.
