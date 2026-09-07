import test from 'node:test';
import assert from 'node:assert/strict';
import { visibleNavigation } from '../../resources/js/presentation/navigation.js';
import { renderVue } from './vueRender.mjs';
import { ref } from 'vue';
import { auditSubjectLabel } from '../../resources/js/presentation/audit.js';

// Matches the four existing pilot permission matrices; never grants new access.
const pilots = {
 Admin: { employees: ['view', 'sync', 'import', 'manage'], vending_machines: ['view', 'create', 'update', 'assign', 'geofence', 'manage'] },
 Operator: { employees: ['view', 'sync', 'import'], vending_machines: ['view', 'assign'] },
 Support: { employees: ['view'], vending_machines: ['view'] },
 Viewer: { employees: ['view'], vending_machines: ['view'] },
};
for (const [role, permissions] of Object.entries(pilots)) {
 test(`phase 12 ${role}: three task groups, unchanged links and no unavailable administration`, async () => {
  const groups = visibleNavigation(permissions);
  assert.deepEqual(groups.map(group => group.title), ['OPERACIÓN', 'INTEGRACIONES', 'ADMINISTRACIÓN']);
  assert.deepEqual(groups[0].items.map(item => item.label), ['Resumen', 'Máquinas', 'Dispositivos', 'Empleados', 'Alertas']);
  assert.deepEqual(groups[1].items.map(item => item.label), ['Catálogo SYBI']);
  assert.deepEqual(groups[2].items.map(item => item.label), ['Versiones de aplicación']);
  assert.equal(groups[0].items.find(item => item.label === 'Empleados').strict, true);
  assert.equal(groups[1].items[0].params.catalog_view, 'sybi');
  const html = await renderVue('resources/js/Layouts/AuthenticatedLayout.vue', {}, permissions);
  assert.ok(html.includes('INTEGRACIONES'));
  for (const hidden of ['PERSONAL', '>VENDING<', 'Roles y permisos', 'Auditoría', 'Configuración</span>']) assert.ok(!html.includes(hidden), hidden);
 });
}

test('phase 12 devices show real aggregate sync and pending values, including unknown', async () => {
 for (const [state, label, pending] of [['SYNCED', 'Sincronizado', 0], ['PENDING', 'Pendiente', 2], ['ERROR', 'Con error', null]]) {
  const props = {
   devices: { data: [{ uuid: 'synthetic-device', device_serial: 'DEMO', platform: 'ANDROID', last_heartbeat_at: null,
    pending_events_count: pending, fleet: { status: 'OFFLINE', manifest: { sync_state: state } },
   }], links: [] }, filters: {}, machines: [], statuses: [], platforms: [], appVersions: [], thresholds: {}, canManage: false,
  };
  const before = JSON.stringify(props);
  const html = await renderVue('resources/js/Pages/VendingFleet/Devices.vue', props, pilots.Viewer);
  const table = html.match(/<table[\s\S]*?<\/table>/)[0];
  assert.equal((table.match(/<th\b/g) || []).length, 7);
  assert.ok(table.includes(`>${label}</span>`));
  assert.ok(table.includes(`>${pending ?? 'Sin información'}</td>`));
  assert.ok(!table.includes('Versión instalada'));
  assert.ok(!table.includes('Canal de distribución'));
  assert.equal(JSON.stringify(props), before);
 }
});

test('dashboard keeps six primary KPI and the actual HIGH-first list without changing alerts', async () => {
 const alerts = [
  { severity: 'HIGH', type: 'DEVICE_OFFLINE', machine_code: 'REVISAR-PRIMERO', message: 'HEARTBEAT_EXPIRED' },
  { severity: 'MEDIUM', type: 'DEVICE_RETIRED', machine_code: 'HISTORICO', message: 'LIFECYCLE_RETIRED' },
 ];
 const before = JSON.stringify(alerts);
 const html = await renderVue('resources/js/Pages/VendingFleet/Dashboard.vue', {
  generated_at: '2026-09-07T12:00:00Z', kpis: {}, app_versions: [], alerts, thresholds: {}, last_sybi_sync: null,
 }, pilots.Operator);
 const primary = html.match(/<section[^>]*aria-label="Indicadores principales"[\s\S]*?<\/section>/)[0];
 assert.equal((primary.match(/<article\b/g) || []).length, 6);
 assert.ok(html.indexOf('REVISAR-PRIMERO') < html.indexOf('HISTORICO'));
 assert.ok(!/<details[^>]*\bopen/.test(html));
 assert.equal(JSON.stringify(alerts), before);
});

test('audit presents activity and resource names; identifiers and raw description stay behind detail', async () => {
 const logs = [{ id: 987, event: 'device.activated', auditable_type: 'App\\Models\\Device', auditable_id: 876,
  description: 'INTERNAL_DIAGNOSTIC', user: { name: 'Usuario de prueba' }, created_at_local: '07/09/2026 00:00:00',
 }];
 const html = await renderVue('resources/js/Pages/Settings/Audit/Index.vue', { users: [] }, { audit: ['view'] }, {
  modules: { vue: { ref: value => ref(Array.isArray(value) && value.length === 0 ? logs : value) } },
 });
 assert.ok(html.includes('Dispositivo habilitado'));
 assert.ok(html.includes('>Dispositivo</span>') || html.includes('Dispositivo'));
 assert.ok(html.includes('Elemento afectado'));
 assert.equal((html.match(/<th\b/g) || []).length, 5);
 assert.ok(!html.includes('min-w-[72rem]'));
 assert.ok(html.includes('Conservación de auditoría'));
 assert.ok(!/<details[^>]*\bopen/.test(html));
 for (const hidden of ['device.activated', 'App\\Models\\Device', 'INTERNAL_DIAGNOSTIC', '#876']) assert.ok(!html.includes(hidden), hidden);
});

test('audit unknown resources are safe generic text, never internal class names', () => {
 assert.equal(auditSubjectLabel('App\\Models\\VendingMachine'), 'Máquina vending');
 for (const unknown of [null, 'constructor', '__proto__', 'SecretInternalModel']) assert.equal(auditSubjectLabel(unknown), 'Registro');
});

test('final round: reported connectivity does not replace derived health or imply a live probe', async () => {
 for (const status of ['DEGRADED', 'OFFLINE']) {
  const device = { uuid: 'demo', device_serial: 'DEMO', network_state: 'ONLINE',
   fleet: { status, reasons: status === 'DEGRADED' ? ['RECENT_NETWORK'] : ['HEARTBEAT_EXPIRED'], manifest: { sync_state: 'SYNCED' } },
  };
  const before = JSON.stringify(device);
  const html = await renderVue('resources/js/Pages/VendingFleet/Devices.vue', {
   devices: { data: [device], links: [] }, filters: {}, machines: [], statuses: [], platforms: [], appVersions: [], thresholds: {},
  }, pilots.Viewer);
  assert.ok(html.includes(status === 'DEGRADED' ? 'Con incidencias' : 'Sin conexión'));
  assert.ok(html.includes('Red reportada: En línea'));
  assert.equal(html.includes('Incidencia reciente de red'), status === 'DEGRADED');
  assert.equal(JSON.stringify(device), before);
 }
});

test('final round: connected KPI retains the server zero and explains the incident criterion', async () => {
 const html = await renderVue('resources/js/Pages/VendingFleet/Dashboard.vue', {
  generated_at: '2026-09-07T15:20:44Z', kpis: { devices_online: 0, devices_degraded: 1 },
  app_versions: [], alerts: [], thresholds: {}, last_sybi_sync: null,
 }, pilots.Operator);
 const kpi = html.match(/<article[^>]*><h2[^>]*>Dispositivos conectados[\s\S]*?<\/article>/)[0];
 assert.match(kpi, />0<\/p>/);
 assert.ok(kpi.includes('Una incidencia reciente puede excluir un dispositivo'));
 assert.ok(html.includes('09:20'));
});

const machineProps = (view, latest = null) => ({
 machines: { data: [], links: [] }, sourceRecords: { data: [], links: [] }, filters: { catalog_view: view },
 statuses: [], coordinateSources: [], catalogSources: [], syncStatuses: [], sourceStatuses: [],
 validationStatuses: [], municipalities: [], localities: [],
 sybiIntegration: { configured: true, latest_run: latest, manual_creation_allowed: false },
});
test('final round: machines summarize actual source-run metrics, while SYBI retains management', async () => {
 const latest = { finished_at: '2026-09-07T12:00:00Z', operational_ready: 12, operational_incomplete: 3, operational_conflicts: 2 };
 const props = machineProps('operational', latest), before = JSON.stringify(props);
 const html = await renderVue('resources/js/Pages/VendingMachines/Index.vue', props, pilots.Admin);
 assert.ok(html.includes('Ver catálogo SYBI'));
 assert.ok(html.includes('catalog_view'));
 for (const count of [12, 3, 2]) assert.ok(html.includes(`<dd>${count}</dd>`));
 assert.ok(html.includes('no representan un conteo en tiempo real'));
 assert.ok(!html.includes('Sincronizar ahora'));
 assert.equal(JSON.stringify(props), before);
 const catalog = await renderVue('resources/js/Pages/VendingMachines/Index.vue', machineProps('sybi', latest), pilots.Admin);
 assert.ok(catalog.includes('Sincronizar ahora'));
 assert.ok(catalog.includes('Última sincronización y resultados'));
 assert.ok(!catalog.includes('Resumen de fuente SYBI'));
 const viewer = await renderVue('resources/js/Pages/VendingMachines/Index.vue', machineProps('sybi'), pilots.Viewer);
 assert.ok(!viewer.includes('Sincronizar ahora'));
});
test('final round: absent source-run data never becomes zero machines', async () => {
 const html = await renderVue('resources/js/Pages/VendingMachines/Index.vue', machineProps('operational'), pilots.Viewer);
 assert.ok(html.includes('Sin ejecuciones'));
 assert.equal((html.match(/<dd>Sin información<\/dd>/g) || []).length, 3);
});

test('final round: local source without a timestamp does not suggest missing Fortia sync; real sync dates stay visible', async () => {
 for (const [source, timestamp, expected] of [
  ['DEMO', null, 'No aplica a Fortia'], ['MANUAL', null, 'No aplica a Fortia'],
  ['FORTIA', null, 'Sin sincronización'], ['LEGACY', null, 'Sin sincronización'],
  ['DEMO', '2026-09-07T12:00:00Z', '06:00'], ['FORTIA', '2026-09-07T12:00:00Z', '06:00'],
 ]) {
  const employee = { id: 1, source, source_synced_at: timestamp, employee_number: 'TEST', full_name: 'Prueba', status: 'A' };
  const before = JSON.stringify(employee);
  const html = await renderVue('resources/js/Pages/Employees/VendingCatalog.vue', {
   employees: { data: [employee], links: [] }, filters: {}, fortia: { driver: 'mock', real_api_ready: false }, capabilities: {}, limits: {},
  }, pilots.Viewer);
  assert.ok(html.includes(expected), `${source}/${timestamp}`);
  if (timestamp) assert.ok(!html.includes('No aplica a Fortia'));
  assert.equal(JSON.stringify(employee), before);
 }
});
