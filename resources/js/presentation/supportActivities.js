// Presentation only: domain permissions, identity, transitions and GPS remain server-owned.
const labels = Object.freeze({
    ASSIGNED: 'Asignada', IN_PROGRESS: 'En progreso', COMPLETED: 'Completada', CANCELLED: 'Cancelada',
    INSIDE: 'Dentro de zona', OUTSIDE: 'Fuera de zona', UNCERTAIN: 'Ubicación imprecisa', NOT_EVALUATED: 'Sin validar',
});
const events = Object.freeze({ created: 'Actividad creada', assigned: 'Asignada', started: 'Iniciada', completed: 'Completada', cancelled: 'Cancelada',
    'support_activity.note_added': 'Nota agregada', 'support_activity.evidence_added': 'Evidencia agregada' });
export const activityStatuses = ['ASSIGNED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
export const activityAdvancedKeys = ['activity_type', 'vending_machine_id', 'employee_id', 'from', 'to', 'has_ticket', 'ticket_uuid'];
export const activityLabel = (value) => Object.hasOwn(labels, value) ? labels[value] : 'Sin información';
export const activityEventLabel = (value) => Object.hasOwn(events, value) ? events[value] : 'Evento de la actividad';
export function activityBadgeClass(value) {
    if (['COMPLETED', 'INSIDE'].includes(value)) return 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
    if (['IN_PROGRESS', 'OUTSIDE', 'UNCERTAIN'].includes(value)) return 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200';
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
}
export function activityDistance(value) {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) || Number(value) < 0) return 'Sin información';
    const meters = Number(value);
    return new Intl.NumberFormat('es-MX', { maximumFractionDigits: meters >= 1000 ? 1 : 0 }).format(meters >= 1000 ? meters / 1000 : meters) + (meters >= 1000 ? ' km' : ' m');
}
export function employeeAccessLabel(employee) {
    if (!employee?.has_account) return 'Este empleado aún no tiene acceso al sistema.';
    return employee.has_active_account ? 'Cuenta asociada habilitada.' : 'La cuenta asociada está desactivada.';
}
export const activityEmptyMessage = (filters = {}) => Object.values(filters).some(value => value !== '' && value !== null && value !== undefined)
    ? 'No encontramos actividades con estos filtros.' : 'No hay actividades de soporte registradas.';
export function activityOptionLabel(kind, row) {
    if (kind === 'employees') return `${row.employee_number ?? 'Sin número'} · ${row.full_name ?? 'Sin nombre'}`;
    if (kind === 'tickets') return `${row.folio} · ${row.title}`;
    const status = { ACTIVE: 'Activa', DRAFT: 'Borrador', INACTIVE: 'Inactiva', RETIRED: 'Retirada' };
    return [row.machine_code, row.name, status[row.status] ?? 'Sin estado', row.address_line].filter(Boolean).join(' · ');
}
