# Checklist de demostración

FASE 10 UX/DEMO: PARTIAL
VISUAL REVIEW: WAITING_FOR_FINAL_CONFIRMATION
DEMO: NOT_READY

El usuario realizó revisiones manuales externas de /vending, Dispositivos, Empleados y Catálogo SYBI con Pilot Operator y aprobó la dirección visual general. Las correcciones de esta ronda esperan una nueva revisión externa; no se declara PASS. No se intentará conectar navegador desde Codex. Las pruebas de renderizado Node NO sustituyen observación visual.

## Ronda de importación y detalle de máquina

El usuario aprobó la estructura actual de Catálogo SYBI, la interacción de empleados asignados y el sidebar sin elipsis. No se modifican esas áreas. La nueva corrección de concurrencia, el copy del import y la presentación de información SYBI/auditoría esperan confirmación final externa.

Evidencia y resultados: [import-sybi-evidence.md](import-sybi-evidence.md). Import real de 2,502 filas NO aplicado. Mojibake confirmado en origen: SOURCE_ENCODING_ISSUE; no se recodifica ni sobrescribe el source record.

## PRODUCT_DISPLAY_NAME — decisión pendiente

Se conserva el footer “Medical Life · Vending Attendance”. PRODUCT_DISPLAY_NAME: PENDING_PRODUCT_OWNER_CONFIRMATION. “Vending Attendance” se mantiene como nombre de producto vigente hasta confirmar su denominación oficial; no se traduce ni se cambia arbitrariamente.

## Ronda de correcciones del Resumen

- [ ] Verificar “Alertas activas” con N numérico real, desglose por severidad y acción “Revisar alertas”.
- El contador y desglose se calculan exclusivamente de la lista recibida. El servidor limita esa lista; no representan un total global garantizado ni se extrapolan a partir de dispositivos.
- [ ] Confirmar “Dispositivos habilitados”, manteniendo “Dispositivos conectados” y “Sin conexión”, sin alterar estados ni cifras.
- [ ] Al entrar, “Más indicadores de operación”, “Versiones y criterios de conexión” y “Detalle técnico” permanecen colapsados.
- [ ] Confirmar 180 / 600 / 300 segundos como 3 / 10 / 5 minutos. Valores internos intactos.
- [ ] Conservar “Lo que necesita atención”, severidad, título, descripción y detalle técnico cerrado.


## Ronda de correcciones: Dispositivos, Empleados, SYBI y sidebar

La revisión externa demuestra los defectos de texto, jerarquía y truncamiento de esta ronda. La comprobación posterior en 1920×1080 y 1366×768 sigue pendiente; no se certifica layout con pruebas Node.

- [ ] Dispositivos: “Estado administrativo” filtra el estado administrativo original; “Estado operativo” muestra la condición calculada original. Sin texto explicativo redundante.
- [ ] Sin conexión previa: “Aún no se ha conectado”. Las conexiones existentes conservan fecha y hora CDMX.
- [ ] Sincronización: dos líneas compactas, “Configuración · estado” y “Empleados · estado”; ambas incidencias permanecen visibles.
- [ ] Empleados: encabezado superior específico y único, subtítulo operacional y CTA “Importar empleados”. El wizard conserva CSV/XLSX.
- [ ] Fortia: un bloque compacto “Fortia · Modo de prueba” sólo cuando el driver recibido es mock; advertencia productiva según real_api_ready. “Consultar cambios” sólo con la capacidad existente. “Más información” cerrado, resultados y errores fuera del colapsable.
- [ ] Catálogo SYBI: encabezado, pestaña y textos de acción coherentes; “Máquina vinculada” muestra vínculo, código y estado originales.
- [ ] SYBI: “Última sincronización y resultados”, “Filtros avanzados” y “Detalle técnico” cerrados al entrar.
- [ ] Sidebar: revisar todos los elementos visibles de Operator y Viewer a ambas resoluciones. Subtítulos breves y ajuste de línea, sin elipsis; ancho y tooltips conservados.

Decisiones verificadas en código:

- Se usa “Catálogo SYBI” como etiqueta UX, coherente con la navegación existente. SYBIML sigue siendo el nombre de la fuente autoritativa en la documentación técnica; no se encontró una obligación de mostrar ese nombre completo en la interfaz. No se renombra el proveedor, la API ni la configuración.
- “Máquina vinculada” corresponde a sourceRecords.promoted_vending_machine, cargado como promotedVendingMachine:id,uuid,machine_code,status por VendingMachineController. El vínculo no implica que la máquina esté lista o en línea.
- Encabezados de pantallas: Resumen de operación, Dispositivos, Empleados, Máquinas vending, Catálogo SYBI y Versiones de aplicación. “Alertas” sigue siendo el acceso a #alertas dentro del Resumen, con “Lo que necesita atención”; no se inventa una pantalla ni ruta independiente.
- El sidebar conserva sus 18 rem de ancho expandido. Etiquetas, descripciones y nombres de grupo pueden ocupar varias líneas; no se oculta texto para simular que cabe.

## Antes de presentar
- [ ] Confirmar repositorio y URL local correcta; no producción.
- [ ] Conservar VM-DEMO-001/002/003 y seleccionar únicamente empleados sintéticos autorizados.
- [ ] Operador conoce su contraseña por un canal seguro; no ejecutar reset sin aprobación.
- [ ] No abrir .env, consola con secretos, códigos de activación ni URLs de artefactos durante la proyección.
- [ ] Fortia muestra “Fortia · Modo de prueba” cuando driver=mock.
- [ ] Aceptar explícitamente el alcance de lectura o autorizar por separado las escrituras demo.
- [ ] Revisar las fallas de OnPremDiagnosticsCommandTest y AuditCleanupModuleTest descritas en la evidencia de esta ronda; no llamar PASS a la suite completa.

## Estado real antes de iniciar la demo

Comprobar condiciones reales, sin alterar datos, cálculos de salud o estados para aparentar una operación saludable.

- [ ] Al menos un dispositivo de la demo está ONLINE (“En línea”); estar ACTIVE (“habilitado”) no basta.
- [ ] Configuración y empleados del dispositivo están SYNCED, con confirmaciones de sincronización reales.
- [ ] Hay 0 registros pendientes, salvo un escenario deliberado documentado previamente.
- [ ] Android está conectado a la red y al servidor correctos.
- [ ] La última conexión es reciente conforme a los criterios vigentes, sin modificar umbrales.
- [ ] Reservar la demostración OFFLINE para un escenario controlado posterior; registrar desconexión y reconexión reales.

Si una condición falla, resolver la causa operativa o declarar la limitación. Nunca fabricar actividad, sincronización, conectividad ni ausencia de pendientes.

## Revisión visual obligatoria
Repetir cada fila a 1366×768, 1920×1080 y tableta (768×1024 y 1024×768). Revisar claro/oscuro, zoom 200%, etiquetas largas, vacíos, errores y teclado.

| Pantalla | Escritorio 1366 | Escritorio 1920 | Tableta | Evidencia / responsable |
| --- | --- | --- | --- | --- |
| Login y recuperación | Pendiente | Pendiente | Pendiente | |
| Inicio / Dashboard | Pendiente | Pendiente | Pendiente | |
| Resumen / Fleet | Pendiente | Pendiente | Pendiente | |
| Empleados | Pendiente | Pendiente | Pendiente | |
| Importación, cinco pasos | Pendiente | Pendiente | Pendiente | |
| Máquinas y detalle | Pendiente | Pendiente | Pendiente | |
| Catálogo SYBI | Pendiente | Pendiente | Pendiente | |
| Dispositivos y detalle | Pendiente | Pendiente | Pendiente | |
| Versiones de aplicación | Pendiente | Pendiente | Pendiente | |

- [ ] Sin desbordamiento de la página; cualquier desplazamiento queda dentro de una tabla cuando sea necesario.
- [ ] Tab/Shift+Tab, foco visible, Enter/Espacio y Escape.
- [ ] Menú móvil: fondo no interactivo, foco contenido, cierre devuelve foco; al cruzar 1024 px no queda bloqueo de desplazamiento.
- [ ] Modal de máquina/importación: foco contenido y restaurado, no cerrar durante envío.
- [ ] Dispositivos: filtros avanzados cerrados al entrar; contador exacto; Buscar/Enter aplica; Limpiar elimina también filtros ocultos.
- [ ] Filtros de máquina usan ID interno esperado, pero muestran código; enums enviados permanecen intactos.
- [ ] Detalle conserva UUID, estado, versiones servidor/dispositivo, grupo, diferencia de hora y diagnóstico.
- [ ] Fechas operativas legibles en America/Mexico_City.
- [ ] Error de login incorrecto muestra “Correo o contraseña incorrectos.”, nunca auth.failed.
- [ ] Empleados: error de consulta de asignaciones ofrece reintento, no convierte el error en “sin asignaciones”.
- [ ] Importación: sin guardado de empleados antes de confirmar; Volver y archivo nuevo invalidan la confirmación.
- [ ] Importación: probar sin cambios, errores, duplicados, confirmación vencida y catálogo cambiado; revisar paso y foco.
- [ ] Códigos técnicos únicamente bajo detalle; sin passwords, hashes de contraseñas ni tokens en capturas.

## Roles
- [ ] Admin: administración vending disponible; no se ejecuta durante prueba visual sin autorización.
- [ ] Operator: importar, consultar Fortia y asignar; sin editar máquina, geocerca, dispositivo o versión.
- [ ] Support: lectura; sin botones mutables.
- [ ] Viewer: lectura; sin botones mutables.
- [ ] Ningún enlace de estos roles termina en 403 por falta de permiso.
- [ ] Logout sigue siendo POST.

## Validación automatizada
Comandos disponibles (PowerShell, rutas explícitas):
```powershell
php artisan test --filter=Vending
node --test tests/Frontend/employeeImport.test.js tests/Frontend/presentation.test.js tests/Frontend/externalVisualReview.test.js tests/Frontend/importMachineReview.test.js
npm run build
git diff --check
```
Para correcciones exclusivamente de frontend/presentación, no repetir la suite Laravel completa salvo una razón de regresión demostrada. En esta ronda sí se ejecutó php artisan test por el fix backend del import: 469 PASS / 2 FAIL; véase la evidencia enlazada.

- [ ] Registrar resultados finales y baseline conocido.
- [ ] ASISTENCIAS_FORTIA limpio.
- [ ] Sin cambios de APIs, RBAC, HMAC, enums, manifests, motor de asistencia ni Android.
- [ ] No commit, tag, push ni deploy.

## Android / física
- [ ] Equipo y versión previamente validados; no reconstruir Android por esta fase web.
- [ ] Captura offline autorizada conserva evidencia.
- [ ] Reconexión confirma recepción única del mismo evento.
- [ ] Responsable, hora y evidencia registrados sin secretos.

DEMO READY sólo tras completar revisión visual y física aplicable y resolver/aceptar formalmente los gates pendientes. Esta implementación no certifica producción.
