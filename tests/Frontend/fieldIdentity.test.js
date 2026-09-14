import test from 'node:test';
import assert from 'node:assert/strict';
import { renderVue } from './vueRender.mjs';
import { visibleNavigation } from '../../resources/js/presentation/navigation.js';
import { readFile } from 'node:fs/promises';

const props = { tab: 'summary', counts: { ACTIVE: 0, PENDING: 2, REVOKED: 1, REPLACED: 0 }, devices: null, employees: null, canManage: false };
test('identity navigation uses only its explicit permission', () => {
    for (const matrix of [{support:['manage']}, {settings:['manage']}, {}]) {
        assert.ok(!visibleNavigation(matrix).flatMap(g => g.items).some(i => i.routeName === 'field-identity.admin.index'));
    }
    assert.ok(visibleNavigation({employee_device:['view']}).flatMap(g => g.items).some(i => i.routeName === 'field-identity.admin.index'));
});
test('summary presents real numbers and no enabled biometrics', async () => {
    const html = await renderVue('resources/js/Pages/FieldIdentity/Index.vue', props);
    assert.match(html, /Pendiente de selección/); assert.match(html, /No disponible todavía/); assert.match(html, />2<\/p>/);
});

test('identity tabs have distinct existing routes and keep terminal navigation separate', () => {
    const groups = visibleNavigation({employee_device:['view'], vending_machines:['view']});
    const identity = groups.find(g => g.key === 'identidad');
    assert.deepEqual(identity.items.map(i => i.params.tab), ['summary','devices','enrollments']);
    assert.ok(identity.items.every(i => i.strict && i.routeName === 'field-identity.admin.index'));
    assert.equal(groups.find(g => g.key === 'operacion').items.find(i => i.label === 'Dispositivos').description, 'Terminales de máquinas');
});

test('version administration presents approved candidate separately without claiming publication', async () => {
    const beta = JSON.parse(await readFile('mobile/internal-beta.json','utf8'));
    const html = await renderVue('resources/js/Pages/VendingFleet/Releases.vue', {releases:[],policies:[],platforms:[],channels:[],statuses:[],targetTypes:[],rolloutPercentages:[],canManage:false});
    assert.match(html, /candidata de revisión/);
    assert.match(html, /Metadata preparada, no publicada/);
    assert.ok(html.includes(beta.version)); assert.ok(html.includes('compilación ' + beta.build));
    assert.doesNotMatch(html, /Registrar versión<\/summary>/);
});
test('device privacy and Spanish, no false phone verification or exposed enums', async () => {
    const html = await renderVue('resources/js/Pages/FieldIdentity/Index.vue', {...props,tab:'devices',devices:{data:[{uuid:'synthetic-uuid', employee:'Técnico', number:'DEMO',model:'HONOR',status:'ACTIVE',phone:'•••• 0000',simulation:true,crypto_verified:true,last_seen_at:'2026-09-09T18:00:00Z'}],links:[]}});
    for (const text of ['Demo local','Firma comprobada','Activo']) assert.ok(html.includes(text));
    for (const text of ['synthetic-uuid','ACTIVE','LOCAL_SIMULATED','2026-09-09T','Revocar dispositivo']) assert.ok(!html.includes(text));
    assert.doesNotMatch(html, /<details[^>]*open/);
});
test('enrollment absence and legacy evidence are distinguished', async () => {
    const html = await renderVue('resources/js/Pages/FieldIdentity/Index.vue', {...props,tab:'enrollments',employees:{data:[{id:1,name:'Uno',legacy_enrollment:false},{id:2,name:'Dos',legacy_enrollment:true}],links:[]}});
    assert.match(html,/No enrolado/); assert.match(html,/Registro previo/); assert.match(html,/Motor: No habilitado/);
});
