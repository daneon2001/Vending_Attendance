# Fase 10 — Diagnóstico UX previo a implementación

Fecha: 2026-09-06. Repositorio: vending-attendance. Baseline aprobado: vending-phase-9-pass.
Se preservan los cambios de integración de empleados y reset piloto ya presentes. No se ejecuta el reset.

## Evidencia
- Inventario estático de resources/js (páginas, layouts y componentes), búsquedas transversales de códigos, fechas, tablas, filtros y permisos; lectura detallada de los recorridos Vending y autenticación.
- Devices: 11 filtros abiertos, 13 columnas, ancho mínimo 105 rem; códigos de salud, versiones y fechas ISO visibles.
- Fleet: 16 indicadores simultáneos; alertas limitadas por el servidor (su longitud NO es el total de incidencias).
- Máquinas y SYBI: 10 columnas / 88 rem, filtros sin etiquetas, acciones de edición/sincronización visibles sin verificar capacidad; backend sí exige create/update/manage.
- Detalle de máquina: activación, geocercas, asignaciones y cambios de dispositivo visibles sin permiso; tablas técnicas de hasta 11 columnas / 92 rem.
- Versiones: tres formularios administrativos abiertos; valores de catálogo y términos en inglés.
- Empleados: origen y fecha crudos; Fortia de prueba oculto para lectores; importación con seis rótulos simultáneos, sin navegación por pasos.
- Login/layout invitado: referencias heredadas a biométricos/clínicas y cifras/promesas no respaldadas. InputError muestra auth.failed directamente.
- Navegación: mezcla operación, catálogos heredados y administración; dashboard/clocks sin filtro; empleados exige permiso estricto, otros módulos admiten settings.manage.
- Componentes base: Tailwind/Vue/Inertia, tokens CSS, foco visible y Modal nativo existentes. Reutilizarlos sin dependencias.
- Vistas heredadas: localización española mayoritaria; conectividad aún dice Heartbeat, algunas fechas dependen de la zona del navegador. Mantener sus capacidades sin cambiar asistencia.
- Navegador: lista vacía. No hubo observación visual. MANUAL_VISUAL_REVIEW_REQUIRED.

## Clasificación
| Tipo | Ejemplos | Tratamiento |
| --- | --- | --- |
| USER_VISIBLE | Device Registry, Health, auth.failed, etiquetas, estados | Español centralizado |
| TECHNICAL_INTERNAL | UUID, SHA-256, códigos de diagnóstico | Sólo detalle técnico o campo administrativo identificado |
| API_CONTRACT | release_channel, preview_hash, rutas y payloads | Intactos |
| DB_ENUM | ACTIVE, READY, PREVIEW, DEVICE | Conservar value; traducir sólo etiqueta |
| LOG_ONLY | event, reason, error_code | No mutar logs; presentación explicativa, código en detalle |

## Decisiones y límites
- Navegación por tareas sobre rutas actuales; asignaciones y geocercas permanecen dentro de cada máquina.
- Dispositivos: tres filtros frecuentes, ocho avanzados colapsados y siete columnas; resto en detalle expandible.
- Métricas: usar exclusivamente kpis existentes; alertas se presentan como lista disponible, nunca como total global.
- No incorporar props, consultas de negocio, endpoints, permisos, datos ni banner que infiera entorno sin evidencia.
- El catálogo de empleados no contiene máquinas asignadas. Facilitar acceso al catálogo de máquinas, sin inventar recuentos ni simular asignaciones.
- No modificar API, HMAC, enums, asistencia, manifests, Fortia/SYBI, biometría ni Android.
- La revisión visual de pantallas, roles y tres tamaños permanece requisito manual para certificación.

## Confirmaciones posteriores al diagnóstico
- Se verificó un endpoint existente de lectura de asignaciones: GET /api/v1/employees/{employee}/vending-machines. La UI lo consulta bajo permiso estricto vending_machines.view. No hubo nuevo endpoint ni cambio del contrato.
- Se preservó el panel anterior moviéndolo a Dashboard/LegacyDashboard.vue; el inicio piloto ofrece el acceso al resumen. No se montan sus consultas heredadas para roles piloto sin permiso dashboard.
- Los formularios de geocerca/asignación y administración de versiones usan divulgación progresiva. El menú móvil reutiliza el diálogo nativo para foco y fondo no interactivo, y cierra al cruzar 1024 px.
- Welcome.vue es la plantilla de Laravel sin ruta activa en routes; no forma parte de la UI visible. Las pantallas heredadas mantienen sus módulos, filtros y campos, con correcciones acotadas de etiquetas/fechas. No se rediseñó el motor ni las pantallas de asistencia.
