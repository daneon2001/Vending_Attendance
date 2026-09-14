// Synthetic rendering fixtures only. Never bootstrap Laravel, load .env or connect to a database.
export const machine = {
    id: 90001, uuid: '10000000-0000-4000-8000-000000000001', machine_code: 'VM-DEMO-001',
    name: 'Máquina DEMO de revisión visual', status: 'ACTIVE', source: 'DEMO', coordinate_source: 'MANUAL',
    address_line: 'Dirección sintética del entorno visual', municipality: 'Municipio DEMO', locality: 'Zona DEMO',
    postal_code: null, latitude: null, longitude: null, coordinates_verified: false,
    geofences: [], assignments: [], devices: [], provisioning_tokens: [], config_version: 1,
};
export const employee = {
    id: 90001, employee_number: 'DEMO-VISUAL-001', full_name: 'Técnico DEMO Uno', status: 'ACTIVE',
    has_account: true, has_active_account: true,
};
export const ticket = {
    uuid: '20000000-0000-4000-8000-000000000001', folio: 'INC-DEMO-000001',
    title: 'Diagnóstico DEMO de dispensación', description: 'Reporte sintético; no representa una incidencia real.',
    machine: { code: machine.machine_code, name: machine.name }, device: { name: 'Equipo DEMO' },
    category: 'HARDWARE', severity: 'MEDIUM', priority: 'NORMAL', status: 'OPEN', source: 'MANUAL_WEB',
    assignee: { id: 90002, name: 'Pilot Support · fixture' }, reported_at: '2026-09-08T15:00:00Z',
    updated_at: '2026-09-08T17:00:00Z', location_available: false,
};
export const types = [
    { value: 'MAINTENANCE', label: 'Mantenimiento' }, { value: 'DIAGNOSTIC', label: 'Diagnóstico' },
    { value: 'CONFIGURATION', label: 'Configuración' }, { value: 'INSTALLATION', label: 'Instalación' },
];
export const activities = ['ASSIGNED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'].map((status, i) => ({
    uuid: '30000000-0000-4000-8000-00000000000' + (i + 1), folio: 'ACT-' + String(i + 1).padStart(6, '0'),
    title: ['Revisión preventiva del equipo DEMO', 'Diagnóstico del módulo de dispensación',
        'Configuración y comprobación de parámetros', 'Instalación reprogramada del equipo DEMO'][i],
    description: 'Actividad sintética para revisar presentación, permisos visibles e historial. No modifica datos reales.',
    activity_type: types[i].value, activity_type_label: types[i].label, status,
    created_at: '2026-09-08T15:00:00Z', started_at: i === 1 || i === 2 ? '2026-09-08T16:00:00Z' : null,
    completed_at: i === 2 ? '2026-09-08T17:00:00Z' : null,
    cancelled_at: i === 3 ? '2026-09-08T15:30:00Z' : null,
    cancellation_reason: i === 3 ? 'Se reprogramó la visita DEMO. Se conserva el historial.' : null,
    machine, employee: { ...employee, has_account: i !== 0, has_active_account: i !== 0 },
    ticket: i === 1 || i === 2 ? { uuid: ticket.uuid, folio: ticket.folio } : null,
    geofence_result: i === 1 || i === 2 ? 'INSIDE' : 'NOT_EVALUATED',
    presence_policy: 'FIELD_PHYSICAL_V1',
    snapshot: { result: i === 1 || i === 2 ? 'INSIDE' : 'NOT_EVALUATED',
        distance_m: i === 1 || i === 2 ? 12.6 : null, accuracy_m: i === 1 || i === 2 ? 8 : null,
        version: i === 1 || i === 2 ? 1 : null, evaluated_at: i === 1 || i === 2 ? '2026-09-08T16:00:00Z' : null },
}));
export function eventsFor(activity) {
    const kinds = ['created', 'assigned'];
    if (activity.started_at) kinds.push('started');
    if (activity.completed_at) kinds.push('completed');
    if (activity.cancelled_at) kinds.push('cancelled');
    return kinds.map((kind, i) => ({ id: i + 1, kind, actor: i < 2 ? 'Pilot Support · fixture' : employee.full_name,
        occurred_at: ({ started: activity.started_at, completed: activity.completed_at, cancelled: activity.cancelled_at })[kind] || activity.created_at }));
}
// Support actions match SupportPermissionsSeeder::MATRIX. No runtime role/user creation.
export const profiles = {
    admin: { name: 'Pilot Admin · fixture', permissions: { support: ['manage'], vending_machines: ['manage'], employees: ['view'], audit: ['view'] } },
    support: { name: 'Pilot Support · fixture', permissions: { support: ['view', 'view_all', 'comment', 'assign', 'resolve', 'verify'], vending_machines: ['view'] } },
    viewer: { name: 'Pilot Viewer · fixture', permissions: { support: ['view', 'view_all'], vending_machines: ['view'] } },
    none: { name: 'Sin permiso · fixture', permissions: {} },
};
