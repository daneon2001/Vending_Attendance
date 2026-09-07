export function canUse(matrix, module, action = 'view', strict = false) {
 const actions = matrix?.[module] ?? [];
 return actions.includes(action) || actions.includes('manage') || (!strict && (matrix?.settings ?? []).includes('manage'));
}
const item = (label, routeName, module, description, icon = 'dashboard', extra = {}) => ({ label, routeName, description, icon, requiredPermission: { module, action: 'view' }, ...extra });
export const navigationGroups = [
 { key:'operacion', title:'OPERACIÓN', items:[
 item('Resumen','vending-fleet.dashboard','vending_machines','Estado de la operación'),
 item('Dispositivos','vending-devices.index','vending_machines','Conexión y estado','clocks'),
 item('Alertas','vending-fleet.dashboard','vending_machines','Incidencias','audit',{hash:'alertas'}),
 item('Asistencias','admin.asistencias.index','asistencias','Consultar registros','attendance'),
 item('Tarjetas de asistencia','attendance-cards.index','asistencias','Consulta por empleado','attendance'),
 ]},
 {key:'personal',title:'PERSONAL',items:[item('Empleados','vending-employees.index','employees','Catálogo e importación','users',{strict:true})]},
 {key:'vending',title:'VENDING',items:[
 item('Máquinas','vending-machines.index','vending_machines','Asignaciones y geocercas','machines'),
 item('Catálogo SYBI','vending-machines.index','vending_machines','Información de origen','machines',{params:{catalog_view:'sybi'}}),
 ]},
 {key:'administracion',title:'ADMINISTRACIÓN',items:[
 item('Versiones de aplicación','vending-releases.index','vending_machines','Versiones y distribución','settings'),
 item('Configuración','settings.index','settings','Preferencias y acceso','settings'),
 item('Roles y permisos','settings.roles.page','settings','Administrar acceso','users'),
 item('Usuarios','settings.users.page','users','Cuentas de acceso','users'),
 item('Auditoría','settings.audit.page','audit','Historial de actividad','audit'),
 ]},
 {key:'herramientas',title:'OTRAS HERRAMIENTAS',items:[
 item('Panel general','dashboard','dashboard','Indicadores anteriores','dashboard',{params:{view:'legacy'}}),
 item('Corporativo y reclutamiento','dashboard.corporativo-reclutamiento','dashboard','Consulta de unidades'),
 item('Relojes','clocks.index','clocks','Catálogo de relojes','clocks'),
 item('Empresas','companies.index','companies','Catálogo de empresas','companies'),
 item('Unidades','units.index','units','Catálogo de unidades','branches'),
 ]},
];
export function visibleNavigation(matrix) {
 return navigationGroups.map(group => ({...group, items:group.items.filter(item => canUse(matrix, item.requiredPermission.module, item.requiredPermission.action, item.strict))})).filter(group => group.items.length);
}
