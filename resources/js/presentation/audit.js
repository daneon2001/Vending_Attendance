// Presentation only. Keys match actual observers/services; never infer from a generic action.
// A version change or bootstrap response does not prove device synchronization.
export const auditEventLabels = Object.freeze({
 'vending_machine.created': 'Máquina registrada',
 'vending_machine.updated': 'Máquina actualizada',
 'vending_machine.activated': 'Máquina habilitada',
 'vending_machine.deactivated': 'Máquina deshabilitada',
 'vending_machine.retired': 'Máquina retirada',
 'vending_machine.sybi_created': 'Máquina incorporada desde SYBI',
 'vending_machine.sybi_updated': 'Información de máquina actualizada desde SYBI',
 'vending_machine.coordinates_changed': 'Coordenadas de origen actualizadas',
 'vending_machine.sybi_missing': 'Máquina ausente en la consulta SYBI',
 'geofence.created': 'Geocerca creada',
 'geofence.updated': 'Geocerca actualizada',
 'geofence.activated': 'Geocerca activada',
 'geofence.superseded': 'Versión de geocerca sustituida',
 'geofence.review_required': 'Geocerca requiere revisión',
 'assignment.created': 'Empleado asignado',
 'assignment.updated': 'Asignación actualizada',
 'assignment.revoked': 'Asignación revocada',
 'employee_manifest.version_changed': 'Versión de la lista de empleados actualizada',
 'device.activated': 'Dispositivo habilitado',
 'device.suspended': 'Dispositivo suspendido',
 'device.revoked': 'Acceso del dispositivo revocado',
 'device.retired': 'Dispositivo retirado',
 'device.provisioned': 'Dispositivo activado',
 'device.bootstrap': 'Configuración inicial consultada',
 'device.release_channel_updated': 'Canal de distribución actualizado',
 'device.provisioning_token.created': 'Código de activación generado',
 'device.provisioning_token.revoked': 'Código de activación revocado',
});

export function auditEventLabel(event) {
 return typeof event === 'string' && Object.prototype.hasOwnProperty.call(auditEventLabels, event)
  ? auditEventLabels[event] : 'Actividad registrada';
}

const subjectLabels = Object.freeze({
 VendingMachine: 'Máquina vending', MachineGeofence: 'Geocerca',
 EmployeeMachineAssignment: 'Asignación de empleado', Device: 'Dispositivo',
 Employee: 'Empleado', User: 'Usuario', Role: 'Rol',
});
export function auditSubjectLabel(type) {
 const name = typeof type === 'string' ? type.split('\\').at(-1) : '';
 return Object.prototype.hasOwnProperty.call(subjectLabels, name) ? subjectLabels[name] : 'Registro';
}
