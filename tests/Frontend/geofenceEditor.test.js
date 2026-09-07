import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { renderVue } from './vueRender.mjs';
import * as geo from '../../resources/js/presentation/geofenceEditor.js';
import * as labels from '../../resources/js/presentation/labels.js';

const limits = {
 radius_m: { min: 1, max: 100000 }, minimum_acceptable_accuracy_m: { min: 0, max: 100000 }, tolerance_m: { min: 0, max: 100000 },
};
const circle = { uuid: 'internal-zone', version: 1, status: 'ACTIVE', center_latitude: 19.4, center_longitude: -99.1, radius_m: 50, minimum_acceptable_accuracy_m: 25, tolerance_m: 5 };
const machine = () => ({ uuid: 'machine-editor', config_version: 2, latitude: 19.4, longitude: -99.1, coordinates_verified: false, default_geofence_radius_m: 40, geofences: [{ ...circle }] });
const editor = { limits, source_location: { latitude: 19.4, longitude: -99.1 } };

// Component events/state rendered without a browser. Native input directives are stubbed;
// assertions drive their update:modelValue contract, not simulated physical input.
async function mountEditor({ canEdit = true, empty = false } = {}) {
 const data = Vue.reactive(machine()); if (empty) data.geofences = [];
 const forms = [], requests = [];
 const useForm = values => {
  const keys = Object.keys(values), initial = { ...values };
  let transform = value => value;
  const form = Vue.reactive({ ...values, errors: {}, processing: false,
   clearErrors() { form.errors = {}; }, reset() { Object.assign(form, initial); },
   transform(fn) { transform = fn; return form; },
   post(url, options) { requests.push({ method: 'post', url, data: transform(Object.fromEntries(keys.map(k => [k, form[k]]))) }); options?.onSuccess?.(); },
   patch(url, options) { requests.push({ method: 'patch', url, data: transform(Object.fromEntries(keys.map(k => [k, form[k]]))) }); options?.onSuccess?.(); },
  });
  forms.push(form); return form;
 };
 const source = await readFile('resources/js/Components/GeofenceEditor.vue', 'utf8');
 let code = compileScript(parse(source).descriptor, { id: 'editor-test', inlineTemplate: true }).content;
 const modules = [], route = (name, params) => name + ':' + JSON.stringify(params);
 for (const match of [...code.matchAll(/import\s+([\s\S]*?)\s+from\s+['"]([^'"]+)['"];?/g)]) {
  const [, binding, spec] = match;
  let module;
  if (spec === 'vue') module = { ...Vue, vModelText: {}, vModelSelect: {} };
  else if (spec === '@inertiajs/vue3') module = { useForm };
  else if (spec.endsWith('/geofenceEditor')) module = geo;
  else if (spec.endsWith('/labels')) module = labels;
  else if (spec.endsWith('/GeofenceMap.vue')) module = { default: Vue.defineComponent({
   props: ['editable'], emits: ['select-center'],
   setup: (p, { emit }) => () => Vue.h('button', { disabled: !p.editable, onClick: () => emit('select-center', { latitude: 19.5, longitude: -99.2 }) }, 'Seleccionar centro sintético'),
  }) };
  else if (spec.endsWith('/TechnicalDetails.vue')) module = { default: { setup: (_, { slots }) => () => Vue.h('details', [Vue.h('summary', 'Detalle técnico'), slots.default?.()]) } };
  else if (spec.endsWith('/StatusBadge.vue')) module = { default: { props: ['value'], setup: p => () => Vue.h('span', labels.statusLabel(p.value)) } };
  else throw new Error('Unmocked module ' + spec);
  const index = modules.push(module) - 1;
  code = code.replace(match[0], binding.trim().startsWith('{')
   ? 'const ' + binding.replace(/\bas\b/g, ':') + ' = __imports[' + index + '];'
   : 'const ' + binding + ' = __imports[' + index + '].default;');
 }
 const node = (type, text = '') => ({ type, text, children: [], props: {}, parent: null, focus() {} });
 const renderer = Vue.createRenderer({
  createElement: type => node(type), createText: text => node('#text', text), createComment: text => node('#comment', text),
  setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
  parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
  patchProp: (el, key, _old, value) => { el.props[key] = value; },
  insert(el, parent, anchor = null) {
   if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1);
   const index = anchor ? parent.children.indexOf(anchor) : -1;
   parent.children.splice(index < 0 ? parent.children.length : index, 0, el); el.parent = parent;
  },
  remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
 });
 const root = node('root');
 const app = renderer.createApp(new Function('__imports', 'route', code.replace('export default', 'return'))(modules, route), { machine: data, editor, canEdit });
 app.config.globalProperties.route = route;
 app.mount(root);
 const all = () => { const result = []; const walk = n => { result.push(n); n.children.forEach(walk); }; walk(root); return result; };
 const text = n => n.type === '#comment' ? '' : n.text + n.children.map(text).join('');
 async function click(label) {
  const button = all().find(n => n.type === 'button' && text(n) === label);
  assert.ok(button, 'Button exists: ' + label); assert.ok(!button.props.disabled);
  button.props.onClick(); await Vue.nextTick();
 }
 async function submit() {
  all().find(n => n.type === 'form').props.onSubmit({ preventDefault() {} }); await Vue.nextTick();
 }
 return { data, requests, forms, all, click, submit, text: () => text(root), close: () => app.unmount() };
}

test('geofence coordinate, distance and area/perimeter presentation uses real values', () => {
 assert.equal(geo.point('', ''), null); assert.equal(geo.point(0, 0), null);
 assert.equal(geo.point(91, -99), null); assert.equal(geo.point(19, 181), null);
 assert.deepEqual(geo.point('0', '20'), { latitude: 0, longitude: 20 });
 assert.equal(geo.formatDistance(43.2), '43 m'); assert.equal(geo.formatDistance(1250), '1.3 km');
 assert.equal(geo.formatDistance(20962.85), '21 km');
 assert.equal(geo.circleMeasures(10).area, Math.PI * 100);
 assert.equal(geo.circleMeasures(10).perimeter, Math.PI * 20);
 assert.equal(geo.formatArea(1000000), '1 km²');
 assert.equal(geo.accuracyLabel(null), 'Sin requisito configurado');
 assert.equal(geo.accuracyLabel(14.5), '15 m');
 assert.equal(geo.presentationDistance(geo.point(19, -99), geo.point(19, -99)), 0);
});
test('drafts never mutate source and use only existing backend limits', () => {
 const source = machine(), before = JSON.stringify(source);
 const draft = geo.createGeofenceDraft(source, source.geofences[0]);
 draft.center_latitude = 20; draft.radius_m = 100;
 assert.equal(JSON.stringify(source), before);
 assert.deepEqual(geo.draftErrors(draft, limits), {});
 assert.ok(geo.draftErrors({ ...draft, radius_m: 100001 }, limits).radius_m);
 assert.ok(geo.draftErrors({ ...draft, radius_m: 1.5 }, limits).radius_m);
 assert.ok(geo.draftErrors({ ...draft, tolerance_m: -1 }, limits).tolerance_m);
 assert.ok(geo.draftErrors({ ...draft, minimum_acceptable_accuracy_m: 100001 }, limits).minimum_acceptable_accuracy_m);
 assert.equal(geo.createGeofenceDraft(source).minimum_acceptable_accuracy_m, '');
});
test('view renders empty state, Spanish labels, technical disclosure and permission-controlled actions', async () => {
 const props = { machine: { ...machine(), geofences: [] }, editor, canEdit: false };
 let html = await renderVue('resources/js/Components/GeofenceEditor.vue', props);
 assert.match(html, /todavía no tiene una geocerca configurada/);
 assert.ok(!html.includes('Crear geocerca')); assert.ok(!html.includes('Guardar geocerca'));
 assert.match(html, /Cargar mapa/); assert.match(html, /OpenStreetMap recibirá/);
 assert.ok(!/<details[^>]*\bopen/.test(html));
 html = await renderVue('resources/js/Components/GeofenceEditor.vue', { ...props, machine: machine(), canEdit: true });
 assert.match(html, /Editar geocerca/); assert.match(html, /Desactivar geocerca/);
 assert.ok(!html.includes('>ACTIVE<'));
});
test('create, map centre, SYBI centre, preview and cancel do not write before confirmation', async () => {
 const ui = await mountEditor({ empty: true });
 await ui.click('Crear geocerca');
 assert.equal(ui.requests.length, 0);
 await ui.click('Seleccionar centro sintético');
 assert.equal(ui.forms[0].center_latitude, 19.5);
 await ui.click('Usar ubicación registrada en SYBI');
 assert.equal(ui.forms[0].center_latitude, 19.4);
 await ui.submit();
 assert.match(ui.text(), /Revisar antes de guardar/);
 assert.equal(ui.requests.length, 0);
 await ui.click('Cancelar');
 assert.equal(ui.requests.length, 0); assert.equal(ui.data.geofences.length, 0);
 ui.close();
});
test('edit saves a new version only after preview and preserves source props', async () => {
 const ui = await mountEditor(), before = JSON.stringify(ui.data);
 await ui.click('Editar geocerca'); await ui.click('Seleccionar centro sintético');
 ui.forms[0].radius_m = 120; await Vue.nextTick(); await ui.submit();
 assert.match(ui.text(), /Centro ajustado manualmente/);
 assert.equal(ui.requests.length, 0);
 await ui.click('Guardar geocerca');
 assert.equal(ui.requests.length, 1);
 assert.equal(ui.requests[0].method, 'post');
 assert.equal(ui.requests[0].data.expected_config_version, 2);
 assert.equal(ui.requests[0].data.center_latitude, 19.5);
 assert.equal(ui.requests[0].data.radius_m, 120);
 assert.equal(JSON.stringify(ui.data), before);
 ui.close();
});
test('activation/deactivation and verification require explicit confirmation', async () => {
 const ui = await mountEditor();
 await ui.click('Desactivar geocerca'); assert.equal(ui.requests.length, 0);
 await ui.click('Confirmar'); assert.match(ui.requests[0].url, /geofences.deactivate/);
 await ui.click('Verificar ubicación'); assert.equal(ui.requests.length, 1);
 await ui.click('Confirmar'); assert.match(ui.requests[1].url, /location.verify/);
 assert.equal(ui.requests[1].data.confirmed, true);
 ui.close();
});
test('invalid radius prevents preview and write', async () => {
 const ui = await mountEditor();
 await ui.click('Editar geocerca'); ui.forms[0].radius_m = 0;
 await ui.submit(); assert.match(ui.text(), /radio entero/);
 assert.ok(!ui.text().includes('Guardar geocerca')); assert.equal(ui.requests.length, 0); ui.close();
});
test('browser GPS is opt-in, fresh, accurate and never implicitly persisted', async () => {
 let calls = 0;
 const geolocation = { getCurrentPosition(success, _error, options) {
  calls++; assert.equal(options.maximumAge, 0); assert.equal(options.enableHighAccuracy, true);
  success({ coords: { latitude: 19.4, longitude: -99.1, accuracy: 14.5 }, timestamp: 1000 });
 } };
 await assert.rejects(geo.requestOperatorLocation({ geolocation, secure: false }), /HTTPS o localhost/);
 assert.equal(calls, 0);
 const fix = await geo.requestOperatorLocation({ geolocation, secure: true, now: () => 1000 });
 assert.equal(calls, 1); assert.equal(fix.accuracy, 14.5);
 await assert.rejects(geo.requestOperatorLocation({ secure: true, now: () => 1001, geolocation }), /ubicación nueva/);
 assert.match(geo.locationError({ code: 1 }), /permiso/);
 assert.match(geo.locationError({ code: 3 }), /tiempo/);
});
test('operator GPS must be confirmed before it changes even the unsaved centre', async () => {
 const secure = Object.getOwnPropertyDescriptor(globalThis, 'isSecureContext');
 const navigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
 Object.defineProperty(globalThis, 'isSecureContext', { value: true, configurable: true });
 Object.defineProperty(globalThis, 'navigator', { value: { geolocation: { getCurrentPosition(success) {
  success({ coords: { latitude: 19.6, longitude: -99.3, accuracy: 14.5 }, timestamp: Date.now() });
 } } }, configurable: true });
 let ui;
 try {
  ui = await mountEditor();
  await ui.click('Editar geocerca'); await ui.click('Usar mi ubicación'); await Vue.nextTick();
  assert.match(ui.text(), /precisión aproximada de 15 m/);
  assert.equal(Number(ui.forms[0].center_latitude), 19.4);
  assert.equal(ui.requests.length, 0);
  await ui.click('Aplicar como centro');
  assert.equal(ui.forms[0].center_latitude, 19.6);
  assert.equal(ui.requests.length, 0);
 } finally {
  ui?.close();
  if (secure) Object.defineProperty(globalThis, 'isSecureContext', secure); else delete globalThis.isSecureContext;
  if (navigator) Object.defineProperty(globalThis, 'navigator', navigator); else delete globalThis.navigator;
 }
});
test('responsive and map interaction contracts retain accessible fallback without a browser claim', async () => {
 const map = await readFile('resources/js/Components/GeofenceMap.vue', 'utf8');
 const component = await readFile('resources/js/Components/GeofenceEditor.vue', 'utf8');
 assert.match(map, /L.circle/); assert.match(map, /radius: r/); assert.match(map, /dragend/);
 assert.match(map, /map.on\('click'/); assert.match(map, /resize\?\.disconnect/); assert.match(map, /map\?\.remove/);
 assert.match(map, /noWrap: true/); assert.match(map, /Seleccionar el centro del mapa/);
 assert.match(map, /geofence-pin--source/); assert.match(map, /geofence-pin--operator/);
 assert.match(component, /xl:grid-cols/); assert.match(component, /min-w-0/);
 assert.match(component, /min-height: 44px/); assert.match(component, /type="number"/);
});
