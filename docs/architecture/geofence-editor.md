# Editor visual de geocercas — Fase 13.5

Estado de aceptación visual: **PASS**, informado por el operador mediante revisión
externa final en 1920×1080 y 1366×768. Cierre Git autorizado, condicionado a sus controles
de seguridad, demo-preflight y datos protegidos. No se certifica GPS físico ni revisión
responsive móvil/tablet no incluida en esta aprobación. Sin push, deploy ni instalación de APK.

Base: `vending-phase-13-support-pass`, `2f39b503842464d1e1836af47168658bfaac00ab`.
Rama: `phase/13-5-geofence-editor`. Discovery: [inventario y decisiones](geofence-editor-discovery.md).

## Alcance y propiedad de los datos

Se reemplaza exclusivamente la sección de geocercas del detalle de Máquina. Se reutilizan
MachineGeofence, MachineGeofenceService, versionamiento, auditoría, permisos y manifests.
No hay modelo paralelo, GeoJSON persistido, polígonos ni migración.

| Dato | Propietario / tratamiento |
| --- | --- |
| Ubicación SYBI | Source record, sólo lectura; «Ubicación de la máquina» con rombo y tooltip de origen SYBI. Fallback a ubicación registrada de la proyección si no existe relación source. |
| Ubicación registrada no SYBI | VendingMachine, sólo lectura en este editor; «Ubicación de la máquina» con rombo. |
| Centro operacional y radio | Nueva versión de MachineGeofence; «Centro de la geocerca» con símbolo circular y radio dibujado. No sobrescribe la fuente ni la geometría histórica. |
| Ubicación consultada | Fix del operador, sólo memoria del componente; «Mi ubicación» con triángulo después de confirmación explícita. No asistencia ni persistencia automática. |
| Área, perímetro y separación respecto a SYBI | Cálculos aproximados de presentación; no se almacenan ni clasifican asistencias. |

Los símbolos, etiquetas y formas complementan el color. La interfaz distingue
«Centro ajustado manualmente» de la ubicación fuente y muestra la separación aproximada.
Identificadores, coordenadas avanzadas e historial quedan bajo Detalle técnico.
Las coordenadas también aparecen en preview y confirmación para identificar exactamente
qué centro cambia o qué ubicación se verifica.

## Escrituras, versiones y concurrencia

El formulario hace preview y después POST al workflow existente; cancelar no envía petición.
Editar crea otra versión, nunca actualiza centro/radio de una versión anterior.
Guardar como DRAFT conserva la configuración aplicada; sólo activar cambia la zona operacional.
Create y su activación opcional ahora comparten transacción. El bloqueo comienza por la máquina
en create/activate/deactivate; se mantiene la restricción de una geocerca ACTIVE.

El editor manda `expected_config_version`. El servicio compara bajo bloqueo y rechaza
una configuración obsoleta sin crear un borrador parcial. El campo es opcional para
llamadores existentes; el editor siempre lo utiliza. No protege contra la creación
concurrente de otro borrador, porque un borrador no altera la configuración operacional.

Activar conserva el workflow previo: sustituye la activa anterior, conserva su geometría,
cierra su vigencia y avanza config_version mediante MachineConfigurationVersionService.
Desactivar usa el estado existente INACTIVE, cierra vigencia y avanza configuración una vez;
repetir sin cambio no vuelve a incrementar. SUPERSEDED no puede reactivarse.

La propagación sigue siendo:

`SYNCED → cambio de configuración → PENDING → fetch → ACK APPLIED válido → SYNCED`

Fetch por sí solo no confirma aplicación. Vue no incrementa versiones ni marca Device
sincronizado. No se modificaron generación, hashes, endpoints, ACK, HMAC ni protocolo.

## Validación existente y verificación de ubicación

StoreMachineGeofenceRequest expone sus límites existentes como prop, sin duplicar una
política productiva en Vue: radio entero 1–100000 m; precisión nullable 0–100000 m;
tolerancia nullable 0–100000 m. WGS84 y exclusión de 0,0 se conservan.
Precisión vacía no inventa un requisito; radio inicial usa sólo el default ya registrado
en la máquina. Tolerancia null se normaliza a cero al crear porque la columna es NOT NULL
con default cero y el evaluador ya interpreta null como cero. Prueba específica contra el
fallo de almacenamiento. GeofenceValidationService no cambió.

«Verificar ubicación» exige confirmación y versión esperada. Verifica las coordenadas
registradas de VendingMachine con el validador existente, actualiza únicamente sus campos
coordinates_verified / coordinates_verified_at y deja al actor en la auditoría estándar.
No existe verified_by en el modelo y no se agregó otro campo. La actualización sigue el
versionamiento normal del modelo. No certifica presencia, precisión GPS, ni un centro
operacional desplazado. No modifica coordenadas source ni metadatos SYBI.

## Permisos y auditoría

VIEW usa `vending_machines.view`; create/activate/deactivate/verify requieren el permiso
existente `vending_machines.geofence`, manteniendo las equivalencias existentes de manage.
No se crean, conceden ni alteran permisos o roles. Vue oculta las acciones; backend aplica
el permiso y comprueba explícitamente que la geocerca corresponde a la máquina de la URL.

Rutas nuevas, dentro del grupo autenticado existente:

- PATCH `/vending-machines/{vendingMachine}/geofences/{geofence}/deactivate`.
- PATCH `/vending-machines/{vendingMachine}/verify-location`.

El observer conserva geofence.created, geofence.activated, geofence.superseded y
geofence.updated; añade geofence.deactivated. Un cambio geométrico equivale a creación de
nueva versión + sustitución de anterior al activar. Conserva valores y vínculo de máquina.
vending_machine.location_verified registra antes/después del flag/fecha; AuditLogger aporta
actor y fecha. No se registran tokens ni GPS transitorio del operador.

## Mapa y GPS

Dependencia única autorizada: Leaflet 1.9.4 (BSD-2-Clause), importación dinámica después de
«Cargar mapa». CSS empaquetado localmente. MapLibre se evaluó en discovery; WebGL/workers
no resultaron necesarios para este círculo 2D. Sin API key, facturación, CDN de scripts,
geocoder ni nuevo backend de mapas. [Referencia oficial de círculos Leaflet](https://leafletjs.com/reference.html#circle).

El mapa usa mosaicos HTTPS OpenStreetMap, atribución visible y política estándar de caché
del navegador. Sin descargas offline, precarga masiva ni proxy. La carga es opt-in y avisa
que el proveedor recibe IP y área consultada; no envía nombres, eventos o credenciales.
Se conserva el Referer con strict-origin-when-cross-origin. El servicio público no ofrece
SLA; otra escala de uso exige revisar proveedor/capacidad. [Política oficial de mosaicos](https://operations.osmfoundation.org/policies/tiles/).

Interacción: arrastre del centro de la geocerca, clic/tap, selección del centro del mapa por teclado, radio y
botones ±1 m. Campos numéricos alternativos disponibles si faltan mosaicos. El mapa avisa
cuando una latitud excede Mercator; no recorta el valor WGS84 que se guarda. NoWrap evita
copias mundiales; el dibujo es una guía, no otro motor de evaluación.

GPS web sólo se solicita por botón, con contexto seguro, permiso, maximumAge=0, alta
precisión y plazo de 15 s; se rechazan fixes anteriores a la solicitud, futuros o inválidos.
Primero se muestra precisión; «Aplicar como centro» sólo cambia el borrador. Guardar sigue
requiriendo preview y otra acción. No se cambiaron headers ni .env para permitir GPS.
LAN HTTP puede bloquear GPS aunque la ubicación nativa Android funcione; mapa y campos
siguen disponibles. GPS real sigue pendiente de prueba externa.

## Mobile mínimo, sólo lectura

Información de la terminal incorpora zona local, radio, consulta explícita de distancia,
estado en español, precisión y hora de la consulta. Lee getAttendanceContext; requiere
configuración local completa. No presupone que el servidor esté actualizado.
GeofenceDiagnosticService usa LocationProvider y GeofenceValidationService existentes;
si cambia la configuración durante el fix descarta el resultado. No crea eventos,
verificaciones, tickets, llamadas de escritura API ni elementos de outbox.
Sin edición móvil, dependencia nueva, modificación nativa, cap sync o instalación de APK.

## Evidencia automatizada y límites

| Validación | Resultado |
| --- | --- |
| Frontend web | 113 PASS; incluye 10 regresiones adicionales de mapa/correcciones visuales |
| Laravel Feature/Vending | 183 PASS, 1286 assertions; 11 nuevos tests |
| Laravel completa | Referencia de implementación inicial: 643 PASS / 1 FAIL, 4876 assertions; única falla heredada OnPremDiagnosticsCommandTest:54 |
| Mobile | Referencia inicial: 258 PASS en 30 archivos; sin cambios móviles en las correcciones visuales |
| Web build | PASS, 878 módulos; Leaflet dinámico ~150 kB (~44 kB gzip) |
| Mobile build | PASS, TypeScript + Vite |
| Pint scoped / diff check | PASS |

La suite completa incluye regresiones de asistencia, geofence, Device, manifests y SYBI.
Los tests nuevos usan fixtures sintéticos SQLite :memory:. No se corrigió ni excluyó la
falla heredada. Los tests web usan SSR y un host Vue con mapa simulado: no certifican
mosaicos, arrastre nativo ni dimensiones de un navegador físico.

El audit npm reportó 14 avisos en paquetes ya presentes (1 low, 2 moderate, 9 high,
2 critical), ninguno atribuido a Leaflet. El lock sólo incorpora Leaflet; no se ejecutó
audit fix ni actualizaciones fuera de alcance. Es deuda pendiente, no certificación
de seguridad productiva. Los builds conservan avisos de Browserslist y, en mobile,
Tailwind/Ionic/chunks grandes. No se ocultan como PASS de producción.

Control final read-only: asistencia = 19; source SYBI 7 único, proyección DRAFT, sin
geocercas, Device ni assignments. ASISTENCIAS_FORTIA limpio. Sin cambios de datos reales.
GeofenceValidationService (PHP/mobile), asistencia, HMAC, manifiestos, lifecycle, health,
RBAC y contratos SYBI/Fortia permanecen sin modificación.

## Inventario de implementación

Creado: este documento, discovery y runbook; GeofenceEditor.vue, GeofenceMap.vue,
presentation/geofenceEditor.js, GeofenceEditorTest.php, geofenceEditor.test.js;
mobile TerminalGeofence.vue, GeofenceDiagnosticService.ts, geofence-diagnostic.spec.ts.
Las correcciones visuales añaden tests/Frontend/geofenceMap.test.js.

Modificado: MachineGeofenceController.php, VendingMachineController.php,
StoreMachineGeofenceRequest.php, MachineGeofenceObserver.php, MachineGeofenceService.php,
routes/web.php, VendingMachines/Show.vue, presentation/audit.js,
tests/Frontend/presentation.test.js, package.json, package-lock.json y mobile HomePage.vue.

La skill design-web-frontends orientó la reutilización de componentes/tokens, permisos,
detalle técnico colapsado, confirmaciones, foco y controles táctiles. No equivale a una
revisión visual. Continuar con el [runbook de revisión externa](../operations/geofence-management-runbook.md).

## Correcciones tras revisión externa — mapa con geocerca activa

Evidencia del operador: una máquina sin geocerca carga, mientras VM-DEMO-001 con círculo
activo de 50 m cae en el mensaje genérico de inicialización. La reproducción automatizada
confirmó la causa en GeofenceMap.fit: se construía otro L.circle y se llamaba getBounds
antes de añadirlo al mapa. Leaflet 1.9.4 requiere _map/_point proyectados y lanza
TypeError al leer layerPointToLatLng de un mapa inexistente. El catch desmontaba el mapa
correctamente iniciado; sin círculo no se ejecutaba ese camino.

La prueba roja ejecutó el código real instalado de Circle._project/getBounds y confirmó
el mismo TypeError desde el componente Vue. El host DOM y el montaje visual son simulados,
no se abre navegador ni se solicitan mosaicos. Después de corregir fit para reutilizar
overlays.getBounds de las capas ya montadas, pasa el círculo de 50 m, sus dos marcadores,
la vista sin geocerca y el cambio a otra geometría. No se cambió radio ni coordenadas.
No fue necesario cambiar HTTP, CORS, proveedor, IDs de contenedor, algoritmo ni datos.

El error real ahora conserva la configuración y ofrece Reintentar. Se limpian instancia
y ResizeObserver; la carga doble y el desmontaje durante importación quedan controlados.
Los tests cubren fallo antes/después del montaje, reintento, error de mosaicos sin
desmontar el mapa y montaje de otra instancia. La carga externa continúa siendo opt-in.
La leyenda muestra nombres completos sólo para elementos existentes, sin códigos S/C;
«Ver geocerca completa» mantiene el encuadre conjunto (sin centro: «Ver ubicaciones»).
El encabezado de origen muestra SYBI únicamente si machine.source es SYBI.

Sólo frontend, pruebas y documentación cambiaron en esta corrección. Frontend 113 PASS,
Vending 183 PASS y build web PASS. No se repitieron mobile, Pint ni Laravel completo
porque no hubo cambios móviles/PHP respecto de la implementación ya validada.
Responsive conserva estructura y altura acotada; la revisión real en 1920×1080 y
1366×768 sigue pendiente. No se crea checkpoint antes de esa evidencia.

## Aprobación externa final y autorización de checkpoint

La revisión pendiente descrita arriba es evidencia histórica de la implementación.
El operador confirmó posteriormente PASS manual en 1920×1080 y 1366×768:
VM-DEMO-001 carga, círculo activo de 50 m visible, leyenda clara, acción de encuadre
funcional, sin error de inicialización, origen SYBI/DEMO correcto, sin overflow ni
clipping y consentimiento OSM preservado. No guardó cambios y SYBI 7 sigue RESERVED.
Esta declaración externa es la evidencia visual de cierre; no se atribuye a un navegador
controlado por Codex ni a pruebas de GPS o escritura de geocercas.

Se autoriza el commit feat(geofence): add visual vending geofence editor y el tag
vending-phase-13-5-geofence-pass sólo si pasan los controles finales. La siguiente
rama será phase/14-biometric-engine-selection exactamente desde el tag, sin implementar
Fase 14. Los avisos npm y la falla Laravel heredada documentados permanecen fuera de alcance.
