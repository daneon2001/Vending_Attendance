# Demostración Vending Attendance — 10 a 15 minutos

## Preparación
Usar exclusivamente vending-attendance local y datos piloto autorizados. Conservar VM-DEMO-001/002/003.
Esta guía NO certifica producción, Fortia real ni Android físico. No se ejecutaron importaciones, asignaciones, cambios de versiones ni reset de contraseñas reales en la fase UX.
Completar primero demo-checklist.md. El operador introduce sus credenciales fuera de la proyección; no escribirlas en esta guía, terminal, capturas ni grabación.

## Recorrido (14 minutos)
| Tiempo | Pantalla / acción | Qué mostrar y explicar | Resultado esperado | Qué evitar |
| --- | --- | --- | --- | --- |
| 0:00–1:00 | Login Pilot Operator | Medical Life, Vending Attendance. Inicio → Abrir resumen de operación. | Sesión autorizada y menú por tareas. | Contraseña visible, consola, gestores de credenciales. |
| 1:00–2:15 | Resumen | Máquinas operativas, dispositivos en línea, empleados distintos asignados, registros recibidos hoy y pendientes reportados. | Cifras reales del servidor; explicar que son estado actual, no historial completo. | Inventar actividad cuando hay cero datos; tratar la lista limitada de alertas como un total global. |
| 2:15–3:15 | Máquinas | Buscar VM-DEMO-001; ubicación, estado, geocerca, asignaciones activas; abrir detalle. | Datos de la máquina autorizada. | Editar ubicación, radio o estado durante una demo de lectura. |
| 3:15–4:15 | Catálogo SYBI | Comparar catálogo de origen y operación. Mostrar ubicación incompleta / identificador duplicado si realmente existen. | Mensajes comprensibles; registros incompletos no aparentan estar operativos. | Afirmar sincronización real sin evidencia; Operator no sincroniza SYBI. |
| 4:15–5:15 | Empleados | Número, nombre, estado, origen, Ver asignaciones. Señalar “Integración Fortia en modo de prueba”. | Máquinas vigentes consultadas al solicitarlas, sin modificar datos. | Afirmar conexión productiva Fortia; mostrar empleados reales no autorizados. |
| 5:15–7:45 | Importar CSV/XLSX | Archivo sintético aprobado → columnas → validación → confirmación → resultado. Explicar nuevos, actualizados, sin cambios, errores y duplicados. Volver no guarda empleados. | Se crea una vista previa temporal al cargar; empleados sólo cambian con confirmación explícita. | Aplicar sin autorización sobre el dataset; usar información personal real; confundir staging con cero escrituras. |
| 7:45–8:45 | Asignación | Máquinas → detalle → Empleados asignados. Operator puede abrir Asignar empleado. | Selección y vigencia visibles. Si hay aprobación explícita, asignar sólo un empleado sintético a una máquina demo y verificar. | Suponer que importar crea asignaciones; crear una asignación sólo para llenar la pantalla. |
| 8:45–10:00 | Dispositivos | Tres filtros principales, abrir avanzados, aplicar uno y limpiar. Ver detalle de un dispositivo. | Siete columnas; estado de activación separado de estado operativo; datos de sincronización legibles. | Abrir códigos de activación, exponer credenciales, cambiar canal o grupo. |
| 10:00–11:15 | Android existente | En el equipo previamente validado, mostrar la máquina vinculada y el flujo autorizado. | Corresponde al dispositivo consultado en web. | Reinstalar, reconstruir, activar otro dispositivo o afirmar validación física desde pruebas web. |
| 11:15–13:00 | Asistencia sin conexión / reconexión | Sólo con equipo y sesión de prueba autorizados: desconectar red, capturar evento sintético, observar pendiente, reconectar. | Evidencia del mismo evento recibida una vez; la web refleja el nuevo estado cuando el dispositivo lo reporta y se recarga. | Inventar resultados, borrar originales, confundir demora de reporte con pérdida. Si no hay equipo, describir el caso y marcar PENDIENTE. |
| 13:00–14:00 | Alertas | Volver a Resumen → Lo que necesita atención. | Estado real disponible, incluidos vacíos honestos. | Fabricar fallas, modificar umbrales o afirmar ausencia total de incidencias a partir de una lista limitada. |

## Roles y pantallas complementarias
- Admin: puede crear/editar máquinas, administrar geocercas/dispositivos/versiones y sincronizar SYBI, además de empleados/asignaciones.
- Operator: empleados, importación/consulta Fortia y asignaciones; sin administración de máquinas/geocercas/dispositivos/versiones.
- Support y Viewer: lectura en los módulos vending disponibles; no importación, sincronización ni mutaciones.
- Las versiones se pueden consultar por los cuatro roles. Formularios de administración y bloqueo sólo para Admin.
- Asistencias del sistema anterior, auditoría y configuración sólo aparecen con sus permisos reales; no ampliar acceso del piloto.
- “Más indicadores” y “Detalle técnico” conservan diagnósticos sin saturar el recorrido principal.
- Si Fortia no permite escritura, mostrar únicamente consulta de cambios; no activar flags para la demo.
- La captura Android/offline debe registrar evidencia y responsable aparte. Esta fase no la ejecutó.

## Cierre
Reportar lo observado, las validaciones pendientes y el modo de prueba. No declarar producción lista ni certificar geocerca, biometría, asistencia física o reconexión si no se comprobaron.
