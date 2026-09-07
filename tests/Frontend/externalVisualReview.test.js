import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { visibleNavigation } from '../../resources/js/presentation/navigation.js';
import { renderVue } from './vueRender.mjs';

// External observations are the scope. SSR checks do not certify browser layout.
const operator = { employees: ['view', 'sync', 'import'], vending_machines: ['view', 'assign'] };
const viewer = { employees: ['view'], vending_machines: ['view'] };
const employeeProps = {
 employees: { data: [], current_page: 1, last_page: 1, total: 0 }, filters: {},
 fortia: { driver: 'mock', source: 'DEMO', real_api_ready: false, write_enabled: false },
 capabilities: { import: true, sync: true }, limits: { file_kb: 5120, rows: 5000 },
};
const deviceProps = (heartbeat = null) => ({
 devices: { data: [{
  uuid: 'review-device', device_serial: 'PILOT-01', platform: 'ANDROID', status: 'ACTIVE',
  last_heartbeat_at: heartbeat, app_version: '1.0.0', machine: { uuid: 'machine', machine_code: 'VM-DEMO-001' },
  fleet: { status: 'OFFLINE', manifest: { configuration: { state: 'ERROR' }, employees: { state: 'PENDING' } }, app_version: { status: 'CURRENT' }, reasons: [] },
 }], links: [] },
 filters: { status: 'ACTIVE' }, machines: [], statuses: ['ACTIVE', 'REVOKED'], platforms: ['ANDROID'],
 appVersions: [], errorCategories: [], thresholds: {}, canManage: false,
});
const catalogProps = (catalog_view = 'sybi') => ({
 machines: { data: [], links: [] },
 sourceRecords: { data: [
  { uuid: 'linked', name: 'Vinculada', source_status: 'PRESENT', validation_status: 'READY', validation_codes: [],
    promoted_vending_machine: { uuid: 'machine', machine_code: 'VM-DEMO-001', status: 'DRAFT' } },
  { uuid: 'unlinked', name: 'Sin vínculo', source_status: 'PRESENT', validation_status: 'INCOMPLETE_LOCATION', validation_codes: ['ZERO_COORDINATES'], promoted_vending_machine: null },
 ], links: [] },
 filters: { catalog_view }, statuses: [], coordinateSources: [], catalogSources: [], syncStatuses: [],
 sourceStatuses: [], validationStatuses: [], municipalities: [], localities: [],
 sybiIntegration: { configured: true, latest_run: { status: 'SUCCESS', finished_at: '2026-09-05T18:48:23Z' } },
});
const textOnly = html => html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
function expectClosed(html, title) {
 const details = [...html.matchAll(/<details\b([^>]*)>\s*<summary[^>]*>([\s\S]*?)<\/summary>/g)]
  .find(match => textOnly(match[2]).startsWith(title));
 assert.ok(details, title + ' exists');
 assert.ok(!/\bopen(?:\s|=|$)/.test(details[1]), title + ' starts closed');
}

test('external devices review separates administrative filter and operational state without changing data', async () => {
 const props = deviceProps();
 const original = JSON.stringify(props);
 const html = await renderVue('resources/js/Pages/VendingFleet/Devices.vue', props, operator);
 assert.ok(html.includes('>Estado administrativo<select'));
 assert.ok(html.includes('>Estado operativo</th>'));
 assert.ok(!html.includes('Estado del dispositivo indica'));
 assert.ok(/<option value="ACTIVE">Activo<\/option>/.test(html));
 const source = await readFile('resources/js/Pages/VendingFleet/Devices.vue', 'utf8');
 assert.ok(source.includes('v-model="filters.status"'));
 assert.ok(html.includes('>Sin conexión</span>'));
 assert.ok(html.includes('Aún no se ha conectado'));
 assert.ok(!html.includes('Sin conexión registrada'));
 assert.equal(JSON.stringify(props), original);
});
test('external devices review keeps both synchronization issues in compact labelled lines', async () => {
 const html = await renderVue('resources/js/Pages/VendingFleet/Devices.vue', deviceProps(), operator);
 const lines = [...html.matchAll(/<p\b[^>]*>[\s\S]*?<\/p>/g)].map(match => textOnly(match[0]));
 assert.ok(lines.includes('Configuración · Con error'));
 assert.ok(lines.includes('Empleados · Pendiente'));
 expectClosed(html, 'Filtros avanzados');
});
test('external devices review preserves readable Mexico City connection dates', async () => {
 const html = await renderVue('resources/js/Pages/VendingFleet/Devices.vue', deviceProps('2026-09-05T18:48:23Z'), viewer);
 assert.ok(html.includes('05/09/2026 12:48'));
 assert.ok(!html.includes('2026-09-05T'));
 assert.ok(!html.includes('Aún no se ha conectado'));
});
test('external employees review uses the specific layout header once and a task-oriented import action', async () => {
 const source = await readFile('resources/js/Pages/Employees/VendingCatalog.vue', 'utf8');
 assert.ok(/<template #header>[\s\S]*?<h1[^>]*>Empleados<\/h1>[\s\S]*?<\/template>/.test(source));
 const html = await renderVue('resources/js/Pages/Employees/VendingCatalog.vue', employeeProps, operator);
 assert.equal((html.match(/<h1\b/g) || []).length, 1);
 assert.ok(html.includes('Consulta y administra los empleados disponibles para las máquinas vending.'));
 assert.ok(html.includes('Importar empleados'));
 for (const old of ['Panel de control', 'Identidad laboral desde Fortia', 'Importar Excel/CSV']) assert.ok(!html.includes(old), old);
});
test('external Fortia review keeps one compact honest notice with capability-gated consultation', async () => {
 for (const [permissions, capabilities] of [[operator, { import: true, sync: true }], [viewer, { import: false, sync: false }]]) {
  const props = { ...employeeProps, capabilities };
  const original = JSON.stringify(props);
  const html = await renderVue('resources/js/Pages/Employees/VendingCatalog.vue', props, permissions);
  assert.ok(textOnly(html).includes('Fortia · Modo de prueba'));
  assert.equal((html.match(/Modo de prueba/g) || []).length, 1);
  assert.ok(html.includes('La conexión productiva aún no está habilitada.'));
  assert.equal(html.includes('Consultar cambios</button>'), capabilities.sync);
  assert.ok(!html.includes('Aplicar sincronización'));
  if (capabilities.sync) expectClosed(html, 'Más información');
  assert.equal(JSON.stringify(props), original);
 }
 const source = await readFile('resources/js/Pages/Employees/VendingCatalog.vue', 'utf8');
 assert.ok(source.includes('@click="sync(true)"'));
 assert.ok(source.includes('fortia.write_enabled && syncResult?.dry_run'));
 assert.ok(source.includes(':disabled="syncBusy || !writeConfirmed"'));
});
test('external Fortia review does not falsely label a real driver as mock or claim it is connected', async () => {
 for (const ready of [false, true]) {
  const html = await renderVue('resources/js/Pages/Employees/VendingCatalog.vue', {
   ...employeeProps, fortia: { driver: 'http', real_api_ready: ready, write_enabled: false },
  }, operator);
  assert.ok(!html.includes('Modo de prueba'));
  assert.equal(html.includes('La conexión productiva aún no está habilitada.'), !ready);
  assert.ok(!html.includes('Fortia conectado'));
 }
});
test('external import review preserves CSV and XLSX disclosure inside the existing wizard', async () => {
 const html = await renderVue('resources/js/Pages/Employees/Partials/StagedEmployeeImport.vue', { open: true, limits: employeeProps.limits }, operator);
 assert.ok(html.includes('Importar empleados'));
 assert.ok(html.includes('Archivo CSV UTF-8 o XLSX de una sola hoja'));
 assert.ok(html.includes('accept=".csv,.xlsx"'));
});
test('external SYBI review uses one visible name and identifies the linked machine, not operational readiness', async () => {
 const props = catalogProps();
 const original = JSON.stringify(props);
 const html = await renderVue('resources/js/Pages/VendingMachines/Index.vue', props, operator);
 assert.ok(/<h1[^>]*>Catálogo SYBI<\/h1>/.test(html));
 assert.ok(!html.includes('SYBIML'));
 assert.ok(html.includes('>Máquina vinculada</th>'));
 assert.ok(!html.includes('>Operación</th>'));
 assert.ok(/<a\b[^>]*href="\/vending-machines.show[^"]*"[^>]*>VM-DEMO-001<\/a>/.test(html));
 assert.ok(html.includes('>Borrador</p>'));
 assert.ok(html.includes('Aún no incorporada a la operación'));
 assert.ok(html.includes('La máquina no tiene coordenadas válidas.'));
 assert.equal(JSON.stringify(props), original);
 for (const title of ['Última sincronización y resultados', 'Filtros avanzados', 'Detalle técnico']) expectClosed(html, title);
 const source = await readFile('resources/js/Pages/VendingMachines/Index.vue', 'utf8');
 assert.ok(!source.includes('SYBIML'), 'Confirmations and edit hints share the visible name');
});
test('external catalog review preserves the operational page heading and its route contract', async () => {
 const html = await renderVue('resources/js/Pages/VendingMachines/Index.vue', catalogProps('operational'), operator);
 assert.ok(/<h1[^>]*>Máquinas vending<\/h1>/.test(html));
 assert.ok(!html.includes('Panel de control'));
 const nav = visibleNavigation(operator).flatMap(group => group.items);
 assert.ok(nav.some(item => item.label === 'Catálogo SYBI' && item.routeName === 'vending-machines.index' && item.params.catalog_view === 'sybi'));
 assert.ok(nav.some(item => item.label === 'Alertas' && item.routeName === 'vending-fleet.dashboard' && item.hash === 'alertas'));
});
test('external sidebar review uses short complete descriptions without changing visibility', async () => {
 for (const permissions of [operator, viewer, { settings: ['manage'], employees: ['view'] }]) {
  const nav = visibleNavigation(permissions).flatMap(group => group.items);
  const html = await renderVue('resources/js/Layouts/AuthenticatedLayout.vue', {}, permissions);
  for (const item of nav) {
   assert.ok(item.description.length <= 24, item.label + ' description stays concise');
   assert.ok(!item.description.includes('...') && !item.description.includes('…'));
   assert.ok(html.includes(item.description), item.description);
  }
  assert.equal(nav.find(item => item.label === 'Dispositivos').description, 'Conexión y estado');
  assert.equal(nav.find(item => item.label === 'Alertas').description, 'Incidencias');
 }
});
test('external sidebar review permits complete wrapping without widening the sidebar or removing tooltips', async () => {
 const css = await readFile('resources/css/app.css', 'utf8');
 for (const selector of ['sidebar-item-label', 'sidebar-item-description', 'sidebar-group-title']) {
  const rule = css.match(new RegExp('\\.' + selector + '\\s*\\{([^}]+)\\}'))?.[1];
  assert.ok(rule, selector);
  assert.ok(!/truncate|text-overflow:\s*ellipsis|whitespace-nowrap|line-clamp/.test(rule), selector + ' must not clip text');
  assert.ok(rule.includes('whitespace-normal') && rule.includes('break-words'), selector + ' wraps');
 }
 const layout = await readFile('resources/js/Layouts/AuthenticatedLayout.vue', 'utf8');
 assert.ok(layout.includes("'w-72 px-6'"));
 assert.ok(layout.includes(':title="item.label"'));
});

test('external header review preserves specific summary and version headings; alerts remain a summary section', async () => {
 const dashboard = await renderVue('resources/js/Pages/VendingFleet/Dashboard.vue', {
  generated_at: '2026-09-05T18:48:23Z', kpis: {}, alerts: [], app_versions: [], last_sybi_sync: null,
  thresholds: { degraded_after_seconds: 180, offline_after_seconds: 600, clock_drift_seconds: 300, pending_events_count: 100 },
 }, operator);
 assert.ok(/<h1[^>]*>Resumen de operación<\/h1>/.test(dashboard));
 assert.ok(dashboard.includes('id="alertas"'));
 assert.ok(dashboard.includes('Lo que necesita atención'));
 const releases = await renderVue('resources/js/Pages/VendingFleet/Releases.vue', {
  releases: [], policies: [], platforms: ['ANDROID'], channels: ['PILOT'], statuses: ['DRAFT'],
  targetTypes: ['CHANNEL'], rolloutPercentages: [0, 100], canManage: false,
 }, operator);
 assert.ok(/<h1[^>]*>Versiones de aplicación<\/h1>/.test(releases));
 assert.ok(!dashboard.includes('Panel de control') && !releases.includes('Panel de control'));
});
