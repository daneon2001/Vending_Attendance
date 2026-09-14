import test from 'node:test';
import assert from 'node:assert/strict';
import { reactive, createVNode } from 'vue';
import { activityBadgeClass, activityDistance, activityEmptyMessage, activityEventLabel, activityLabel, activityOptionLabel, employeeAccessLabel } from '../../resources/js/presentation/supportActivities.js';
import { renderVue } from './vueRender.mjs';

const permissions = { support: ['view', 'assign'] };
const activity = {
    uuid: 'internal-private-uuid', folio: 'ACT-000042', title: 'Revisar pantalla', description: 'Sólo fixture',
    status: 'ASSIGNED', activity_type: 'MAINTENANCE', activity_type_label: 'Mantenimiento',
    machine: { machine_code: 'VM-042', name: 'Recepción', address_line: 'Dirección de prueba' },
    employee: { employee_number: 'TEST-042', full_name: 'Técnico de prueba', has_account: false, has_active_account: false },
    ticket: { uuid: 'private-ticket-uuid', folio: 'INC-2026-000001' },
    created_at: '2026-09-09T02:15:00Z', started_at: null, completed_at: null, cancelled_at: null,
    geofence_result: 'NOT_EVALUATED', snapshot: { result: 'NOT_EVALUATED', evaluated_at: null, version: null, distance_m: null, accuracy_m: null },
    presence_policy: 'FIELD_PHYSICAL_V1',
};
const index = { activities: { data: [activity], total: 1, last_page: 1, links: [] }, filters: {}, types: [{ value: 'MAINTENANCE', label: 'Mantenimiento' }], canCreate: true, unavailable: null };
const detail = { activity, canCancel: true, events: [
    { id: 1, kind: 'created', actor: 'Coordinación', occurred_at: activity.created_at },
    { id: 2, kind: 'assigned', actor: 'Coordinación', occurred_at: activity.created_at },
] };
const primaryText = html => html.replace(/<details\b[\s\S]*?<\/details>/g, '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');

test('field notes/evidence are escaped, private routed and distinguish capture from receipt', async () => {
    const html = await renderVue('resources/js/Pages/Support/Activities/Show.vue', { ...detail, activity: { ...activity,
        notes: [{ uuid: 'note-fixture', body: '<img onerror=alert(1)>', author: 'Técnico', captured_at: activity.created_at, received_at: activity.created_at }],
        evidence: [{ uuid: 'photo-fixture', author: 'Técnico', captured_at: activity.created_at, received_at: activity.created_at }],
    } }, permissions);
    assert.match(html, /Notas de campo|Evidencias de campo/);
    assert.match(html, /&lt;img/); assert.doesNotMatch(html, /<img onerror/);
    assert.match(html, /Capturada:|Recibida:/); assert.match(html, /support.activities.evidence/);
    assert.doesNotMatch(html, /storage\/|data:image|base64|v-html/);
    assert.equal(activityEventLabel('support_activity.note_added'), 'Nota agregada');
    assert.equal(activityEventLabel('support_activity.evidence_added'), 'Evidencia agregada');
});

test('Spanish activity and geofence labels preserve enum values and never expose unknown codes', () => {
    for (const [key, label] of Object.entries({ ASSIGNED: 'Asignada', IN_PROGRESS: 'En progreso', COMPLETED: 'Completada', CANCELLED: 'Cancelada', INSIDE: 'Dentro de zona', OUTSIDE: 'Fuera de zona', UNCERTAIN: 'Ubicación imprecisa', NOT_EVALUATED: 'Sin validar' })) assert.equal(activityLabel(key), label);
    for (const key of ['INTERNAL', '__proto__', 'constructor']) { assert.equal(activityLabel(key), 'Sin información'); assert.equal(activityEventLabel(key), 'Evento de la actividad'); }
    assert.equal(activity.status, 'ASSIGNED');
    assert.match(activityBadgeClass('OUTSIDE'), /amber/);
    assert.match(activityBadgeClass('COMPLETED'), /emerald/);
});

test('distance and account labels distinguish zero, missing GPS, inactive and absent accounts', () => {
    assert.equal(activityDistance(0), '0 m'); assert.equal(activityDistance(43.2), '43 m');
    assert.equal(activityDistance(1250), '1.3 km'); assert.equal(activityDistance(20962.85), '21 km');
    for (const value of [null, undefined, '', NaN, -1]) assert.equal(activityDistance(value), 'Sin información');
    assert.equal(employeeAccessLabel({ has_account: false }), 'Este empleado aún no tiene acceso al sistema.');
    assert.match(employeeAccessLabel({ has_account: true, has_active_account: false }), /desactivada/);
    assert.match(employeeAccessLabel({ has_account: true, has_active_account: true }), /habilitada/);
});

test('list uses scoped totals, friendly folios, compact columns, CDMX dates and collapsed filters', async () => {
    const html = await renderVue('resources/js/Pages/Support/Activities/Index.vue', index, permissions);
    assert.match(html, /Nueva actividad|ACT-000042/); assert.match(html, /INC-2026-000001/);
    assert.match(html, /20:15/); assert.match(html, /1 actividades en esta consulta/);
    assert.match(html, /Filtros avanzados/); assert.doesNotMatch(html, /<details[^>]*\bopen(?:[\s=>])/);
    assert.equal((html.match(/scope="col"/g) ?? []).length, 4);
    assert.match(html, /xl:table-cell/); assert.match(html, /table-fixed/);
    assert.doesNotMatch(primaryText(html), /internal-private-uuid|ASSIGNED|NOT_EVALUATED|private-ticket-uuid/);
});

test('empty and filtered empty states are distinct and unavailable is never presented as empty data', async () => {
    assert.equal(activityEmptyMessage({}), 'No hay actividades de soporte registradas.');
    assert.equal(activityEmptyMessage({ status: 'ASSIGNED' }), 'No encontramos actividades con estos filtros.');
    for (const filters of [{}, { search: 'Sin coincidencias' }]) {
        const html = await renderVue('resources/js/Pages/Support/Activities/Index.vue', { ...index, filters, activities: { data: [], total: 0 }, canCreate: false }, { support: ['view'] });
        assert.ok(html.includes(activityEmptyMessage(filters))); assert.doesNotMatch(html, /<table|Nueva actividad/);
    }
    const unavailable = await renderVue('resources/js/Pages/Support/Activities/Index.vue', { ...index, activities: null, canCreate: false, unavailable: 'Habilitación pendiente' }, permissions);
    assert.match(unavailable, /Habilitación pendiente/); assert.doesNotMatch(unavailable, /Nueva actividad|No hay actividades|Aplicar filtros/);
});

test('embedded activity summary uses three columns and keeps the creation date visible', async () => {
    const html = await renderVue('resources/js/Components/SupportActivityList.vue', { activities: [activity], compact: true }, permissions);
    assert.equal((html.match(/scope="col"/g) ?? []).length, 3);
    assert.doesNotMatch(html, /xl:table-cell|xl:hidden/);
    assert.match(html, /Creada:|20:15|ACT-000042|INC-2026-000001/);
    const summary = await renderVue('resources/js/Components/SupportActivitySummary.vue', { machineId: 42 }, permissions, { modules: {
        vue: { reactive: value => reactive({ ...value, available: true, loading: false, activities: [activity] }) },
    } });
    assert.equal((summary.match(/scope="col"/g) ?? []).length, 3);
});

test('creation has scalable employee and machine selectors, no GPS, and optional ticket', async () => {
    const html = await renderVue('resources/js/Pages/Support/Activities/Create.vue', { types: index.types }, permissions);
    assert.match(html, /Buscar máquina/); assert.match(html, /Buscar empleado/);
    assert.match(html, /Crear y asignar actividad/); assert.match(html, /Ticket relacionado \(opcional\)/);
    assert.doesNotMatch(html, /type="(?:number|file)"|navigator.geolocation|latitude|longitude|accuracy_m/);
    assert.doesNotMatch(html, /<details[^>]*\bopen(?:[\s=>])/);
    assert.match(html, /No se crean cuentas/);
});

test('detail timeline shows actors, Spanish states and snapshot only; technical references stay collapsed', async () => {
    const html = await renderVue('resources/js/Pages/Support/Activities/Show.vue', detail, permissions);
    assert.match(html, /Actividad creada/); assert.match(html, /Coordinación/); assert.match(html, /aún no tiene acceso al sistema/);
    assert.match(html, /no inicia ni completa trabajos/); assert.match(html, /Confirmar cancelación/);
    assert.match(html, /El personal autorizado inicia y finaliza desde la aplicación móvil/);
    assert.match(html, /La ubicación se valida al iniciar; finalizar no obtiene ni acredita una nueva ubicación/);
    assert.doesNotMatch(html, /se habilitará en una fase posterior|Iniciar actividad|Finalizar actividad/);
    assert.match(html, /no se ha validado/); assert.match(html, /No se recalcula/);
    assert.doesNotMatch(primaryText(html), /internal-private-uuid|FIELD_PHYSICAL_V1|ASSIGNED|NOT_EVALUATED/);
    assert.doesNotMatch(html, /<details[^>]*\bopen(?:[\s=>])|tile\.openstreetmap|geofence\.store/);
    const frozen = await renderVue('resources/js/Pages/Support/Activities/Show.vue', { ...detail, canCancel: false, activity: { ...activity, status: 'COMPLETED', snapshot: { result: 'INSIDE', evaluated_at: activity.created_at, distance_m: 43.2, accuracy_m: 5, version: 2 } } }, permissions);
    assert.match(frozen, /43 m|5 m|Dentro de zona/); assert.doesNotMatch(frozen, /Confirmar cancelación/);
});

test('untrusted titles descriptions actors and cancellation text are escaped', async () => {
    const unsafe = '<script>alert(1)</script>';
    const html = await renderVue('resources/js/Pages/Support/Activities/Show.vue', { ...detail,
        activity: { ...activity, title: unsafe, description: unsafe, cancellation_reason: unsafe },
        events: [{ id: 1, kind: 'INTERNAL_EVENT', actor: unsafe, occurred_at: activity.created_at }],
    }, permissions);
    assert.doesNotMatch(html, /<script>|INTERNAL_EVENT/); assert.match(html, /&lt;script&gt;/);
});

test('embedded summary loads once with parent scope and never invents counters', async () => {
    let load, state;
    let calls = 0;
    const html = await renderVue('resources/js/Components/SupportActivitySummary.vue', { machineId: 42 }, permissions, { modules: {
        vue: { onMounted: callback => { load = callback; }, reactive: value => { state = reactive(value); return state; } },
        axios: { default: { get: async (url, config) => {
            assert.equal(url, '/support.activities.summary'); assert.equal(config.params.vending_machine_id, 42);
            assert.ok(config.signal instanceof AbortSignal); calls++;
            return { data: { available: true, activities: [activity] } };
        } } },
    } });
    assert.match(html, /Consultando actividades/); assert.doesNotMatch(html, /No hay actividades/);
    assert.equal(calls, 0); await load(); assert.equal(calls, 1); assert.equal(state.activities.length, 1);
});

test('embedded summary makes no request without support permission and hides unavailable data', async () => {
    let load;
    let calls = 0;
    const html = await renderVue('resources/js/Components/SupportActivitySummary.vue', { ticketUuid: 'fixture-ticket' }, { settings: ['manage'] }, { modules: {
        vue: { onMounted: callback => { load = callback; } },
        axios: { default: { get: async () => { calls++; return { data: { available: false } }; } } },
    } });
    await load(); assert.equal(calls, 0); assert.doesNotMatch(html, /Actividades de campo|Ver historial/);
    const hidden = await renderVue('resources/js/Components/SupportActivitySummary.vue', { machineId: 42 }, permissions, { modules: {
        vue: { reactive: value => reactive({ ...value, available: false, loading: false }) },
    } });
    assert.doesNotMatch(hidden, /Ver historial|No hay actividades|Actividades de campo/);
});

test('picker performs explicit bounded server search and sends selection context', async () => {
    let search;
    let calls = 0;
    await renderVue('resources/js/Components/SupportActivityPicker.vue', { modelValue: '', kind: 'employees', purpose: 'create', label: 'Empleado', machineId: 42, activityType: 'MAINTENANCE' }, permissions, { modules: {
        vue: { createVNode: (type, props, ...rest) => {
            if (typeof type === 'object' && props?.onClick && !search) search = props.onClick;
            return createVNode(type, props, ...rest);
        } },
        axios: { default: { get: async (url, config) => {
            calls++; assert.equal(url, '/support.activities.options'); assert.equal(config.params.page, 1);
            assert.equal(config.params.vending_machine_id, 42); assert.equal(config.params.activity_type, 'MAINTENANCE');
            assert.equal(config.params.purpose, 'create'); return { data: { data: [], page: 1, has_more: false } };
        } } },
    } });
    assert.equal(calls, 0); assert.equal(typeof search, 'function'); await search(); assert.equal(calls, 1);
    assert.match(activityOptionLabel('employees', { employee_number: '042', full_name: 'Persona' }), /042 · Persona/);
    assert.doesNotMatch(activityOptionLabel('machines', { machine_code: 'VM-1', status: 'ACTIVE' }), /ACTIVE/);
});
