<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import GeofenceMap from '@/Components/GeofenceMap.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDateTime, friendlyError } from '@/presentation/labels';
import { accuracyLabel, circleMeasures, createGeofenceDraft, draftErrors, formatArea, formatCenter, formatDistance, geofencePoint, point, presentationDistance, requestOperatorLocation, samePoint } from '@/presentation/geofenceEditor';

const props = defineProps({ machine: { type: Object, required: true }, editor: { type: Object, default: () => ({}) }, canEdit: Boolean });
const selectedId = ref('');
const current = computed(() => props.machine.geofences?.find(g => g.uuid === selectedId.value)
 ?? props.machine.geofences?.find(g => g.status === 'ACTIVE') ?? props.machine.geofences?.[0] ?? null);
const editing = ref(false);
const preview = ref(false);
const localErrors = ref({});
const feedback = ref('');
const confirmation = ref(null);
const confirmPanel = ref(null);
const editHeading = ref(null);
const editButton = ref(null);
const previewHeading = ref(null);
const busy = computed(() => form.processing || operation.processing);
const form = useForm(createGeofenceDraft(props.machine));
const operation = useForm({ expected_config_version: props.machine.config_version, confirmed: true });
const source = computed(() => props.editor.source_location ? point(props.editor.source_location.latitude, props.editor.source_location.longitude) : null);
const registered = computed(() => point(props.machine.latitude, props.machine.longitude));
const center = computed(() => editing.value ? point(form.center_latitude, form.center_longitude) : geofencePoint(current.value));
const radius = computed(() => editing.value ? form.radius_m : current.value?.radius_m);
const mapRadius = computed(() => Number(radius.value) > 0 && Number(radius.value) <= props.editor.limits?.radius_m?.max ? Number(radius.value) : null);
const measures = computed(() => circleMeasures(mapRadius.value));
const offset = computed(() => presentationDistance(source.value, center.value));
const adjusted = computed(() => !!source.value && !!center.value && !samePoint(source.value, center.value));
const coordinateErrors = computed(() => ({ ...localErrors.value, ...form.errors }));
const operatorLocation = ref(null);
const proposedLocation = ref(null);
const locating = ref(false);
const locationMessage = ref('');
let generation = 0;

function focus(target) { nextTick(() => target.value?.focus()); }
function beginEdit(item = current.value) {
 if (!props.canEdit || busy.value) return;
 form.clearErrors();
 Object.assign(form, createGeofenceDraft(props.machine, item));
 editing.value = true; preview.value = false; confirmation.value = null;
 localErrors.value = {}; feedback.value = ''; proposedLocation.value = null;
 focus(editHeading);
}
function cancel() {
 if (busy.value) return;
 generation++;
 editing.value = false; preview.value = false; confirmation.value = null;
 proposedLocation.value = null; locating.value = false;
 localErrors.value = {}; form.clearErrors(); form.reset();
 feedback.value = 'Cambios descartados. No se guardó ninguna configuración.';
 focus(editButton);
}
function selectCenter(p) {
 if (!editing.value || preview.value || busy.value || !props.canEdit) return;
 form.center_latitude = p.latitude; form.center_longitude = p.longitude;
 proposedLocation.value = null;
}
function changeRadius(delta) {
 const range = props.editor.limits?.radius_m;
 if (!range) return;
 form.radius_m = Math.min(range.max, Math.max(range.min, (Number(form.radius_m) || range.min) + delta));
}
function review() {
 localErrors.value = draftErrors(form, props.editor.limits);
 if (Object.keys(localErrors.value).length) return;
 preview.value = true; focus(previewHeading);
}
function save() {
 if (!props.canEdit || !preview.value || busy.value) return;
 localErrors.value = draftErrors(form, props.editor.limits);
 if (Object.keys(localErrors.value).length) { preview.value = false; return; }
 // No UUID/version mutation in Vue. POST creates a new server-owned version.
 form.transform(data => ({ ...data, tolerance_m: data.tolerance_m === '' || data.tolerance_m == null ? 0 : data.tolerance_m })).post(route('vending-machines.geofences.store', props.machine.uuid), {
  preserveScroll: true,
  onSuccess: () => {
   editing.value = false; preview.value = false; selectedId.value = '';
   proposedLocation.value = null;
   feedback.value = form.status === 'ACTIVE'
    ? 'Geocerca guardada. Los dispositivos recibirán el cambio mediante la sincronización habitual.'
    : 'Borrador guardado. No se enviará a los dispositivos hasta activarlo.';
   focus(editButton);
  },
  onError: () => { preview.value = false; },
 });
}
async function locate() {
 const request = ++generation;
 locating.value = true; locationMessage.value = ''; proposedLocation.value = null;
 try {
  const fix = await requestOperatorLocation();
  if (request === generation) proposedLocation.value = fix;
 } catch (error) {
  if (request === generation) locationMessage.value = error.message;
 } finally { if (request === generation) locating.value = false; }
}
function applyLocation() {
 if (!proposedLocation.value) return;
 const fix = proposedLocation.value;
 selectCenter(fix); operatorLocation.value = fix;
 locationMessage.value = 'Ubicación aplicada sólo al borrador. Revisa los cambios antes de guardar.';
}
function ask(action, item = null) {
 if (!props.canEdit || busy.value) return;
 operation.clearErrors();
 operation.expected_config_version = props.machine.config_version;
 confirmation.value = { action, uuid: item?.uuid };
 focus(confirmPanel);
}
function confirmOperation() {
 if (!confirmation.value || busy.value) return;
 const { action, uuid } = confirmation.value;
 const target = action === 'verify'
  ? route('vending-machines.location.verify', props.machine.uuid)
  : route('vending-machines.geofences.' + action, [props.machine.uuid, uuid]);
 operation.patch(target, {
  preserveScroll: true,
  onSuccess: () => {
   confirmation.value = null; selectedId.value = '';
   feedback.value = action === 'verify' ? 'Ubicación registrada verificada; el centro operacional no cambió.'
    : 'Estado actualizado. La sincronización de configuración seguirá el flujo habitual.';
   focus(editButton);
  },
 });
}
watch(() => props.machine.uuid, () => {
 generation++; selectedId.value = ''; editing.value = false; preview.value = false;
 confirmation.value = null; proposedLocation.value = null; operatorLocation.value = null; locating.value = false;
});
onBeforeUnmount(() => { generation++; });
</script>

<template>
 <section class="card min-w-0 p-4 sm:p-5" aria-labelledby="geofence-title">
  <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
   <div><h2 id="geofence-title" class="text-lg font-semibold text-app">Geocerca</h2><p class="text-sm text-soft">Zona circular permitida para esta máquina.</p></div>
   <button v-if="canEdit && !editing" ref="editButton" type="button" class="geofence-button geofence-button--primary" :disabled="busy" @click="beginEdit(current)">{{ current ? 'Editar geocerca' : 'Crear geocerca' }}</button>
  </div>
  <p v-if="!current && !editing" class="mb-4 text-sm text-soft">Esta máquina todavía no tiene una geocerca configurada.</p>
  <p v-if="feedback" role="status" class="mb-4 rounded-lg bg-slate-100 p-3 text-sm text-app dark:bg-slate-800">{{ feedback }}</p>
  <div class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(16rem,22rem)]">
   <GeofenceMap :center="center" :source="source" :registered="registered" :operator="operatorLocation" :radius="mapRadius" :editable="canEdit && editing && !preview && !busy" @select-center="selectCenter" />
   <div class="min-w-0 space-y-4">
    <div v-if="!editing" class="space-y-4">
     <StatusBadge v-if="current" :value="current.status" />
     <dl class="grid grid-cols-2 gap-3 text-sm">
      <dt class="text-soft">Radio</dt><dd>{{ formatDistance(current?.radius_m) }}</dd>
      <dt class="text-soft">Precisión requerida</dt><dd>{{ accuracyLabel(current?.minimum_acceptable_accuracy_m) }}</dd>
      <dt class="text-soft">Tolerancia</dt><dd>{{ formatDistance(current?.tolerance_m) }}</dd>
     </dl>
     <p v-if="current?.status === 'DRAFT'" class="text-sm text-soft">Este borrador todavía no está aplicado a la operación.</p>
     <div v-if="canEdit && current" class="flex flex-wrap gap-2">
      <button v-if="['DRAFT', 'INACTIVE'].includes(current.status)" type="button" class="geofence-button" :disabled="busy" @click="ask('activate', current)">Activar geocerca</button>
      <button v-if="current.status === 'ACTIVE'" type="button" class="geofence-button" :disabled="busy" @click="ask('deactivate', current)">Desactivar geocerca</button>
     </div>
    </div>

    <form v-else-if="!preview" class="space-y-4" @submit.prevent="review">
     <h3 ref="editHeading" tabindex="-1" class="font-semibold text-app">Configurar geocerca</h3>
     <fieldset :disabled="busy" class="space-y-4">
      <div class="flex flex-wrap gap-2">
       <button v-if="source" type="button" class="geofence-button" @click="selectCenter(source)">Usar ubicación registrada en SYBI</button>
       <button v-else-if="registered" type="button" class="geofence-button" @click="selectCenter(registered)">Usar ubicación de la máquina</button>
       <button type="button" class="geofence-button" :disabled="locating" @click="locate">{{ locating ? 'Obteniendo ubicación…' : 'Usar mi ubicación' }}</button>
      </div>
      <p v-if="!center" class="text-sm text-soft">Selecciona un centro para dibujar la zona.</p>
      <p v-if="locationMessage" role="status" class="text-sm text-soft">{{ locationMessage }}</p>
      <div v-if="proposedLocation" class="rounded-lg border border-amber-300 p-3 text-sm">
       <p>Ubicación obtenida con precisión aproximada de {{ formatDistance(proposedLocation.accuracy) }}.</p>
       <p class="mt-1 text-soft">¿Aplicarla como centro del borrador? Todavía no se guardará.</p>
       <div class="mt-2 flex flex-wrap gap-2"><button type="button" class="geofence-button" @click="applyLocation">Aplicar como centro</button><button type="button" class="geofence-button" @click="proposedLocation = null">Descartar ubicación</button></div>
      </div>
      <label class="block text-sm" for="geofence-radius">Radio (m)
       <span class="mt-1 flex gap-2"><button type="button" aria-label="Reducir radio un metro" class="geofence-button" @click="changeRadius(-1)">−</button><input id="geofence-radius" v-model="form.radius_m" type="number" step="1" :min="editor.limits?.radius_m.min" :max="editor.limits?.radius_m.max" class="geofence-input min-w-0" aria-describedby="geofence-radius-range" /><button type="button" aria-label="Aumentar radio un metro" class="geofence-button" @click="changeRadius(1)">+</button></span>
      </label>
      <p id="geofence-radius-range" class="text-xs text-soft">Rango permitido: {{ editor.limits?.radius_m.min }}–{{ editor.limits?.radius_m.max }} m, en metros enteros.</p>
      <label v-for="field in [{ key: 'minimum_acceptable_accuracy_m', label: 'Precisión requerida (m)' }, { key: 'tolerance_m', label: 'Tolerancia (m)' }]" :key="field.key" :for="'geofence-' + field.key" class="block text-sm">{{ field.label }}
       <input :id="'geofence-' + field.key" v-model="form[field.key]" class="geofence-input mt-1" type="number" step="any" :min="editor.limits?.[field.key].min" :max="editor.limits?.[field.key].max" />
       <span class="text-xs text-soft">Rango: {{ editor.limits?.[field.key].min }}–{{ editor.limits?.[field.key].max }} m.</span>
      </label>
      <p class="text-xs text-soft">La precisión requerida limita la incertidumbre aceptable del GPS. Vacía significa sin requisito configurado. La tolerancia es un margen adicional; no cambia el radio dibujado.</p>
      <label for="geofence-status" class="block text-sm">Al guardar<select id="geofence-status" v-model="form.status" class="geofence-input mt-1"><option value="DRAFT">Conservar como borrador</option><option value="ACTIVE">Activar esta configuración</option></select></label>
      <TechnicalDetails>
       <p>Los valores de origen SYBI no se modifican. Estos campos ajustan sólo el centro operacional.</p>
       <label for="geofence-latitude" class="block">Latitud del centro<input id="geofence-latitude" v-model="form.center_latitude" class="geofence-input" type="number" step="0.0000001" min="-90" max="90" /></label>
       <label for="geofence-longitude" class="block">Longitud del centro<input id="geofence-longitude" v-model="form.center_longitude" class="geofence-input" type="number" step="0.0000001" min="-180" max="180" /></label>
      </TechnicalDetails>
      <ul v-if="Object.keys(coordinateErrors).length" role="alert" class="space-y-1 text-sm text-rose-700"><li v-for="(message, field) in coordinateErrors" :key="field">{{ friendlyError(message) }}</li></ul>
      <div class="flex flex-wrap gap-2"><button type="button" class="geofence-button" @click="cancel">Cancelar</button><button class="geofence-button geofence-button--primary" type="submit">Revisar cambios</button></div>
     </fieldset>
    </form>

    <div v-else class="space-y-4">
     <h3 ref="previewHeading" tabindex="-1" class="font-semibold text-app">Revisar antes de guardar</h3>
     <dl class="space-y-3 text-sm">
      <div><dt class="font-medium">Centro anterior → nuevo centro</dt><dd class="break-words">{{ formatCenter(geofencePoint(current)) }} → {{ formatCenter(center) }}</dd></div>
      <div><dt class="font-medium">Radio anterior → nuevo radio</dt><dd>{{ formatDistance(current?.radius_m) }} → {{ formatDistance(form.radius_m) }}</dd></div>
      <div><dt class="font-medium">Precisión requerida</dt><dd>{{ accuracyLabel(form.minimum_acceptable_accuracy_m) }}</dd></div>
      <div><dt class="font-medium">Tolerancia</dt><dd>{{ formatDistance(form.tolerance_m === '' ? 0 : form.tolerance_m) }}</dd></div>
      <div><dt class="font-medium">Resultado</dt><dd>{{ form.status === 'ACTIVE' ? 'Se activará la nueva configuración y se conservará la anterior.' : 'Se guardará un borrador sin modificar la configuración del dispositivo.' }}</dd></div>
     </dl>
     <div class="flex flex-wrap gap-2"><button type="button" class="geofence-button" :disabled="busy" @click="cancel">Cancelar</button><button type="button" class="geofence-button" :disabled="busy" @click="preview = false">Volver a editar</button><button type="button" class="geofence-button geofence-button--primary" :disabled="busy" @click="save">{{ busy ? 'Guardando…' : 'Guardar geocerca' }}</button></div>
    </div>

    <p v-if="adjusted" class="rounded-lg border border-amber-300 p-3 text-sm">Centro ajustado manualmente · {{ formatDistance(offset) }} de la ubicación SYBI. La fuente permanece intacta.</p>
    <p v-else-if="source && center" class="text-sm text-soft">El centro coincide con la ubicación SYBI.</p>
    <dl v-if="measures" class="grid grid-cols-2 gap-2 text-xs text-soft"><dt>Área aproximada</dt><dd>{{ formatArea(measures.area) }}</dd><dt>Perímetro aproximado</dt><dd>{{ formatDistance(measures.perimeter) }}</dd></dl>
   </div>
  </div>

  <div v-if="!editing" class="mt-5 border-t border-app pt-4">
   <h3 class="font-semibold text-app">Ubicación de la máquina</h3>
   <p class="mt-1 text-sm text-soft">{{ machine.coordinates_verified ? 'Ubicación registrada verificada' : 'Ubicación registrada sin verificar' }} · {{ formatDateTime(machine.coordinates_verified_at, 'Sin fecha de verificación') }}</p>
   <p class="text-xs text-soft">Verificar la ubicación registrada no certifica el GPS ni un centro operacional ajustado.</p>
   <button v-if="canEdit && registered" type="button" class="geofence-button mt-2" :disabled="busy" @click="ask('verify')">Verificar ubicación</button>
  </div>

  <section v-if="confirmation" ref="confirmPanel" tabindex="-1" aria-label="Confirmar operación de geocerca" class="mt-4 rounded-xl border border-amber-300 p-4">
   <p v-if="confirmation.action === 'verify'" class="mb-2 break-words text-sm">Ubicación registrada que estás verificando: {{ formatCenter(registered) }}.</p>
   <h3 class="font-semibold text-app">{{ confirmation.action === 'verify' ? 'Confirmar ubicación registrada' : confirmation.action === 'activate' ? 'Activar geocerca' : 'Desactivar geocerca' }}</h3>
   <p class="mt-2 text-sm">{{ confirmation.action === 'verify' ? 'Confirma que revisaste la ubicación registrada de la máquina. Se guardarán la fecha y el responsable de esta revisión, sin cambiar las coordenadas de origen.' : confirmation.action === 'activate' ? 'La geocerca activa anterior se conservará como historial. El dispositivo recibirá esta configuración en su próxima sincronización.' : 'La máquina quedará sin una geocerca activa. Esto puede impedir nuevos registros que requieran una zona configurada. El historial no se borrará.' }}</p>
   <ul v-if="Object.keys(operation.errors).length" role="alert" class="mt-2 text-sm text-rose-700"><li v-for="(message, field) in operation.errors" :key="field">{{ friendlyError(message) }}</li></ul>
   <div class="mt-3 flex flex-wrap gap-2"><button type="button" class="geofence-button" :disabled="busy" @click="confirmation = null">Cancelar</button><button type="button" class="geofence-button geofence-button--primary" :disabled="busy" @click="confirmOperation">{{ busy ? 'Guardando…' : 'Confirmar' }}</button></div>
  </section>

  <TechnicalDetails>
   <p>Configuración del servidor: {{ machine.config_version }}. Cambiar valores crea otra versión; las geometrías anteriores no se sobrescriben.</p>
   <p>Ubicación registrada: {{ formatCenter(registered) }}. Centro operacional: {{ formatCenter(center) }}.</p>
   <p v-if="operatorLocation">Ubicación consultada: precisión {{ formatDistance(operatorLocation.accuracy) }}. No se guarda como asistencia.</p>
   <ul v-if="machine.geofences?.length" class="space-y-3" aria-label="Versiones anteriores de geocerca">
    <li v-for="item in machine.geofences" :key="item.uuid" class="flex flex-wrap items-center gap-2">
     <span>Versión {{ item.version }} · {{ formatDistance(item.radius_m) }}</span><StatusBadge :value="item.status" />
     <button type="button" class="geofence-button" :disabled="editing || busy" @click="selectedId = item.uuid">Ver esta versión</button>
     <span class="basis-full break-all">UUID: {{ item.uuid }} · Centro: {{ formatCenter(geofencePoint(item)) }}</span>
    </li>
   </ul>
  </TechnicalDetails>
 </section>
</template>

<style scoped>
.geofence-button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; border: 1px solid #94a3b8; border-radius: .6rem; padding: .5rem .8rem; font-size: .875rem; font-weight: 600; }
.geofence-button--primary { background: #4f46e5; border-color: #4f46e5; color: white; }
.geofence-button:disabled { opacity: .55; cursor: not-allowed; }
.geofence-input { width: 100%; min-height: 44px; border-radius: .6rem; border-color: #94a3b8; background: transparent; color: inherit; }
.geofence-button:focus-visible, .geofence-input:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
</style>
