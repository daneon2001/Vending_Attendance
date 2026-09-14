import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { activities, employee, eventsFor, machine, profiles, ticket, types } from './support-activities-fixtures.mjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const routes = {};
function route(name, uri, methods = ['GET', 'HEAD']) { routes[name] = { uri, methods }; }
for (const [name, uri] of Object.entries({
    'vending-fleet.dashboard': 'vending', 'vending-machines.index': 'vending-machines',
    'vending-devices.index': 'vending/devices', 'vending-employees.index': 'employees',
    'admin.asistencias.index': 'admin/asistencias', 'attendance-cards.index': 'attendance-cards',
    'vending-releases.index': 'vending/releases', 'settings.index': 'settings',
    'settings.roles.page': 'settings/roles', 'settings.users.page': 'settings/users',
    'settings.audit.page': 'settings/audit', 'dashboard': 'dashboard',
    'dashboard.corporativo-reclutamiento': 'dashboard/corporativo-reclutamiento',
    'clocks.index': 'clocks', 'companies.index': 'companies', 'units.index': 'units',
    'profile.edit': 'profile', 'support.tickets.index': 'support/tickets',
    'support.tickets.show': 'support/tickets/{ticket}', 'support.verifications.index': 'support/verifications',
    'support.notifications.index': 'support/notifications', 'support.activities.index': 'support/activities',
    'support.activities.create': 'support/activities/create', 'support.activities.options': 'support/activities/options',
    'support.activities.summary': 'support/activities/summary', 'support.activities.show': 'support/activities/{activity}',
    'vending-machines.show': 'vending-machines/{machine}',
})) route(name, uri);
route('logout', 'logout', ['POST']);
route('support.activities.store', 'support/activities', ['POST']);
route('support.activities.cancel', 'support/activities/{activity}/cancel', ['POST']);
// Only filenames listed by Vite, plus explicitly named public logos/Ziggy, can be served.
const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
const canAssign = profile => profile.permissions.support?.some(action => ['assign', 'manage'].includes(action));
const jsonHeaders = { 'Content-Type': 'application/json; charset=utf-8' };

export async function startVisualServer(port = 0) {
    const manifest = JSON.parse(await readFile(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const staticFiles = new Map();
    for (const entry of Object.values(manifest)) {
        for (const file of [entry.file, ...(entry.css || []), ...(entry.assets || [])]) {
            if (!/^assets\/[a-zA-Z0-9_.-]+$/.test(file)) throw new Error('Unexpected build asset path');
            staticFiles.set('/build/' + file, path.join(root, 'public/build', file));
        }
    }
    staticFiles.set('/fixture-ziggy.js', path.join(root, 'vendor/tightenco/ziggy/dist/route.umd.js'));
    for (const file of ['medical-life-logo.png', 'medical-life-logo_short.png']) {
        staticFiles.set('/images/' + file, path.join(root, 'public/images', file));
    }
    const css = [...staticFiles.keys()].filter(file => file.endsWith('.css'));
    const server = http.createServer(async (req, res) => {
        try {
            const origin = 'http://127.0.0.1:' + server.address().port;
            if (req.headers.host !== '127.0.0.1:' + server.address().port) { res.writeHead(403); return res.end(); }
            res.setHeader('Cache-Control', 'no-store');
            res.setHeader('X-Content-Type-Options', 'nosniff');
            res.setHeader('Referrer-Policy', 'no-referrer');
            res.setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
            res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
            const url = new URL(req.url, origin);
            const profileKey = /(?:^|; )visual_profile=(admin|support|viewer|none)(?:;|$)/.exec(req.headers.cookie || '')?.[1] || 'admin';
            const profile = profiles[profileKey];
            const sendJson = (value, status = 200) => { res.writeHead(status, jsonHeaders); res.end(JSON.stringify(value)); };
            const page = (component, props, status = 200) => {
                const data = { component, props: { errors: {}, flash: {}, auth: {
                    user: { id: 90000, name: profile.name, email: profileKey + '@visual.example.test' },
                    roles: [profile.name], permissions: profile.permissions,
                }, ...props }, url: url.pathname + url.search, version: 'isolated-visual', clearHistory: false, encryptHistory: false };
                if (req.headers['x-inertia']) { res.setHeader('X-Inertia', 'true'); return sendJson(data, status); }
                const ziggy = { url: origin, port: server.address().port, defaults: {}, routes };
                const banner = '<aside style="position:relative;z-index:100;background:#fffbeb;color:#78350f;padding:8px 16px;font:14px sans-serif" aria-label="Entorno de prueba">ENTORNO VISUAL AISLADO · datos sintéticos · no guarda datos reales · '
                    + Object.keys(profiles).map(key => '<a style="margin:0 8px;color:#1e40af" href="/__fixture/profile/' + key + '">' + escapeHtml(profiles[key].name) + '</a>').join('') + '</aside>';
                res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end('<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                    + '<meta name="app-base-path" content=""><meta name="app-api-base-url" content="' + origin + '">'
                    + css.map(file => '<link rel="stylesheet" href="' + file + '">').join('')
                    + '<script>globalThis.Ziggy=' + JSON.stringify(ziggy) + '</script><script src="/fixture-ziggy.js"></script>'
                    + '</head><body class="font-sans antialiased">' + banner + '<div id="app" data-page="' + escapeHtml(JSON.stringify(data)) + '"></div>'
                    + '<script type="module" src="/build/' + manifest['resources/js/app.js'].file + '"></script></body></html>');
            };
            // All mutations are rejected. No database, filesystem write or backend proxy exists.
            if (!['GET', 'HEAD'].includes(req.method)) return sendJson({ message: 'Entorno visual: los cambios no se guardan.' }, 405);
            if (staticFiles.has(url.pathname)) {
                const file = staticFiles.get(url.pathname);
                const mime = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2' }[path.extname(file)];
                res.writeHead(200, { 'Content-Type': mime || 'application/octet-stream' });
                return res.end(await readFile(file));
            }
            if (url.pathname === '/favicon.ico') { res.writeHead(204); return res.end(); }
            const profileMatch = /^\/__fixture\/profile\/(admin|support|viewer|none)$/.exec(url.pathname);
            if (profileMatch) {
                res.writeHead(303, { 'Set-Cookie': 'visual_profile=' + profileMatch[1] + '; Path=/; HttpOnly; SameSite=Strict', Location: '/support/activities' });
                return res.end();
            }
            if (url.pathname === '/') { res.writeHead(303, { Location: '/support/activities' }); return res.end(); }
            const empty = { data: [], total: 0, current_page: 1, last_page: 1, links: [] };
            if (!profile.permissions.support) {
                return page('Support/Activities/Index', { activities: empty, filters: {}, types, canCreate: false,
                    unavailable: 'No tienes permiso para consultar actividades de soporte.' }, 403);
            }
            const filters = Object.fromEntries(url.searchParams);
            const rows = activities.filter(activity =>
                (!filters.status || activity.status === filters.status)
                && (!filters.activity_type || activity.activity_type === filters.activity_type)
                && (!filters.search || JSON.stringify([activity.folio, activity.title, activity.machine.name, activity.employee.full_name]).toLowerCase().includes(filters.search.toLowerCase()))
                && (!filters.has_ticket || (filters.has_ticket === 'yes') === !!activity.ticket)
                && (!filters.ticket_uuid || activity.ticket?.uuid === filters.ticket_uuid)
                && (!filters.vending_machine_id || String(machine.id) === filters.vending_machine_id)
                && (!filters.employee_id || String(employee.id) === filters.employee_id)
                && (!filters.from || activity.created_at.slice(0, 10) >= filters.from)
                && (!filters.to || activity.created_at.slice(0, 10) <= filters.to));
            if (url.pathname === '/support/activities/options') {
                if (filters.search === 'error-demo') return sendJson({ message: 'No fue posible consultar las opciones. Intenta nuevamente.' }, 503);
                const kind = filters.kind;
                let options = kind === 'machines' ? [machine] : kind === 'tickets' ? [ticket] :
                    Array.from({ length: 23 }, (_, i) => ({ ...employee, id: employee.id + i,
                        employee_number: 'DEMO-VISUAL-' + String(i + 1).padStart(3, '0'),
                        full_name: i ? 'Técnico DEMO ' + (i + 1) : employee.full_name,
                        has_account: i !== 1, has_active_account: i !== 1 }));
                if (filters.search) options = options.filter(row => JSON.stringify(row).toLowerCase().includes(filters.search.toLowerCase()));
                const number = Math.max(1, Number(filters.page) || 1);
                return sendJson({ data: options.slice((number - 1) * 20, number * 20), page: number, has_more: options.length > number * 20 });
            }
            if (url.pathname === '/support/activities/summary') return sendJson({ available: true, activities: rows.slice(0, 5) });
            if (url.pathname === '/support/notifications') return sendJson({ data: [], unread_count: 0 });
            if (url.pathname === '/support/activities/create') {
                if (!canAssign(profile)) return page('Support/Activities/Index', { activities: empty, types, canCreate: false, unavailable: 'No tienes permiso para asignar actividades.' }, 403);
                return page('Support/Activities/Create', { types });
            }
            if (url.pathname === '/support/activities') return page('Support/Activities/Index', {
                activities: { ...empty, data: rows, total: rows.length }, filters, types, canCreate: canAssign(profile), unavailable: null });
            let activity = activities.find(row => url.pathname === '/support/activities/' + row.uuid);
            // Presentation variants, not claims that the domain permits starting outside a geofence.
            if (activity && ['OUTSIDE', 'UNCERTAIN'].includes(filters.snapshot)) {
                activity = structuredClone(activity);
                activity.snapshot.result = filters.snapshot;
                activity.geofence_result = filters.snapshot;
                activity.description = 'Variante sintética de presentación del snapshot; no representa una transición válida del dominio.';
            }
            if (activity) return page('Support/Activities/Show', { activity, events: eventsFor(activity),
                canCancel: canAssign(profile) && ['ASSIGNED', 'IN_PROGRESS'].includes(activity.status) });
            if (url.pathname === '/support/tickets/' + ticket.uuid) return page('Support/Show', {
                ticket, options: { categories: [{ value: 'HARDWARE', label: 'Equipo físico' }], assignees: [{ id: 90002, name: profiles.support.name }] },
                events: [{ uuid: 'fixture-event', kind: 'support.ticket.created', created_at: ticket.reported_at }], evidence: [],
                allowedTransitions: ['IN_PROGRESS', 'CANCELLED'],
                evidencePolicy: { max_count: 5, max_size_bytes: 5242880, allowed_mimes: ['image/jpeg', 'image/png', 'image/webp'] },
            });
            if (url.pathname === '/vending-machines/' + machine.uuid) return page('VendingMachines/Show', {
                machine, auditLogs: [], employees: [], assignmentTypes: ['PRIMARY'], geofenceStatuses: ['ACTIVE'],
                geofenceEditor: { limits: { radius_m: { min: 1, max: 1000 } }, source_location: null },
            });
            return sendJson({ message: 'Ruta fuera de esta revisión visual aislada.' }, 404);
        } catch (error) { console.error('Fixture rendering error:', error.message); res.writeHead(500); res.end('Error del entorno visual.'); }
    });
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(port, '127.0.0.1', resolve); });
    return { origin: 'http://127.0.0.1:' + server.address().port, close: () => new Promise(resolve => server.close(resolve)) };
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const server = await startVisualServer(8136);
    console.log('VISUAL FIXTURES ONLY: ' + server.origin + '/support/activities');
    console.log('No Laravel, database, real accounts, mutations or external resources. Ctrl+C to stop.');
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, async () => { await server.close(); process.exit(0); });
}
