<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { point } from '@/presentation/geofenceEditor';
const props = defineProps({ center: Object, source: Object, registered: Object, operator: Object, radius: [Number, String], editable: Boolean });
const emit = defineEmits(['select-center']);
const container = ref(null);
const loaded = ref(false);
const loading = ref(false);
const warning = ref('');
const initializationFailed = ref(false);
const polar = computed(() => [props.center, props.source, props.registered].some(p => p && Math.abs(p.latitude) > 85.05112878));
let L, map, overlays, resize, disposed = false;
function select(latlng) {
 if (!props.editable) return;
 const wrapped = latlng.wrap();
 const p = point(Number(wrapped.lat.toFixed(7)), Number(wrapped.lng.toFixed(7)));
 if (p) emit('select-center', p);
}
function draw() {
 if (!map) return;
 overlays.clearLayers();
 const add = (p, symbol, name, kind, draggable = false) => {
  if (!p || Math.abs(p.latitude) > 85.05112878) return;
  const marker = L.marker([p.latitude, p.longitude], {
   title: name, alt: name, draggable, keyboard: true,
   icon: L.divIcon({ className: 'geofence-pin geofence-pin--' + kind, html: '<span aria-hidden="true">' + symbol + '</span>', iconSize: [32, 32], iconAnchor: [16, 16] }),
  }).bindTooltip(name).addTo(overlays);
  if (draggable) marker.on('dragend', () => select(marker.getLatLng()));
 };
 if (props.source) add(props.source, '◆', 'Ubicación de la máquina (origen SYBI)', 'source');
 else add(props.registered, '◆', 'Ubicación de la máquina', 'source');
 if (props.center && Math.abs(props.center.latitude) <= 85.05112878) {
  const r = Number(props.radius);
  if (Number.isFinite(r) && r > 0) L.circle([props.center.latitude, props.center.longitude], { radius: r, color: '#2563eb', weight: 2, fillOpacity: .12, interactive: false }).addTo(overlays);
  add(props.center, '●', 'Centro de la geocerca', 'center', props.editable);
 }
 add(props.operator, '▲', 'Mi ubicación', 'operator');
}
function fit() {
 if (!map) return;
 // Circle.getBounds needs the projection of a layer already attached to the map.
 // Reuse the displayed layers instead of constructing an unattached second circle.
 const bounds = overlays.getBounds();
 if (bounds.isValid()) map.fitBounds(bounds, { padding: [35, 35], maxZoom: 18 });
}
function teardown() {
 resize?.disconnect(); resize = null;
 map?.remove(); map = null; overlays = null;
}
async function loadMap() {
 if (loading.value || disposed) return;
 loading.value = true; warning.value = ''; initializationFailed.value = false;
 try {
  L = await import('leaflet');
  if (disposed) return;
  loaded.value = true;
  await nextTick();
  if (disposed) return;
  map = L.map(container.value, { zoomControl: false, scrollWheelZoom: false }).setView([20, -100], 5);
  L.control.zoom({ zoomInTitle: 'Acercar', zoomOutTitle: 'Alejar' }).addTo(map);
  L.control.scale({ imperial: false }).addTo(map);
  overlays = L.featureGroup().addTo(map);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
   maxZoom: 19, noWrap: true, updateWhenIdle: true, keepBuffer: 1,
   referrerPolicy: 'strict-origin-when-cross-origin',
   attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>',
  }).on('tileerror', () => { warning.value = 'No se pudo cargar parte del mapa. Los valores y el dibujo de la geocerca siguen disponibles.'; }).addTo(map);
  map.on('click', event => select(event.latlng));
  draw(); fit();
  resize = new ResizeObserver(() => map?.invalidateSize({ pan: false }));
  resize.observe(container.value);
 } catch {
  warning.value = 'No fue posible cargar el mapa. La configuración de la geocerca se conserva y puedes intentarlo nuevamente.';
  initializationFailed.value = true;
  teardown(); loaded.value = false;
 } finally { loading.value = false; }
}
watch(() => [props.center, props.source, props.registered, props.operator, props.radius, props.editable], draw, { deep: true });
watch(() => props.center, p => {
 if (map && p && Math.abs(p.latitude) <= 85.05112878 && !map.getBounds().contains([p.latitude, p.longitude])) map.panTo([p.latitude, p.longitude]);
}, { deep: true });
onBeforeUnmount(() => { disposed = true; teardown(); });
</script>

<template>
 <div class="geofence-map min-w-0">
  <div v-if="!loaded" class="geofence-map__placeholder">
   <p class="font-semibold text-app">Mapa de la geocerca</p>
   <p class="max-w-lg text-sm text-soft">Al cargarlo, OpenStreetMap recibirá tu dirección IP y el área consultada. No se enviarán nombres, credenciales ni registros de asistencia.</p>
   <button type="button" class="btn-primary min-h-11 px-4" :disabled="loading" @click="loadMap">{{ loading ? 'Cargando mapa…' : initializationFailed ? 'Reintentar' : 'Cargar mapa' }}</button>
  </div>
  <div v-if="loaded" ref="container" class="geofence-map__canvas" aria-label="Mapa de ubicación y geocerca" />
  <div v-if="loaded" class="flex flex-wrap gap-3 py-2">
   <button type="button" class="min-h-11 px-2 text-sm font-semibold text-indigo-600" @click="fit">{{ center ? 'Ver geocerca completa' : 'Ver ubicaciones' }}</button>
   <button v-if="editable" type="button" class="min-h-11 px-2 text-sm font-semibold text-indigo-600" @click="select(map.getCenter())">Seleccionar el centro del mapa</button>
  </div>
  <p v-if="editable" class="text-sm text-soft">Toca el mapa o mueve el marcador del centro de la geocerca. También puedes navegar con el teclado y seleccionar el centro del mapa.</p>
  <ul v-if="source || registered || center || operator" class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-soft" aria-label="Referencias del mapa">
   <li v-if="source || registered"><span aria-hidden="true" class="font-bold">◆</span> Ubicación de la máquina</li>
   <li v-if="center"><span aria-hidden="true" class="font-bold">●</span> Centro de la geocerca</li>
   <li v-if="operator"><span aria-hidden="true" class="font-bold">▲</span> Mi ubicación</li>
  </ul>
  <p v-if="polar" role="status" class="mt-2 text-sm text-amber-700">Esta ubicación está fuera del área representable por el mapa. Usa las coordenadas avanzadas; no se alterará el valor guardado.</p>
  <p v-if="warning" role="status" class="mt-2 text-sm text-amber-700">{{ warning }}</p>
 </div>
</template>

<style>
@import 'leaflet/dist/leaflet.css';
.geofence-map__canvas, .geofence-map__placeholder { height: clamp(20rem, 48vh, 34rem); border-radius: .75rem; border: 1px solid #cbd5e1; }
.geofence-map__canvas { z-index: 0; }
.geofence-map__placeholder { display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 1rem; padding: 1.5rem; text-align: center; background: var(--bg-soft, #f1f5f9); }
.geofence-pin { display: grid; place-items: center; border: 2px solid white; box-shadow: 0 1px 5px #0f172a80; font-weight: 800; color: white; }
.geofence-pin--center { background: #2563eb; border-radius: 50%; }
.geofence-pin--source { background: #7e22ce; transform-origin: center; border-radius: 3px; }
.geofence-pin--operator { background: #166534; border-radius: 50% 50% 4px 4px; }
.geofence-map button:focus-visible, .geofence-map .leaflet-interactive:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
.geofence-map .leaflet-control-attribution { max-width: 100%; }
</style>
