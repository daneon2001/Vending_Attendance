import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { labels, statusLabel, friendlyError, formatDateTime, advancedDeviceKeys, activeFilterCount, isFortiaMock, paginationLabel } from '../../resources/js/presentation/labels.js';
import { canUse, visibleNavigation } from '../../resources/js/presentation/navigation.js';
import { emptyImportState, receivePreview, navigateImport, canApplyImport, confirmationPayload, importSteps } from '../../resources/js/Pages/Employees/importState.js';
import { renderVue } from './vueRender.mjs';

const roles = {
 Admin:{employees:['view','sync','import','manage'],vending_machines:['view','create','update','assign','geofence','manage']},
 Operator:{employees:['view','sync','import'],vending_machines:['view','assign']},
 Support:{employees:['view'],vending_machines:['view']},
 Viewer:{employees:['view'],vending_machines:['view']},
};
test('central labels preserve input codes and expose Spanish',()=>{
 for(const [code,label] of Object.entries(labels)){assert.equal(statusLabel(code),label);assert.equal(typeof code,'string');}
 assert.equal(statusLabel('UNKNOWN'),'Sin información');
 assert.equal(statusLabel('NEW_SERVER_ENUM'),'Sin información');
 assert.equal(statusLabel('OFFLINE'),'Sin conexión');
 assert.equal(statusLabel('READY'),'Lista para operar');
});
test('login and source diagnostics use friendly Spanish with safe unknown fallback',async()=>{
 assert.equal(friendlyError('auth.failed'),'Correo o contraseña incorrectos.');
 assert.equal(friendlyError('ZERO_COORDINATES'),'La máquina no tiene coordenadas válidas.');
 assert.equal(friendlyError('DUPLICATE_VENDING_IDENTIFIER'),'El identificador está asociado a más de una máquina.');
 assert.equal(friendlyError('SOURCE_EMPTY_UNEXPECTED'),'No fue posible obtener información válida de la fuente.');
 assert.doesNotMatch(friendlyError('UNRECOGNIZED_ERROR'),/UNRECOGNIZED_ERROR/);
 const html=await renderVue('resources/js/Components/InputError.vue',{message:'auth.failed'});
 assert.match(html,/Correo o contraseña incorrectos/);assert.doesNotMatch(html,/auth.failed/);
});
test('dates use Mexico City independently of browser timezone and keep absent data honest',()=>{
 assert.equal(formatDateTime('2026-09-05T18:48:23.000000Z'),'05/09/2026 12:48');
 assert.equal(formatDateTime('2026-09-05 18:48:23'),'05/09/2026 12:48');
 assert.equal(formatDateTime('2026-09-05T02:00:00Z'),'04/09/2026 20:00');
 assert.equal(formatDateTime('2026-09-05'),'05/09/2026');
 for(const value of [null,'','not-a-date']) assert.equal(formatDateTime(value),'Sin información');
});
test('four pilot roles only see usable navigation; strict employee permission has no settings override',()=>{
 for(const matrix of Object.values(roles)){
  const nav=visibleNavigation(matrix).flatMap(group=>group.items);
  assert.ok(nav.some(item=>item.label==='Resumen'));
  assert.ok(nav.some(item=>item.label==='Empleados'));
  assert.ok(nav.some(item=>item.label==='Catálogo SYBI'&&item.params.catalog_view==='sybi'));
  assert.ok(nav.every(item=>!['settings.index','clocks.index','dashboard','admin.asistencias.index'].includes(item.routeName)));
 }
 assert.deepEqual(visibleNavigation({}),[]);
 assert.equal(canUse({settings:['manage']},'vending_machines','update'),true);
 assert.equal(canUse({settings:['manage']},'employees','view',true),false);
 assert.equal(visibleNavigation({settings:['manage']}).flatMap(g=>g.items).some(i=>i.label==='Empleados'),false);
});
test('advanced filters are collapsed, count all eight keys and do not change values',async()=>{
 const filters=Object.fromEntries(advancedDeviceKeys.map(key=>[key,'']));
 filters.platform='ANDROID';filters.sync_state='PENDING';filters.search='VM-DEMO';
 assert.equal(activeFilterCount(filters,advancedDeviceKeys),2);
 const html=await renderVue('resources/js/Components/AdvancedFilters.vue',{count:2});
 assert.match(html,/Filtros avanzados/);assert.match(html,/\(2\)/);assert.doesNotMatch(html,/<details[^>]*\bopen/);
 assert.equal(filters.platform,'ANDROID');assert.equal(filters.sync_state,'PENDING');
 assert.equal(paginationLabel('&laquo; Previous'),'Anterior');
});
test('wizard has five steps; preview, backtracking and refresh never authorize application',()=>{
 assert.equal(importSteps.length,5);
 let state=emptyImportState();
 assert.equal(navigateImport(state,4).step,1);
 const preview={uuid:'preview',preview_hash:'a'.repeat(64),status:'PREVIEW',summary:{valid_new:1,valid_update:0}};
 state=receivePreview(state,preview);assert.equal(state.step,2);assert.equal(canApplyImport(state),false);
 state=navigateImport(state,3);state=navigateImport(state,4);assert.equal(state.confirmed,false);
 assert.throws(()=>confirmationPayload(state));
 state.confirmed=true;assert.deepEqual(confirmationPayload(state),{confirmed:true,preview_hash:preview.preview_hash});
 state=navigateImport(state,3);assert.equal(canApplyImport(state),false);
 state=receivePreview(state,preview);assert.equal(state.step,3);
 state=receivePreview(state,{...preview,status:'COMPLETED'});assert.equal(state.step,5);assert.equal(canApplyImport(state),false);
 assert.equal(navigateImport(state,1).step,5);
});
const employeeProps={employees:{data:[],current_page:1,last_page:1,total:0},filters:{},fortia:{driver:'mock',source:'DEMO',real_api_ready:false,write_enabled:false},limits:{file_kb:5120,rows:5000}};
for(const [role,matrix] of Object.entries(roles)){
 test(role+' employee actions follow capabilities and always disclose Fortia mock',async()=>{
  const html=await renderVue('resources/js/Pages/Employees/VendingCatalog.vue',{...employeeProps,capabilities:{import:canUse(matrix,'employees','import',true),sync:canUse(matrix,'employees','sync',true)}},matrix);
  assert.match(html,/Fortia[\s\S]*Fuente de prueba/);
  assert.equal(html.includes('Importar empleados'),['Admin','Operator'].includes(role));
  assert.equal(html.includes('Consultar cambios'),['Admin','Operator'].includes(role));
  assert.doesNotMatch(html,/driver mock|dry-run|auth.failed/);
 });
 test(role+' machine detail only renders authorized mutation controls',async()=>{
  const html=await renderVue('resources/js/Pages/VendingMachines/Show.vue',{machine:{uuid:'machine',machine_code:'VM-DEMO-001',latitude:19,longitude:-99,geofences:[],devices:[],assignments:[],provisioning_tokens:[]},auditLogs:[],employees:[],assignmentTypes:['PRIMARY'],geofenceStatuses:['DRAFT','ACTIVE']},matrix);
  assert.equal(html.includes('Generar código de activación'),role==='Admin');
  assert.equal(html.includes('Nueva geocerca circular'),role==='Admin');
  assert.equal(html.includes('Asignar empleado'),['Admin','Operator'].includes(role));
 });
}
test('Fortia mock is based on backend driver, not an invented production claim',()=>{
 assert.equal(isFortiaMock({driver:'mock'}),true);
 assert.equal(isFortiaMock({driver:'http'}),false);
 assert.equal(isFortiaMock({}),false);
});
test('device page renders seven columns with original select values and human states',async()=>{
 const html=await renderVue('resources/js/Pages/VendingFleet/Devices.vue',{devices:{data:[],links:[]},filters:{},machines:[{id:1,machine_code:'VM-DEMO-001'}],statuses:['ACTIVE','REVOKED'],platforms:['ANDROID'],appVersions:['1.0'],thresholds:{},errorCategories:[],canManage:false},roles.Viewer);
 assert.equal((html.match(/<th\b/g)||[]).length,7);
 assert.match(html,/<option value="ACTIVE">Activo<\/option>/);
 assert.match(html,/<option value="ANDROID">Android<\/option>/);
 assert.doesNotMatch(html,/105rem|Device Registry|Lifecycle|Heartbeat|Outbox/);
 assert.doesNotMatch(html,/<details[^>]*\bopen/);
});
test('login branding excludes unsupported legacy claims and English headings',async()=>{
 const html=await renderVue('resources/js/Pages/Auth/Login.vue',{canResetPassword:true});
 assert.match(html,/Vending Attendance/);assert.match(html,/Medical Life/);
 assert.doesNotMatch(html,/120\+|clínicas|Sanctum|biométricos/);
});
test('all Vue pages compile, including screens outside the main demo',async()=>{
 const {parse,compileScript,compileTemplate}=await import('@vue/compiler-sfc');
 const {readdir}=await import('node:fs/promises');
 async function scan(dir){for(const entry of await readdir(dir,{withFileTypes:true})){const file=dir+'/'+entry.name;if(entry.isDirectory())await scan(file);else if(file.endsWith('.vue')){const {descriptor,errors}=parse(await readFile(file,'utf8'),{filename:file});assert.deepEqual(errors,[],file);if(descriptor.script||descriptor.scriptSetup)compileScript(descriptor,{id:file});const compiled=compileTemplate({id:file,filename:file,source:descriptor.template?.content??''});assert.deepEqual(compiled.errors,[],file);}}}
 await scan('resources/js');
});

test('real authenticated layout initializes and renders pilot task navigation',async()=>{
 const html = await renderVue('resources/js/Layouts/AuthenticatedLayout.vue',{},roles.Viewer);
 assert.match(html,/Resumen/);assert.match(html,/Catálogo SYBI/);assert.doesNotMatch(html,/Opensync|Panel general|Relojes biom/);
});
for(const [role,matrix] of Object.entries(roles)) {
 test(role+' machine catalog hides unavailable create, edit and sync actions',async()=>{
  const html=await renderVue('resources/js/Pages/VendingMachines/Index.vue',{
   machines:{data:[{uuid:'m',machine_code:'VM-DEMO-001',status:'ACTIVE',source:'DEMO',active_assignments_count:0}],links:[]},
   sourceRecords:{data:[],links:[]},filters:{},statuses:['ACTIVE'],coordinateSources:[],catalogSources:[],syncStatuses:[],sourceStatuses:[],validationStatuses:[],municipalities:[],localities:[],
   sybiIntegration:{manual_creation_allowed:true,configured:true,automatic_sync_enabled:false},
  },matrix);
  for(const action of ['Nueva máquina','>Editar<'])assert.equal(html.includes(action),role==='Admin',action);
  assert.ok(!html.includes('Sincronizar ahora'), 'Source synchronization stays on the SYBI catalog view');
  assert.ok(html.includes('Ver catálogo SYBI'));
  assert.doesNotMatch(html,/88rem/);
 });
 test(role+' releases expose administration only to allowed management',async()=>{
  const html=await renderVue('resources/js/Pages/VendingFleet/Releases.vue',{releases:[],policies:[],platforms:['ANDROID'],channels:['PILOT'],statuses:['DRAFT'],targetTypes:['CHANNEL'],rolloutPercentages:[0,100],canManage:canUse(matrix,'vending_machines','manage')},matrix);
  assert.equal(html.includes('Registrar versión'),role==='Admin');
  assert.doesNotMatch(html,/<details[^>]*\bopen/);
 });
}
test('SYBI explains invalid coordinates and keeps exact diagnostic in technical details',async()=>{
 const html=await renderVue('resources/js/Pages/VendingMachines/Index.vue',{
 machines:{data:[],links:[]},sourceRecords:{data:[{uuid:'record',name:'Máquina de prueba',source_status:'PRESENT',validation_status:'INCOMPLETE_LOCATION',validation_codes:['ZERO_COORDINATES']}],links:[]},filters:{catalog_view:'sybi'},
 statuses:[],coordinateSources:[],catalogSources:[],syncStatuses:[],sourceStatuses:['PRESENT'],validationStatuses:['INCOMPLETE_LOCATION'],municipalities:[],localities:[],sybiIntegration:{configured:false},
 },roles.Viewer);
 assert.match(html,/La máquina no tiene coordenadas válidas/);assert.match(html,/Ubicación incompleta/);
 assert.match(html,/<summary[^>]*>Detalle técnico<\/summary>[\s\S]*ZERO_COORDINATES/);
});


const reviewedDashboardProps = (alerts = []) => ({
 generated_at: '2026-09-05T18:48:23Z',
 kpis: { machines_operational: 3, devices_online: 0, devices_active: 1, devices_offline: 1, employees_assigned: 5, attendance_today: 2, pending_edge_events: 0 },
 alerts,
 app_versions: [],
 last_sybi_sync: null,
 thresholds: { degraded_after_seconds: 180, offline_after_seconds: 600, clock_drift_seconds: 300, pending_events_count: 100 },
});
const reviewedAlert = (severity, index = 0) => ({
 severity, type: 'DEVICE_OFFLINE', machine_code: 'VM-DEMO-001', device_uuid: 'review-device-' + index, message: 'HEARTBEAT_EXPIRED',
});
const renderReviewedDashboard = (props) => renderVue('resources/js/Pages/VendingFleet/Dashboard.vue', props, roles.Operator);
const activeAlertsCard = (html) => {
 const card = html.match(/<article\b[\s\S]*?<\/article>/g)?.find(article => article.includes('>Alertas activas</h2>'));
 assert.ok(card, 'The sixth KPI must be Alertas activas');
 return card;
};

test('reviewed dashboard has numeric alert KPI, severity summary and review action', async () => {
 const props = reviewedDashboardProps([reviewedAlert('HIGH'), reviewedAlert('MEDIUM', 1)]);
 const original = JSON.stringify(props);
 const html = await renderReviewedDashboard(props);
 const card = activeAlertsCard(html);
 assert.match(card, /<p[^>]*text-3xl[^>]*>2<\/p>/);
 assert.match(card, /1 alta · 1 media/);
 assert.match(card, /<a[^>]*href="#alertas"[^>]*>Revisar alertas<\/a>/);
 assert.match(card, /Conteo de la lista recibida; puede haber más alertas\./);
 assert.doesNotMatch(card, /Revisar incidencias/);
 assert.equal(JSON.stringify(props), original, 'Presentation must not mutate server data');
});

test('reviewed dashboard alert KPI uses zero for an empty received list', async () => {
 const card = activeAlertsCard(await renderReviewedDashboard(reviewedDashboardProps()));
 assert.match(card, /<p[^>]*text-3xl[^>]*>0<\/p>/);
 assert.doesNotMatch(card, /\d+ altas? · \d+ medias?/);
 assert.match(card, /Revisar alertas/);
});

test('reviewed dashboard never extrapolates a limited alert list into a global total', async () => {
 const props = reviewedDashboardProps(Array.from({ length: 100 }, (_, i) => reviewedAlert(i < 60 ? 'HIGH' : 'MEDIUM', i)));
 props.kpis.devices_active = 500;
 const card = activeAlertsCard(await renderReviewedDashboard(props));
 assert.match(card, /<p[^>]*text-3xl[^>]*>100<\/p>/);
 assert.match(card, /60 altas · 40 medias/);
 assert.match(card, /puede haber más alertas/);
 assert.doesNotMatch(card, />500</);
});

test('reviewed dashboard separates enabled lifecycle from connected health without changing numbers', async () => {
 const html = await renderReviewedDashboard(reviewedDashboardProps());
 assert.match(html, />Dispositivos conectados<\/h2><p[^>]*>0<\/p>/);
 assert.match(html, />Dispositivos habilitados<\/dt><dd[^>]*>1<\/dd>/);
 assert.match(html, />Sin conexión<\/dt><dd[^>]*>1<\/dd>/);
 assert.doesNotMatch(html, />Dispositivos activos</);
});

test('reviewed dashboard secondary indicators and version criteria remain collapsed by default', async () => {
 const html = await renderReviewedDashboard(reviewedDashboardProps([reviewedAlert('HIGH')]));
 const panels = [...html.matchAll(/<details\b([^>]*)>\s*<summary[^>]*>([^<]*)<\/summary>/g)];
 for (const title of ['Más indicadores de operación', 'Versiones y criterios de conexión', 'Detalle técnico']) {
  const panel = panels.find(match => match[2] === title);
  assert.ok(panel, title);
  assert.doesNotMatch(panel[1], /\bopen(?:\s|=|$)/, title + ' must start closed');
 }
 assert.match(html, /Lo que necesita atención/);
 assert.match(html, /Criterios vigentes/);
});

test('human duration helper formats exact seconds, minutes and hours in Spanish', async () => {
 const { formatDurationSeconds } = await import('../../resources/js/presentation/labels.js');
 for (const [seconds, label] of [
  [180, '3 minutos'], [600, '10 minutos'], [300, '5 minutos'], ['180', '3 minutos'],
  [0, '0 segundos'], [1, '1 segundo'], [59, '59 segundos'], [60, '1 minuto'],
  [61, '1 minuto y 1 segundo'], [90, '1 minuto y 30 segundos'],
  [3600, '1 hora'], [7200, '2 horas'], [3661, '1 hora, 1 minuto y 1 segundo'],
 ]) assert.equal(formatDurationSeconds(seconds), label);
});

test('human duration helper does not present missing or invalid values as zero', async () => {
 const { formatDurationSeconds } = await import('../../resources/js/presentation/labels.js');
 for (const value of [null, undefined, '', ' ', false, {}, [], -1, NaN, Infinity, 'invalid', 1.5]) {
  assert.equal(formatDurationSeconds(value), 'Sin información');
 }
});

test('reviewed dashboard formats only time thresholds and preserves original seconds', async () => {
 const props = reviewedDashboardProps();
 const original = { ...props.thresholds };
 const html = await renderReviewedDashboard(props);
 assert.match(html, /Con demora después de<\/dt><dd>3 minutos<\/dd>/);
 assert.match(html, /Sin conexión después de<\/dt><dd>10 minutos<\/dd>/);
 assert.match(html, /Diferencia de hora<\/dt><dd>5 minutos<\/dd>/);
 assert.match(html, /Registros pendientes<\/dt><dd>100<\/dd>/);
 assert.doesNotMatch(html, />(180|600|300) s</);
 assert.deepEqual(props.thresholds, original);
});
