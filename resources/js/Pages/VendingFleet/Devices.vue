<script setup>
import { reactive } from 'vue';
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
const tone = (status) => ({ ONLINE: 'text-emerald-600', DEGRADED: 'text-amber-600', OFFLINE: 'text-rose-600' }[status] ?? 'text-soft');
</script>

<template>
    <Head title="Devices vending" />
    <AuthenticatedLayout>
        <template #header><div><Link :href="route('vending-fleet.dashboard')" class="text-sm text-indigo-600">← Operación vending</Link><h1 class="text-xl font-semibold text-app">Device Registry</h1><p class="text-sm text-soft">Identidad técnica y diagnóstico latest-state, sin secretos.</p></div></template>
        <div class="space-y-5">
            <form class="card grid gap-3 p-4 md:grid-cols-4" @submit.prevent="apply">
                <input v-model="filters.search" class="rounded-xl border-app" placeholder="Código, UUID o serial" />
                <select v-model="filters.machine" class="rounded-xl border-app"><option value="">Todas las máquinas</option><option v-for="machine in machines" :key="machine.id" :value="machine.id">{{ machine.machine_code }}</option></select>
                <select v-model="filters.status" class="rounded-xl border-app"><option value="">Lifecycle</option><option v-for="status in statuses" :key="status">{{ status }}</option></select>
                <select v-model="filters.platform" class="rounded-xl border-app"><option value="">Plataforma</option><option v-for="platform in platforms" :key="platform">{{ platform }}</option></select>
                <select v-model="filters.app_version" class="rounded-xl border-app"><option value="">Versión app</option><option v-for="version in appVersions" :key="version">{{ version }}</option></select>
                <select v-model="filters.release_channel" class="rounded-xl border-app"><option value="">Canal</option><option>DEV</option><option>PILOT</option><option>PRODUCTION</option></select>
                <select v-model="filters.sync_state" class="rounded-xl border-app"><option value="">Sync</option><option>SYNCED</option><option>PENDING</option><option>STALE</option><option>ERROR</option></select>
                <select v-model="filters.last_seen" class="rounded-xl border-app"><option value="">Heartbeat</option><option value="recent">Reciente</option><option value="delayed">Demorado</option><option value="offline">Offline</option><option value="never">Nunca</option></select>
                <select v-model="filters.clock_drift" class="rounded-xl border-app"><option value="">Clock drift</option><option value="warning">Con warning</option></select>
                <select v-model="filters.pending_events" class="rounded-xl border-app"><option value="">Outbox</option><option value="any">Con pendientes</option><option value="high">Presión alta</option><option value="none">Sin pendientes</option></select>
                <select v-model="filters.geofence" class="rounded-xl border-app"><option value="">Geocerca</option><option>READY</option><option>REVIEW</option></select>
                <div class="flex gap-2"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Filtrar</button><button type="button" class="rounded-xl border border-app px-4 py-2 text-sm" @click="clear">Limpiar</button></div>
            </form>

            <section class="card overflow-x-auto">
                <table class="w-full min-w-[105rem] text-sm"><thead><tr class="bg-slate-50 text-left text-xs uppercase tracking-wide text-soft dark:bg-slate-800"><th class="p-3">Máquina</th><th class="p-3">Device</th><th class="p-3">Health</th><th class="p-3">Lifecycle</th><th class="p-3">App</th><th class="p-3">Heartbeat</th><th class="p-3">Sync config</th><th class="p-3">Sync employees</th><th class="p-3">Outbox</th><th class="p-3">Attendance/sync</th><th class="p-3">Drift</th><th class="p-3">Geocerca</th><th class="p-3">Último error</th></tr></thead>
                    <tbody><tr v-for="device in devices.data" :key="device.uuid" class="border-t border-app"><td class="p-3"><Link v-if="device.machine" :href="route('vending-machines.show', device.machine.uuid)" class="font-semibold text-indigo-600">{{ device.machine.machine_code }}</Link></td><td class="p-3"><div class="font-mono text-xs">{{ device.uuid }}</div><div class="text-soft">{{ device.device_serial || '—' }}</div></td><td class="p-3 font-semibold" :class="tone(device.fleet.status)">{{ device.fleet.status }}<div class="font-normal text-xs text-soft">{{ device.fleet.reasons.join(', ') }}</div></td><td class="p-3">{{ device.status }}</td><td class="p-3">{{ device.platform }} {{ device.platform_version }}<br><span class="font-mono">{{ device.app_version || '—' }} ({{ device.app_build_number || '—' }})</span><br><span class="text-xs text-soft">{{ device.fleet.app_version.status }}</span><template v-if="canManage"><select :value="device.release_channel" class="mt-1 block rounded-lg border-app text-xs" @change="updateReleaseTarget(device, { release_channel: $event.target.value })"><option>DEV</option><option>PILOT</option><option>PRODUCTION</option></select><input :value="device.release_group" class="mt-1 w-28 rounded-lg border-app text-xs" placeholder="grupo" @change="updateReleaseTarget(device, { release_group: $event.target.value || null })" /></template><span v-else class="block text-xs text-soft">{{ device.release_channel }} / {{ device.release_group || 'sin grupo' }}</span></td><td class="p-3">{{ device.last_heartbeat_at || 'Nunca' }}<br><span class="text-xs text-soft">{{ device.network_state || 'UNKNOWN' }}</span></td><td class="p-3">{{ device.fleet.manifest.configuration.applied_version ?? '—' }} / {{ device.fleet.manifest.configuration.server_version }}<br><span class="text-xs text-soft">{{ device.fleet.manifest.configuration.state }} · ACK {{ device.fleet.manifest.configuration.last_ack_at || '—' }}</span></td><td class="p-3">{{ device.fleet.manifest.employees.applied_version ?? '—' }} / {{ device.fleet.manifest.employees.server_version }}<br><span class="text-xs text-soft">{{ device.fleet.manifest.employees.state }} · ACK {{ device.fleet.manifest.employees.last_ack_at || '—' }}</span></td><td class="p-3">{{ device.pending_events_count ?? '—' }}</td><td class="p-3 text-xs">24h: {{ device.attendance?.received_last_24h ?? 0 }}<br>Última: {{ device.attendance?.last_received_at || '—' }}<br>Delay avg/max: {{ device.attendance?.sync_delay_average_seconds ?? '—' }} / {{ device.attendance?.sync_delay_max_seconds ?? '—' }} s<br>Dup/rechazo: {{ device.attendance?.duplicate_total ?? 0 }} / {{ device.attendance?.rejected_total ?? 0 }}</td><td class="p-3">{{ device.clock_drift_seconds ?? '—' }} s</td><td class="p-3">{{ device.machine?.geofence_ready ? 'READY' : 'REVIEW' }}</td><td class="p-3">{{ device.last_error_category || '—' }}<br><span class="text-xs text-soft">{{ device.last_error_code }}</span></td></tr><tr v-if="!devices.data.length"><td colspan="13" class="p-5 text-center text-soft">No hay devices para estos filtros.</td></tr></tbody>
                </table>
            </section>
            <nav class="flex flex-wrap gap-2"><Link v-for="link in devices.links" :key="link.label" :href="link.url || '#'" class="rounded-lg border border-app px-3 py-2 text-sm" :class="{ 'bg-indigo-600 text-white': link.active, 'pointer-events-none opacity-50': !link.url }" v-html="link.label" /></nav>
        </div>
    </AuthenticatedLayout>
</template>
