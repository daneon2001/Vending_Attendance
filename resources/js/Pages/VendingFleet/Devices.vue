<script setup>
import { reactive, ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ devices: Object, filters: Object, machines: Array, statuses: Array, platforms: Array, appVersions: Array, errorCategories: Array, thresholds: Object, canManage: Boolean });
const filters = reactive({
    search: props.filters.search ?? '', machine: props.filters.machine ?? '', status: props.filters.status ?? '',
    platform: props.filters.platform ?? '', app_version: props.filters.app_version ?? '', release_channel: props.filters.release_channel ?? '',
    sync_state: props.filters.sync_state ?? '', last_seen: props.filters.last_seen ?? '', clock_drift: props.filters.clock_drift ?? '',
    pending_events: props.filters.pending_events ?? '', geofence: props.filters.geofence ?? '',
});
const apply = () => router.get(route('vending-devices.index'), filters, { preserveState: true, replace: true });
const clear = () => { Object.keys(filters).forEach((key) => { filters[key] = ''; }); apply(); };
const updateReleaseTarget = (device, patch) => router.patch(route('vending-machines.devices.release-channel', [device.machine.uuid, device.uuid]), { release_channel: device.release_channel, release_group: device.release_group, ...patch }, { preserveScroll: true });

import StatusBadge from '@/Components/StatusBadge.vue';
import AdvancedFilters from '@/Components/AdvancedFilters.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import RecordPagination from '@/Components/RecordPagination.vue';
import { statusLabel, friendlyError, formatDateTime, advancedDeviceKeys, activeFilterCount } from '@/presentation/labels';
const expanded = ref(null);
const advancedCount = computed(() => activeFilterCount(filters, advancedDeviceKeys));
const advanced = computed(() => [
 { key:'platform', label:'Plataforma', options:props.platforms.map(value => [value, statusLabel(value)]) },
 { key:'app_version', label:'Versión', options:props.appVersions.map(value => [value, value]) },
 { key:'release_channel', label:'Canal', options:['DEV','PILOT','PRODUCTION'].map(value => [value,statusLabel(value)]) },
 { key:'sync_state', label:'Sincronización', options:['SYNCED','PENDING','STALE','ERROR'].map(value => [value,statusLabel(value)]) },
 { key:'last_seen', label:'Última conexión', options:[['recent','Reciente'],['delayed','Con demora'],['offline','Sin conexión'],['never','Sin conexiones previas']] },
 { key:'clock_drift', label:'Diferencia de hora', options:[['warning','Requiere revisión']] },
 { key:'pending_events', label:'Registros pendientes', options:[['any','Con pendientes'],['high','Cantidad elevada'],['none','Sin pendientes']] },
 { key:'geofence', label:'Geocerca', options:[['READY','Lista'],['REVIEW','Requiere revisión']] },
]);
</script>
<template>
 <Head title="Dispositivos" />
 <AuthenticatedLayout>
  <template #header><div><h1 class="text-xl font-semibold text-app">Dispositivos</h1><p class="text-sm text-soft">Revisa la conexión y la información sincronizada de cada máquina.</p></div></template>
  <div class="space-y-5">
   <form class="card grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="apply">
    <label class="text-sm">Buscar<input v-model="filters.search" class="mt-1 w-full rounded-xl border-app" placeholder="Código, UUID o número de serie" /></label>
    <label class="text-sm">Estado administrativo<select v-model="filters.status" class="mt-1 w-full rounded-xl border-app"><option value="">Todos</option><option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
    <label class="text-sm">Máquina<select v-model="filters.machine" class="mt-1 w-full rounded-xl border-app"><option value="">Todas</option><option v-for="machine in machines" :key="machine.id" :value="machine.id">{{ machine.machine_code }}</option></select></label>
    <div class="flex items-end gap-2"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-white">Buscar</button><button type="button" class="rounded-xl border border-app px-4 py-2" @click="clear">Limpiar</button></div>
    <AdvancedFilters :count="advancedCount"><label v-for="field in advanced" :key="field.key" class="text-sm">{{ field.label }}<select v-model="filters[field.key]" class="mt-1 w-full rounded-xl border-app"><option value="">Todos</option><option v-for="[value,label] in field.options" :key="value" :value="value">{{ label }}</option></select></label></AdvancedFilters>
   </form>
   <section class="card overflow-x-auto">
    <table class="w-full text-left text-sm"><caption class="sr-only">Dispositivos y conexión actual</caption>
     <thead class="bg-slate-50 text-soft dark:bg-slate-800"><tr><th class="p-3">Máquina</th><th class="p-3">Dispositivo</th><th class="p-3">Estado operativo</th><th class="p-3">Última conexión</th><th class="p-3">Sincronización</th><th class="p-3">Versión</th><th class="p-3">Acciones</th></tr></thead>
     <tbody><template v-for="device in devices.data" :key="device.uuid">
      <tr class="border-t border-app align-top">
       <td class="p-3"><Link v-if="device.machine" :href="route('vending-machines.show',device.machine.uuid)" class="font-semibold text-indigo-600">{{ device.machine.machine_code }}</Link><span v-else>Sin máquina</span></td>
       <td class="max-w-40 break-words p-3">{{ device.device_serial || 'Sin número de serie' }}<p class="text-xs text-soft">{{ statusLabel(device.platform) }}</p></td>
       <td class="p-3"><StatusBadge :value="device.fleet.status" /></td>
       <td class="p-3">{{ formatDateTime(device.last_heartbeat_at, 'Aún no se ha conectado') }}</td>
       <td class="space-y-1 p-3">
        <p class="flex flex-wrap items-center gap-1 text-xs"><span class="text-soft">Configuración · </span><StatusBadge :value="device.fleet.manifest.configuration.state" /></p>
        <p class="flex flex-wrap items-center gap-1 text-xs"><span class="text-soft">Empleados · </span><StatusBadge :value="device.fleet.manifest.employees.state" /></p>
       </td>
       <td class="p-3">{{ device.app_version || 'Sin información' }}<p class="mt-1 text-xs text-soft">{{ statusLabel(device.fleet.app_version.status) }}</p></td>
       <td class="p-3"><button type="button" class="min-h-11 font-semibold text-indigo-600" :aria-expanded="expanded === device.uuid" :aria-controls="'device-' + device.uuid" @click="expanded = expanded === device.uuid ? null : device.uuid">{{ expanded === device.uuid ? 'Cerrar detalle' : 'Ver detalle' }}</button></td>
      </tr>
      <tr v-if="expanded === device.uuid" class="border-t border-app"><td colspan="7" class="p-4"><section :id="'device-' + device.uuid" class="space-y-4" :aria-label="'Detalle del dispositivo ' + (device.device_serial || device.uuid)">
       <h2 class="font-semibold">Detalle del dispositivo</h2>
       <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div><p class="text-sm text-soft">Estado administrativo</p><StatusBadge :value="device.status" /><ul class="mt-2 space-y-1"><li v-for="reason in device.fleet.reasons" :key="reason">{{ friendlyError(reason) }}</li></ul></div>
        <dl class="space-y-2"><div><dt class="text-soft">Registros pendientes</dt><dd>{{ device.pending_events_count ?? 'Sin información' }}</dd></div><div><dt class="text-soft">Geocerca</dt><dd>{{ device.machine?.geofence_ready ? 'Lista' : 'Requiere revisión' }}</dd></div><div><dt class="text-soft">Diferencia de hora</dt><dd>{{ device.clock_drift_seconds == null ? 'Sin información' : device.clock_drift_seconds + ' s' }}</dd></div></dl>
        <dl class="space-y-2"><div><dt class="text-soft">Asistencias recibidas en 24 horas</dt><dd>{{ device.attendance?.received_last_24h ?? 'Sin información' }}</dd></div><div><dt class="text-soft">Última asistencia recibida</dt><dd>{{ formatDateTime(device.attendance?.last_received_at) }}</dd></div><div><dt class="text-soft">Conexión de red</dt><dd>{{ statusLabel(device.network_state) }}</dd></div></dl>
       </div>
       <TechnicalDetails>
        <p>UUID: {{ device.uuid }}</p><p>Sistema: {{ statusLabel(device.platform) }} {{ device.platform_version }} · Compilación: {{ device.app_build_number ?? '—' }}</p>
        <p>Canal: {{ statusLabel(device.release_channel) }} · Grupo: {{ device.release_group || 'Sin grupo' }}</p>
        <p>Última actividad: {{ formatDateTime(device.last_seen_at) }} · Espacio libre: {{ device.storage_free_mb ?? '—' }} MB</p>
        <p v-for="kind in ['configuration','employees']" :key="kind">{{ kind === 'configuration' ? 'Configuración' : 'Empleados' }}: dispositivo {{ device.fleet.manifest[kind].applied_version ?? '—' }} / servidor {{ device.fleet.manifest[kind].server_version }} · Confirmación: {{ formatDateTime(device.fleet.manifest[kind].last_ack_at) }}</p>
        <p>Demora promedio / máxima: {{ device.attendance?.sync_delay_average_seconds ?? '—' }} / {{ device.attendance?.sync_delay_max_seconds ?? '—' }} s</p>
        <p>Duplicados: {{ device.attendance?.duplicate_total ?? '—' }} · Rechazados: {{ device.attendance?.rejected_total ?? '—' }} · Fuera de geocerca: {{ device.attendance?.geofence_mismatch_total ?? '—' }}</p>
        <p>Códigos de diagnóstico: {{ device.fleet.reasons.join(', ') || 'Ninguno' }}</p>
        <p>Último error: {{ device.last_error_category || '—' }} / {{ device.last_error_code || '—' }} · {{ formatDateTime(device.last_error_at) }}</p>
        <div v-if="canManage && device.machine" class="grid gap-3 sm:grid-cols-2"><label>Canal de distribución<select :value="device.release_channel" class="mt-1 w-full rounded-lg border-app" @change="updateReleaseTarget(device,{release_channel:$event.target.value})"><option v-for="value in ['DEV','PILOT','PRODUCTION']" :key="value" :value="value">{{ statusLabel(value) }}</option></select></label><label>Grupo de distribución<input :value="device.release_group" class="mt-1 w-full rounded-lg border-app" @change="updateReleaseTarget(device,{release_group:$event.target.value || null})" /></label><p class="text-soft sm:col-span-2">Cada cambio de canal o grupo se guarda al terminar de editar el campo.</p></div>
       </TechnicalDetails>
      </section></td></tr>
     </template><tr v-if="!devices.data.length"><td colspan="7" class="p-8 text-center text-soft">No hay dispositivos que coincidan con los filtros. Prueba otra búsqueda o limpia los filtros.</td></tr></tbody>
    </table>
   </section>
   <RecordPagination :links="devices.links" />
  </div>
 </AuthenticatedLayout>
</template>
