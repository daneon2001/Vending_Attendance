import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import * as Vue from 'vue';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderVue } from './vueRender.mjs';
import { point } from '../../resources/js/presentation/geofenceEditor.js';

// Execute the installed Leaflet geometry code, with only its startup feature probes
// stubbed. No browser, tiles, network, database or visual certification.
async function leafletGeometry() {
 const element = () => ({ style: {}, getContext() {}, createSVGRect() {} });
 const document = { documentElement: element(), createElement: element, createElementNS: element };
 const window = { screen: {}, devicePixelRatio: 1, setTimeout, clearTimeout, addEventListener() {}, removeEventListener() {} };
 const context = { window, document, navigator: { userAgent: 'node geometry test', platform: 'test' }, exports: {}, console };
 runInNewContext(await readFile('node_modules/leaflet/dist/leaflet-src.js', 'utf8'), context);
 return context.window.L;
}
const location = { latitude: 19.432608, longitude: -99.133209 };
const active = () => ({ registered: { ...location }, center: { ...location }, source: null, radius: 50, editable: false });

async function mountMap(props, { failFirst = false, deferImport = false } = {}) {
 const L = await leafletGeometry();
 const stats = { imports: 0, maps: [], tiles: [], circles: [], markers: [], observers: [], events: [], errors: [], geometryErrors: [] };
 let releaseImport;
 const importGate = deferImport ? new Promise(resolve => { releaseImport = resolve; }) : Promise.resolve();
 const control = () => ({ addTo() { return this; } });
 const facade = {
  latLngBounds: L.latLngBounds, divIcon: options => options,
  control: { zoom: control, scale: control },
  map(container) {
   if (failFirst === true) { failFirst = false; throw new Error('Synthetic initialization failure'); }
   assert.ok(container, 'Vue mounted a real container reference before initializing');
   assert.ok(!container._leaflet_id, 'No duplicate initialization on a container');
   container._leaflet_id = stats.maps.length + 1;
   const crs = L.CRS.EPSG3857, zoom = 18;
   const map = {
    options: { crs }, removed: false, invalidated: 0, bounds: null,
    project: latlng => crs.latLngToPoint(L.latLng(latlng), zoom),
    unproject: p => crs.pointToLatLng(p, zoom),
    getPixelOrigin: () => L.point(0, 0),
    layerPointToLatLng: p => crs.pointToLatLng(p, zoom),
    setView() { return this; }, on() { return this; },
    fitBounds(bounds) { this.bounds = bounds; return this; },
    getBounds() { return this.bounds; }, getCenter() { return this.bounds.getCenter(); },
    panTo() {}, invalidateSize() { this.invalidated++; },
    remove() { this.removed = true; delete container._leaflet_id; },
   };
   stats.maps.push(map); return map;
  },
  featureGroup() {
   return {
    layers: [], map: null,
    addTo(map) { this.map = map; return this; },
    clearLayers() { this.layers = []; },
    getBounds() {
     if (failFirst === 'bounds') { failFirst = false; throw new Error('Synthetic rendering failure after map attachment'); }
     const bounds = L.latLngBounds([]);
     for (const layer of this.layers) bounds.extend(layer.getBounds ? layer.getBounds() : layer.getLatLng());
     return bounds;
    },
   };
  },
  circle(latlng, options) {
   const circle = L.circle(latlng, options);
   const getBounds = circle.getBounds;
   circle.getBounds = function () {
    try { return getBounds.call(this); }
    catch (error) { stats.geometryErrors.push(error.message); throw error; }
   };
   // Only attachment/rendering is simulated. _project and getBounds are unmodified
   // Leaflet: an unattached circle must still throw, reproducing the reported bug.
   circle.addTo = function (group) {
    this._map = group.map; this._renderer = { options: { tolerance: 0 } };
    this._project(); group.layers.push(this); return this;
   };
   stats.circles.push(circle); return circle;
  },
  marker(latlng, options) {
   const marker = { options, getLatLng: () => L.latLng(latlng),
    bindTooltip(text) { this.tooltip = text; return this; },
    addTo(group) { group.layers.push(this); return this; }, on() { return this; },
   };
   stats.markers.push(marker); return marker;
  },
  tileLayer(url, options) {
   const tile = { url, options, handlers: {}, on(event, callback) { this.handlers[event] = callback; return this; }, addTo() { return this; } };
   stats.tiles.push(tile); return tile;
  },
 };
 const source = await readFile('resources/js/Components/GeofenceMap.vue', 'utf8');
 let code = compileScript(parse(source).descriptor, { id: 'map-regression', inlineTemplate: true }).content;
 const imports = [];
 for (const match of [...code.matchAll(/import\s+([\s\S]*?)\s+from\s+['"]([^'"]+)['"];?/g)]) {
  const [, binding, spec] = match;
  const module = spec === 'vue' ? Vue : spec.endsWith('/geofenceEditor') ? { point } : null;
  assert.ok(module, 'Known import: ' + spec);
  code = code.replace(match[0], 'const ' + binding.replace(/\bas\b/g, ':') + ' = __imports[' + (imports.push(module) - 1) + '];');
 }
 code = code.replace("import('leaflet')", '__loadLeaflet()');
 const makeNode = (type, text = '') => ({ type, text, children: [], props: {}, parent: null });
 const renderer = Vue.createRenderer({
  createElement: type => makeNode(type), createText: text => makeNode('#text', text), createComment: text => makeNode('#comment', text),
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
 class ResizeObserver {
  constructor(callback) { this.callback = callback; this.disconnected = false; stats.observers.push(this); }
  observe() {} disconnect() { this.disconnected = true; }
 }
 const component = new Function('__imports', '__loadLeaflet', 'ResizeObserver', code.replace('export default', 'return'))(
  imports, async () => { stats.imports++; await importGate; return facade; }, ResizeObserver);
 const data = Vue.reactive(props), root = makeNode('root');
 const app = renderer.createApp({ render: () => Vue.h(component, { ...data, 'onSelect-center': p => stats.events.push(p) }) });
 app.config.errorHandler = error => { stats.errors.push(error); };
 app.mount(root);
 const nodes = () => { const result = []; const walk = node => { result.push(node); node.children.forEach(walk); }; walk(root); return result; };
 const content = node => node.type === '#comment' ? '' : node.text + node.children.map(content).join('');
 const flush = async () => { await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick(); };
 const button = label => nodes().find(n => n.type === 'button' && content(n) === label);
 const click = async label => { const node = button(label); assert.ok(node, label); assert.ok(!node.props.disabled); await node.props.onClick(); await flush(); };
 return { stats, data, button, click, flush, releaseImport, text: () => content(root), close: () => app.unmount() };
}

test('installed Leaflet reproduces getBounds failure on an unattached 50 m circle', async () => {
 const L = await leafletGeometry();
 const circle = L.circle([location.latitude, location.longitude], { radius: 50 });
 assert.equal(circle.getRadius(), 50);
 assert.throws(() => circle.getBounds(), /layerPointToLatLng/);
});
test('VM-DEMO active 50 m map initializes and frames mounted geometry without changing props', async () => {
 const data = active(), before = JSON.stringify(data), ui = await mountMap(data);
 try {
  assert.equal(ui.stats.imports, 0); assert.equal(ui.stats.tiles.length, 0);
  await ui.click('Cargar mapa');
  assert.deepEqual(ui.stats.geometryErrors, [], 'Mounted geometry must have a valid Leaflet projection');
  assert.equal(ui.stats.maps[0].removed, false, 'The active geofence must not fall into initialization catch');
  assert.equal(ui.stats.circles.length, 1, 'Do not construct a second unattached circle for bounds');
  assert.equal(ui.stats.circles[0].getRadius(), 50);
  const bounds = ui.stats.maps[0].bounds;
  assert.ok(bounds.isValid());
  assert.ok(bounds.getNorth() > location.latitude && bounds.getSouth() < location.latitude);
  assert.ok(bounds.getEast() > location.longitude && bounds.getWest() < location.longitude);
  assert.equal(ui.stats.markers.length, 2);
  assert.equal(ui.stats.events.length, 0); assert.equal(JSON.stringify(data), before);
  await ui.click('Ver geocerca completa');
  assert.ok(!ui.text().includes('No fue posible cargar el mapa'));
 } finally { ui.close(); }
});
test('SYBI map without a geofence still initializes after explicit consent', async () => {
 const ui = await mountMap({ source: { ...location }, registered: null, center: null, radius: null });
 try {
  assert.equal(ui.stats.tiles.length, 0);
  await ui.click('Cargar mapa');
  assert.equal(ui.stats.circles.length, 0); assert.equal(ui.stats.maps[0].removed, false);
  assert.ok(ui.stats.maps[0].bounds.isValid());
  assert.match(ui.text(), /Ubicación de la máquina/);
  assert.ok(!ui.text().includes('Centro de la geocerca'));
  assert.ok(!ui.text().includes('Mi ubicación'));
 } finally { ui.close(); }
});
test('legend names existing markers without letter codes, for SYBI and DEMO source', async () => {
 for (const source of [null, { ...location }]) {
  const html = await renderVue('resources/js/Components/GeofenceMap.vue', { ...active(), source, operator: { ...location } });
  assert.match(html, /Ubicación de la máquina/); assert.match(html, /Centro de la geocerca/); assert.match(html, /Mi ubicación/);
  assert.ok(!/>[^<]*\b[SCPU]\b[^<]*<\/span>/.test(html));
  assert.match(html, /aria-hidden="true"/);
 }
 const empty = await renderVue('resources/js/Components/GeofenceMap.vue', {});
 assert.ok(!empty.includes('<li>'));
});
test('source heading distinguishes SYBI from DEMO without changing source data', async () => {
 for (const source of ['SYBI', 'DEMO']) {
  const machine = { uuid: 'source-heading-test', machine_code: 'SYNTHETIC', source, geofences: [], assignments: [], devices: [], provisioning_tokens: [] };
  const before = JSON.stringify(machine);
  const html = await renderVue('resources/js/Pages/VendingMachines/Show.vue', { machine, auditLogs: [], employees: [] });
  const heading = source === 'SYBI' ? 'Información de origen SYBI' : 'Información de origen';
  assert.match(html, new RegExp('>' + heading + '</h2>'));
  assert.equal(html.includes('Información de origen SYBI'), source === 'SYBI');
  assert.equal(JSON.stringify(machine), before);
 }
});
test('initialization failure is friendly, preserves configuration and retry recovers', async () => {
 for (const failFirst of [true, 'bounds']) {
 const data = active(), before = JSON.stringify(data), ui = await mountMap(data, { failFirst });
 try {
  await ui.click('Cargar mapa');
  assert.match(ui.text(), /No fue posible cargar el mapa. La configuración de la geocerca se conserva y puedes intentarlo nuevamente/);
  assert.ok(!ui.text().includes('campos del detalle técnico'));
  assert.equal(JSON.stringify(data), before);
  assert.ok(ui.stats.maps.every(map => map.removed), 'A partially initialized map is cleaned before retry');
  await ui.click('Reintentar');
  assert.equal(ui.stats.maps.at(-1).removed, false); assert.ok(ui.stats.maps.at(-1).bounds.isValid());
  assert.ok(!ui.text().includes('No fue posible cargar el mapa'));
  assert.equal(JSON.stringify(data), before);
 } finally { ui.close(); }
 }
});
test('tile failure does not tear down the map, and remount does not reuse its instance', async () => {
 const ui = await mountMap(active());
 await ui.click('Cargar mapa');
 ui.stats.tiles[0].handlers.tileerror(); await ui.flush();
 assert.match(ui.text(), /No se pudo cargar parte del mapa/);
 assert.equal(ui.stats.maps[0].removed, false);
 ui.stats.observers[0].callback(); assert.equal(ui.stats.maps[0].invalidated, 1);
 ui.close();
 assert.equal(ui.stats.maps[0].removed, true); assert.equal(ui.stats.observers[0].disconnected, true);
 const second = await mountMap(active());
 try { await second.click('Cargar mapa'); assert.equal(second.stats.maps.length, 1); assert.equal(second.stats.maps[0].removed, false); }
 finally { second.close(); }
});
test('unmount during lazy import creates no map, tiles or resize subscription', async () => {
 const ui = await mountMap(active(), { deferImport: true });
 const pending = ui.button('Cargar mapa').props.onClick();
 ui.close(); ui.releaseImport(); await pending; await ui.flush();
 assert.equal(ui.stats.maps.length, 0); assert.equal(ui.stats.tiles.length, 0); assert.equal(ui.stats.observers.length, 0);
});
test('updating radius or selecting historical geometry frames only currently mounted layers', async () => {
 const ui = await mountMap(active());
 try {
  await ui.click('Cargar mapa');
  ui.data.radius = '80'; ui.data.center = { latitude: 19.433, longitude: -99.134 }; await ui.flush();
  await ui.click('Ver geocerca completa');
  assert.equal(ui.stats.circles.at(-1).getRadius(), 80);
  assert.ok(ui.stats.maps[0].bounds.contains([19.433, -99.134]));
  assert.equal(ui.stats.errors.length, 0);
 } finally { ui.close(); }
});
test('responsive structure retains bounded map height and wrapping controls at requested desktops', async () => {
 const source = await readFile('resources/js/Components/GeofenceMap.vue', 'utf8');
 const editor = await readFile('resources/js/Components/GeofenceEditor.vue', 'utf8');
 assert.match(source, /min-w-0/); assert.match(source, /flex flex-wrap/);
 assert.match(source, /height: clamp\(20rem, 48vh, 34rem\)/);
 assert.match(editor, /xl:grid-cols-\[minmax\(0,1fr\)_minmax\(16rem,22rem\)\]/);
 for (const height of [1080, 768]) {
  const mapHeight = Math.max(20 * 16, Math.min(.48 * height, 34 * 16));
  assert.ok(mapHeight <= height * .6);
 }
 // These are CSS/structure assertions, not measured viewport screenshots.
});
