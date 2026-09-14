import test from 'node:test';
import assert from 'node:assert/strict';
import { createElementBlock, createElementVNode, reactive, ref } from 'vue';
import { visibleNavigation } from '../../resources/js/presentation/navigation.js';
import { canSupport, supportCheckLabel, supportError, supportEventLabel, supportFileSize, supportLabel, supportOperationUuid, supportPageLinks } from '../../resources/js/presentation/support.js';
import { renderVue } from './vueRender.mjs';

const roles = {
    administrator: { support: ['manage'] },
    operator: { support: ['view', 'report', 'comment', 'verify'] },
    support: { support: ['view', 'view_all', 'comment', 'assign', 'resolve', 'verify'] },
    viewer: { support: ['view', 'view_all'] },
};
const ticket = {
    uuid: 'ticket-private-uuid', folio: 'SOP-000042', machine: { code: 'VM-042', name: 'Recepción' },
    device: { name: 'Equipo de recepción' }, title: 'Pantalla sin respuesta', description: 'La pantalla dejó de responder.',
    category: 'HARDWARE', severity: 'HIGH', priority: 'NORMAL', status: 'OPEN', source: 'MANUAL_WEB',
    assignee: null, reported_at: '2026-09-08T02:15:00Z', updated_at: '2026-09-08T02:15:00Z',
    response_due_at: null, resolution_due_at: null, location_available: false,
};
const options = {
    machines: [{ id: 42, machine_code: 'VM-042', name: 'Recepción' }],
    categories: [{ value: 'HARDWARE', label: 'Equipo físico' }],
    assignees: [{ id: 7, name: 'Responsable de soporte' }], devices: [],
};
const stats = { open: 18, high_critical: 6, unassigned: 3, sla_warning: 2, sla_breached: 1, created_today: 4 };
const indexProps = { tickets: [ticket], options, stats, filters: {}, pagination: { page: 2, last_page: 4, total: 75 } };
const detailProps = {
    ticket, options, events: [{ uuid: 'event-uuid', kind: 'support.ticket.created', body: null, created_at: ticket.reported_at }],
    evidence: [{ uuid: 'photo-uuid', status: 'CONFIRMED', thumbnail: true, size_bytes: 2048, sha256: 'private-digest-not-visible', confirmed_at: ticket.reported_at }],
    allowedTransitions: ['IN_PROGRESS', 'CANCELLED'],
    evidencePolicy: { max_count: 5, max_size_bytes: 5242880, allowed_mimes: ['image/jpeg', 'image/png', 'image/webp'] },
};
const visibleText = (html) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');

test('support navigation requires explicit support permission, including settings administrators', () => {
    for (const permissions of [{}, { settings: ['manage'] }, { support: ['report'] }]) {
        assert.equal(visibleNavigation(permissions).some((group) => group.key === 'soporte'), false);
        assert.equal(canSupport(permissions), false);
    }
    for (const permissions of Object.values(roles)) {
        const group = visibleNavigation(permissions).find((entry) => entry.key === 'soporte');
        assert.deepEqual(group.items.map((entry) => entry.label), ['Tickets', 'Verificaciones', 'Actividades']);
        assert.ok(group.items.every((entry) => entry.strict));
    }
    assert.equal(canSupport(roles.operator, 'assign'), false);
    assert.equal(canSupport(roles.administrator, 'assign'), true);
});

test('operation UUID uses secure native or LAN-compatible random bytes and fails closed without crypto', () => {
    assert.equal(supportOperationUuid({ randomUUID: () => 'native-uuid' }), 'native-uuid');
    const uuid = supportOperationUuid({ getRandomValues: (bytes) => bytes.fill(171) });
    assert.equal(uuid, 'abababab-abab-4bab-abab-abababababab');
    assert.match(uuid, /^[a-f\d]{8}-[a-f\d]{4}-4[a-f\d]{3}-[89ab][a-f\d]{3}-[a-f\d]{12}$/);
    assert.throws(() => supportOperationUuid(null), /envío seguro/);
});

test('support labels never expose unknown internal status, event, or check codes', () => {
    assert.equal(supportLabel('CRITICAL'), 'Crítica');
    assert.equal(supportLabel('URGENT'), 'Urgente');
    assert.equal(supportLabel('INTERNAL_NEW_STATE'), 'Sin información');
    assert.equal(supportEventLabel('support.comment.created'), 'Comentario agregado');
    assert.equal(supportEventLabel('INTERNAL_EVENT'), 'Actividad del reporte');
    assert.equal(supportCheckLabel('CAMERA_PERMISSION'), 'Permiso de cámara');
    assert.equal(supportCheckLabel('INTERNAL_CHECK'), 'Comprobación del equipo');
    for (const key of ['__proto__', 'constructor', 'toString']) {
        assert.equal(supportLabel(key), 'Sin información');
        assert.equal(supportEventLabel(key), 'Actividad del reporte');
        assert.equal(supportCheckLabel(key), 'Comprobación del equipo');
    }
    assert.equal(supportFileSize(null), 'Sin información');
    assert.equal(supportFileSize(0), '0 KB');
    assert.equal(supportFileSize(5242880), '5 MB');
});

test('request errors provide useful Spanish feedback without server internals', () => {
    assert.match(supportError({ response: { status: 419 } }), /sesión venció/);
    assert.match(supportError({ response: { status: 413 } }), /tamaño permitido/);
    assert.match(supportError({ response: { status: 429 } }), /Espera/);
    assert.doesNotMatch(supportError({ response: { status: 500, data: { message: 'secret stack trace' } } }), /secret/);
    assert.equal(supportError({ response: { status: 422, data: { errors: { file: ['La imagen no es válida.'] } } } }), 'La imagen no es válida.');
});

test('bounded pagination preserves the current query through its link builder', () => {
    assert.deepEqual(supportPageLinks({ page: 1, last_page: 1 }, () => 'unused'), []);
    const first = supportPageLinks({ page: 1, last_page: 3 }, (page) => `?status=OPEN&page=${page}`);
    assert.equal(first[0].url, null);
    assert.equal(first[2].url, '?status=OPEN&page=2');
    assert.equal(first[1].active, true);
    assert.equal(supportPageLinks({ page: 3, last_page: 3 }, String)[2].url, null);
});

for (const [name, permissions] of Object.entries(roles)) {
    test(`index renders real data and correct report action for ${name}`, async () => {
        const html = await renderVue('resources/js/Pages/Support/Index.vue', indexProps, permissions);
        assert.equal(html.includes('Enviar reporte'), ['administrator', 'operator'].includes(name));
        assert.match(html, /SOP-000042/);
        assert.match(html, /Pantalla sin respuesta/);
        assert.match(html, /VM-042/);
        assert.match(html, /75 reportes en esta consulta/);
        assert.match(html, /20:15/);
        assert.match(html, /Ciudad de México/);
        assert.equal((html.match(/scope="col"/g) ?? []).length, 8);
        assert.match(html, /Filtros avanzados/);
        assert.doesNotMatch(html, /<details[^>]*\bopen(?:[\s=>])/);
        assert.match(html, /Buscar máquina por código o nombre/);
        assert.doesNotMatch(visibleText(html), /ticket-private-uuid/);
    });

    test(`detail actions honor the ${name} matrix`, async () => {
        const html = await renderVue('resources/js/Pages/Support/Show.vue', detailProps, permissions);
        assert.equal(html.includes('Guardar comentario'), name !== 'viewer');
        assert.equal(html.includes('Guardar fotografía'), name !== 'viewer');
        assert.equal(html.includes('Guardar responsable'), ['administrator', 'support'].includes(name));
        assert.equal(html.includes('Guardar estado'), ['administrator', 'support'].includes(name));
        assert.match(html, /Descargar imagen/);
        assert.match(html, /Ubicación no disponible/);
        assert.match(html, /no tiene tiempos objetivo configurados/);
    });
}

test('empty collections render meaningful states outside tables', async () => {
    const index = await renderVue('resources/js/Pages/Support/Index.vue', { tickets: null, options: null, pagination: null, stats: null, filters: null }, roles.viewer);
    assert.match(index, /Sin reportes para mostrar/);
    assert.doesNotMatch(index, /<table/);
    assert.doesNotMatch(index, /undefined|NaN/);
    const verifications = await renderVue('resources/js/Pages/Support/Verifications.vue', { verifications: null }, roles.viewer);
    assert.match(verifications, /Sin verificaciones disponibles/);
    assert.doesNotMatch(verifications, /<table/);
});

test('detail renders only backend-provided transitions and respects terminal immutability', async () => {
    const noTransitions = await renderVue('resources/js/Pages/Support/Show.vue', { ...detailProps, allowedTransitions: [] }, roles.administrator);
    assert.doesNotMatch(noTransitions, /Guardar estado/);
    const restricted = await renderVue('resources/js/Pages/Support/Show.vue', { ...detailProps, allowedTransitions: ['IN_PROGRESS'] }, roles.support);
    assert.match(restricted, /value="IN_PROGRESS"/);
    assert.doesNotMatch(restricted, /value="CLOSED"|value="CANCELLED"/);
    for (const status of ['CLOSED', 'CANCELLED']) {
        const html = await renderVue('resources/js/Pages/Support/Show.vue', { ...detailProps, ticket: { ...ticket, status } }, roles.administrator);
        assert.doesNotMatch(html, /Guardar comentario|Guardar fotografía|Guardar responsable|Guardar estado/);
        assert.match(html, /Descargar imagen/);
    }
});

test('evidence renders authorized thumbnails only and explicit downloads without private storage metadata', async () => {
    const html = await renderVue('resources/js/Pages/Support/Show.vue', detailProps, roles.viewer);
    assert.match(html, /<img[^>]+src="\/support\.evidence\.thumbnail/);
    assert.match(html, /<img[^>]+loading="lazy"/);
    assert.doesNotMatch(html, /<img[^>]+(?:download|\/content)/);
    assert.match(html, /<a[^>]+href="\/support\.evidence\.download/);
    assert.doesNotMatch(html, /private-digest-not-visible|storage_key|support_private/);
    assert.doesNotMatch(html, /<details[^>]*\bopen(?:[\s=>])/);
});

test('untrusted report and timeline content is escaped and technical event codes remain hidden', async () => {
    const unsafe = '<script>alert("unsafe")</script>';
    const html = await renderVue('resources/js/Pages/Support/Show.vue', {
        ...detailProps, ticket: { ...ticket, description: unsafe },
        events: [{ uuid: 'event', kind: 'INTERNAL_EVENT', body: unsafe, created_at: ticket.reported_at }],
    }, roles.viewer);
    assert.doesNotMatch(html, /<script>|INTERNAL_EVENT/);
    assert.match(html, /&lt;script&gt;/);
    assert.match(html, /Actividad del reporte/);
});

test('verification details distinguish reported and server-observed checks without exposing internal codes', async () => {
    const html = await renderVue('resources/js/Pages/Support/Verifications.vue', {
        verifications: { last_page: 1, data: [{ uuid: 'verification-uuid', machine: { machine_code: 'VM-042' }, device: { device_name: 'Tablet' }, summary: 'FAIL', completed_at: ticket.reported_at, checks: [
            { code: 'CAMERA_PERMISSION', result: 'FAIL', source: 'CLIENT_REPORTED', observed_at: ticket.reported_at, details: { error_code: 'INTERNAL_CAMERA_ERROR' } },
            { code: 'HEARTBEAT', result: 'WARNING', source: 'SERVER_SNAPSHOT', observed_at: ticket.reported_at, details: { seconds: 300 } },
            { code: 'INTERNAL_UNKNOWN_CHECK', result: 'NOT_AVAILABLE', source: 'UNKNOWN', details: {} },
        ] }] },
    }, roles.viewer, { modules: { vue: { ref: (value) => ref(value === null ? 'verification-uuid' : value) } } });
    assert.match(html, /Requiere atención/);
    assert.match(html, /Permiso de cámara/);
    assert.match(html, /Reportado por el equipo/);
    assert.match(html, /Comprobado por el servidor/);
    assert.match(html, /300 segundos/);
    assert.match(html, /aria-expanded="true"/);
    assert.match(html, /Comprobación del equipo/);
    assert.doesNotMatch(html, /INTERNAL_CAMERA_ERROR|INTERNAL_UNKNOWN_CHECK|CLIENT_REPORTED|SERVER_SNAPSHOT/);
    assert.doesNotMatch(html, /<details[^>]*\bopen(?:[\s=>])/);
});

test('dashboard support summary is strict, compact, and never substitutes zero for loading', async () => {
    const props = { generated_at: ticket.reported_at, kpis: {}, app_versions: [], alerts: [], thresholds: {}, last_sybi_sync: null };
    for (const permissions of [{ settings: ['manage'] }, {}, roles.viewer]) {
        const html = await renderVue('resources/js/Pages/VendingFleet/Dashboard.vue', props, permissions);
        assert.equal(html.includes('aria-label="Resumen de soporte"'), permissions === roles.viewer);
        if (permissions === roles.viewer) {
            assert.match(html, /Consultando reportes/);
            assert.doesNotMatch(html, />0<\/span> abiertos/);
            assert.equal((html.match(/>Ver tickets<\/a>/g) ?? []).length, 1);
        }
        const primary = html.match(/<section[^>]*aria-label="Indicadores principales"[\s\S]*?<\/section>/)[0];
        assert.equal((primary.match(/<article\b/g) ?? []).length, 6);
    }
    const html = await renderVue('resources/js/Components/SupportSummary.vue', {}, roles.viewer, { modules: { vue: {
        reactive: (value) => reactive({ ...value, open: 0, highCritical: 7, loading: false }),
    } } });
    assert.match(html, />0<\/span> abiertos/);
    assert.match(html, />7<\/span> de impacto alto o crítico/);
});

test('summary makes one authorized initial request, refreshes manually, and masks failure without stale counters', async () => {
    for (const permissions of [roles.viewer, { settings: ['manage'] }]) {
        let load;
        let state;
        let calls = 0;
        await renderVue('resources/js/Components/SupportSummary.vue', {}, permissions, { modules: {
            vue: { onMounted: (callback) => { load = callback; }, reactive: (value) => { state = reactive(value); return state; } },
            axios: { default: { get: async (url, config) => {
                assert.equal(url, '/support.summary');
                assert.ok(config.signal instanceof AbortSignal);
                calls++;
                if (calls > 1) throw { response: { status: 500, data: { message: 'private server data' } } };
                return { data: { open: 3, high_critical: 0 } };
            } } },
        } });
        assert.equal(calls, 0);
        await load();
        if (permissions !== roles.viewer) { assert.equal(calls, 0); continue; }
        assert.equal(calls, 1);
        assert.equal(state.open, 3);
        assert.equal(state.highCritical, 0);
        await load();
        assert.equal(calls, 2);
        assert.equal(state.open, null);
        assert.equal(state.highCritical, null);
        assert.doesNotMatch(state.error, /private/);
    }
});

test('notification feed uses authorized server count and idempotent POST then refresh on mark-read', async () => {
    const notification = { id: 'notification-id', ticket_uuid: ticket.uuid, folio: ticket.folio, event_uuid: 'private-event-id', kind: 'support.comment.created', created_at: ticket.reported_at, read_at: null };
    let markRead;
    let state;
    const calls = [];
    const html = await renderVue('resources/js/Components/SupportNotificationFeed.vue', {}, roles.viewer, { modules: {
        vue: {
            reactive: (value) => { state = reactive({ ...value, notifications: [notification], unreadCount: 9, loading: false, loaded: true }); return state; },
            createElementVNode: (tag, attrs, ...rest) => {
                if (tag === 'button' && attrs?.['aria-label']?.startsWith('Marcar aviso')) markRead = attrs.onClick;
                return createElementVNode(tag, attrs, ...rest);
            },
            createElementBlock: (tag, attrs, ...rest) => {
                if (tag === 'button' && attrs?.['aria-label']?.startsWith('Marcar aviso')) markRead = attrs.onClick;
                return createElementBlock(tag, attrs, ...rest);
            },
        },
        axios: { default: {
            post: async (url, body) => { calls.push(['post', url]); assert.deepEqual(body, {}); return { status: 204 }; },
            get: async (url) => { calls.push(['get', url]); return { data: { notifications: [{ ...notification, read_at: ticket.reported_at }], unread_count: 8 } }; },
        } },
    } });
    assert.match(html, /9 sin leer/);
    assert.match(html, /Comentario agregado/);
    assert.match(html, /20:15/);
    assert.match(html, /Marcar como leído/);
    assert.doesNotMatch(visibleText(html), /private-event-id|support.comment.created|notification-id/);
    assert.doesNotMatch(html, /<details[^>]*\bopen(?:[\s=>])/);
    assert.equal(calls.length, 0);
    assert.equal(typeof markRead, 'function');
    await markRead();
    assert.equal(calls[0][0], 'post');
    assert.match(calls[0][1], /support.notifications.read/);
    assert.deepEqual(calls[1], ['get', '/support.notifications.index']);
    assert.equal(state.unreadCount, 8);
    assert.equal(state.notifications[0].read_at, ticket.reported_at);
    assert.match(state.feedback, /marcado como leído/);
});

test('standalone activity notification opens activity instead of inventing a ticket', async () => {
    const html = await renderVue('resources/js/Components/SupportNotificationFeed.vue', {}, roles.viewer, { modules: {
        vue: { reactive: value => reactive({ ...value, loading: false, loaded: true, unreadCount: 1, notifications: [{
            id: 'fixture-notice', activity_uuid: 'fixture-activity', ticket_uuid: null, folio: 'ACT-000002',
            kind: 'support_activity.completed', created_at: ticket.reported_at, read_at: null,
        }] }) },
    } });
    assert.match(html, /ACT-000002|Actividad completada|1 sin leer/);
    assert.match(html, /support.activities.show/);
    assert.doesNotMatch(html, /support.tickets.show|INC-2026/);
});

test('notification feed does not request without permission or present unloaded data as empty', async () => {
    for (const permissions of [roles.viewer, { settings: ['manage'] }]) {
        let load;
        let calls = 0;
        let state;
        const html = await renderVue('resources/js/Components/SupportNotificationFeed.vue', {}, permissions, { modules: {
            vue: { onMounted: (callback) => { load = callback; }, reactive: (value) => { state = reactive(value); return state; } },
            axios: { default: { get: async () => { calls++; return { data: { notifications: [], unread_count: 0 } }; } } },
        } });
        assert.doesNotMatch(html, /No hay avisos de soporte disponibles|0 sin leer/);
        await load();
        assert.equal(calls, permissions === roles.viewer ? 1 : 0);
        if (permissions === roles.viewer) { assert.equal(state.loaded, true); assert.equal(state.unreadCount, 0); }
        else assert.doesNotMatch(html, /Avisos de soporte/);
    }
});
