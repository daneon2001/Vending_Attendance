<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import { statusLabel, friendlyError, formatDateTime, formatDurationSeconds } from '@/presentation/labels';
const props = defineProps({ generated_at: String, kpis: Object, app_versions: Array, alerts: Array, last_sybi_sync: Object, thresholds: Object });
const primary = [
 ['Máquinas operativas','machines_operational','Activas o en mantenimiento, según el criterio vigente'],
 ['Dispositivos conectados','devices_online','En línea y sin incidencias según el criterio operativo. Una incidencia reciente puede excluir un dispositivo cuya red reporta conexión.'],
 ['Empleados asignados','employees_assigned','Empleados distintos con asignación vigente'],
 ['Asistencias de hoy','attendance_today','Registros recibidos por el servidor'],
 ['Registros pendientes','pending_edge_events','Pendientes reportados por los dispositivos'],
];
const secondary = [
 ['Máquinas registradas','machines_total'],['Dispositivos habilitados','devices_active'],['Con incidencias','devices_degraded'],
 ['Sin conexión','devices_offline'],['Geocercas listas','geofences_ready'],['Geocercas por revisar','geofences_review'],
 ['Configuraciones sincronizadas','configuration_synced'],['Configuraciones pendientes','configuration_pending'],
 ['Empleados sincronizados','employees_synced'],['Empleados pendientes de sincronizar','employees_pending'],['Registros rechazados acumulados','rejected_events_total'],
];
// Count only the received list: the server may truncate it. Do not infer a global total.
const alertSeveritySummary = computed(() => {
 const counts = { HIGH: 0, MEDIUM: 0, OTHER: 0 };
 for (const alert of props.alerts) {
  const severity = alert.severity === 'HIGH' || alert.severity === 'MEDIUM' ? alert.severity : 'OTHER';
  counts[severity]++;
 }
 return [
  ['HIGH', 'alta', 'altas'],
  ['MEDIUM', 'media', 'medias'],
  ['OTHER', 'sin clasificación', 'sin clasificación'],
 ].filter(([severity]) => counts[severity] > 0)
  .map(([severity, singular, plural]) => counts[severity] + ' ' + (counts[severity] === 1 ? singular : plural))
  .join(' · ');
});
const alertTitle = (type) => type === 'GEOFENCE_REVIEW' ? 'Revisar geocerca' : 'Dispositivo: ' + statusLabel(type.replace(/^DEVICE_/, '')).toLowerCase();
</script>
<template>
 <Head title="Resumen de operación" />
 <AuthenticatedLayout>
  <template #header><div><h1 class="text-xl font-semibold text-app">Resumen de operación</h1><p class="text-sm text-soft">Información al {{ formatDateTime(generated_at) }} · Hora de Ciudad de México</p></div></template>
  <div class="space-y-6">
   <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Indicadores principales">
    <article v-for="[label,key,hint] in primary" :key="key" class="card p-5"><h2 class="text-sm text-soft">{{ label }}</h2><p class="mt-2 text-3xl font-semibold text-app">{{ kpis[key] ?? 'Sin información' }}</p><p class="mt-2 text-xs text-soft">{{ hint }}</p></article>
    <article class="card p-5">
     <h2 class="text-sm text-soft">Alertas activas</h2>
     <p class="mt-2 text-3xl font-semibold text-app">{{ alerts.length }}</p>
     <p v-if="alertSeveritySummary" class="mt-2 text-xs text-soft">{{ alertSeveritySummary }}</p>
     <p class="mt-2 text-xs text-soft">Conteo de la lista recibida; puede haber más alertas.</p>
     <a href="#alertas" class="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-indigo-600">Revisar alertas</a>
    </article>
   </section>
   <nav class="flex flex-wrap gap-3" aria-label="Continuar operación"><Link class="rounded-xl border border-app px-4 py-2 text-sm font-semibold" :href="route('vending-machines.index')">Ver máquinas y asignaciones</Link><Link class="rounded-xl border border-app px-4 py-2 text-sm font-semibold" :href="route('vending-devices.index')">Revisar dispositivos</Link></nav>
   <section id="alertas" class="card scroll-mt-6 p-5">
    <h2 class="text-lg font-semibold text-app">Lo que necesita atención</h2>
    <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800"><li v-for="(alert,index) in alerts" :key="index" class="grid gap-3 py-4 sm:grid-cols-[8rem_1fr]"><div><StatusBadge :value="alert.severity" /></div><div class="min-w-0"><h3 class="font-semibold">{{ alertTitle(alert.type) }} · {{ alert.machine_code || 'Sin máquina identificada' }}</h3><p class="mt-1 text-sm text-soft">{{ alert.message.split(', ').map(friendlyError).join(' ') }}</p><TechnicalDetails><p>Tipo: {{ alert.type }}</p><p>Dispositivo: {{ alert.device_uuid || 'No aplica' }}</p><p>Códigos: {{ alert.message }}</p></TechnicalDetails></div></li><li v-if="!alerts.length" class="py-5 text-sm text-soft">No hay alertas activas en la información disponible.</li></ul>
   </section>
   <details class="card p-5"><summary class="min-h-11 cursor-pointer font-semibold text-app">Más indicadores de operación</summary><dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><div v-for="[label,key] in secondary" :key="key"><dt class="text-sm text-soft">{{ label }}</dt><dd class="mt-1 text-xl font-semibold">{{ kpis[key] ?? 'Sin información' }}</dd></div></dl></details>
   <details class="card p-5"><summary class="min-h-11 cursor-pointer font-semibold text-app">Versiones y criterios de conexión</summary><div class="mt-4 grid gap-6 lg:grid-cols-2">
    <div><h2 class="font-semibold">Versiones instaladas</h2><table class="mt-3 w-full text-left text-sm"><thead><tr><th class="p-2">Plataforma</th><th class="p-2">Versión</th><th class="p-2">Dispositivos</th></tr></thead><tbody><tr v-for="row in app_versions" :key="row.platform + row.version" class="border-t border-app"><td class="p-2">{{ statusLabel(row.platform) }}</td><td class="p-2">{{ row.version === 'UNKNOWN' ? 'Sin información' : row.version }}</td><td class="p-2">{{ row.devices }}</td></tr><tr v-if="!app_versions.length"><td colspan="3" class="p-3 text-soft">Sin versiones reportadas.</td></tr></tbody></table></div>
    <div><h2 class="font-semibold">Criterios vigentes</h2><dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><dt>Con demora después de</dt><dd>{{ formatDurationSeconds(thresholds.degraded_after_seconds) }}</dd><dt>Sin conexión después de</dt><dd>{{ formatDurationSeconds(thresholds.offline_after_seconds) }}</dd><dt>Diferencia de hora</dt><dd>{{ formatDurationSeconds(thresholds.clock_drift_seconds) }}</dd><dt>Registros pendientes</dt><dd>{{ thresholds.pending_events_count }}</dd></dl><p class="mt-4 text-sm">Última consulta SYBI: {{ last_sybi_sync ? statusLabel(last_sybi_sync.status) : 'Sin ejecución' }} · {{ formatDateTime(last_sybi_sync?.finished_at) }}</p></div>
   </div></details>
  </div>
 </AuthenticatedLayout>
</template>
