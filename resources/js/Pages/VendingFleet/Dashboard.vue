<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    generated_at: String,
    kpis: Object,
    app_versions: Array,
    alerts: Array,
    last_sybi_sync: Object,
    thresholds: Object,
});

const cards = [
    ['Máquinas', 'machines_total'], ['Máquinas operativas', 'machines_operational'],
    ['Devices activos', 'devices_active'], ['Online', 'devices_online'],
    ['Degradados', 'devices_degraded'], ['Offline', 'devices_offline'],
    ['Geocercas listas', 'geofences_ready'], ['Geocercas en revisión', 'geofences_review'],
    ['Empleados asignados', 'employees_assigned'], ['Config sincronizada', 'configuration_synced'],
    ['Config pendiente', 'configuration_pending'], ['Employees sincronizados', 'employees_synced'],
    ['Employees pendientes', 'employees_pending'], ['Asistencias hoy', 'attendance_today'],
    ['Eventos pendientes edge', 'pending_edge_events'], ['Rechazados acumulados', 'rejected_events_total'],
];
</script>

<template>
    <Head title="Operación vending" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-xl font-semibold text-app">Operación de flota vending</h1><p class="text-sm text-soft">Estado derivado; no se almacena telemetría heartbeat histórica.</p></div>
                <div class="flex gap-3 text-sm"><Link :href="route('vending-devices.index')" class="text-indigo-600">Devices</Link><Link :href="route('vending-machines.index')" class="text-indigo-600">Máquinas</Link><Link :href="route('vending-releases.index')" class="text-indigo-600">Releases</Link></div>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <article v-for="card in cards" :key="card[1]" class="card p-4">
                    <p class="text-xs uppercase tracking-wide text-soft">{{ card[0] }}</p>
                    <p class="mt-2 text-2xl font-semibold text-app">{{ kpis[card[1]] ?? 0 }}</p>
                </article>
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                <article class="card p-5">
                    <h2 class="font-semibold text-app">Distribución de app</h2>
                    <table class="mt-4 w-full text-sm"><thead><tr class="text-left text-soft"><th class="p-2">Plataforma</th><th class="p-2">Versión</th><th class="p-2 text-right">Devices</th></tr></thead><tbody><tr v-for="row in app_versions" :key="`${row.platform}-${row.version}`" class="border-t border-app"><td class="p-2">{{ row.platform }}</td><td class="p-2 font-mono">{{ row.version }}</td><td class="p-2 text-right">{{ row.devices }}</td></tr><tr v-if="!app_versions.length"><td colspan="3" class="p-3 text-soft">Sin información.</td></tr></tbody></table>
                </article>
                <article class="card p-5">
                    <h2 class="font-semibold text-app">Criterios visibles</h2>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><dt class="text-soft">Degradado después de</dt><dd>{{ thresholds.degraded_after_seconds }} s</dd><dt class="text-soft">Offline después de</dt><dd>{{ thresholds.offline_after_seconds }} s</dd><dt class="text-soft">Drift de reloj</dt><dd>{{ thresholds.clock_drift_seconds }} s</dd><dt class="text-soft">Presión outbox</dt><dd>{{ thresholds.pending_events_count }} eventos</dd></dl>
                    <p class="mt-4 text-sm text-soft">Último SYBI: {{ last_sybi_sync?.status ?? 'sin ejecución' }} · {{ last_sybi_sync?.finished_at ?? '—' }}</p>
                </article>
            </section>

            <section class="card p-5">
                <h2 class="font-semibold text-app">Centro de alertas derivadas</h2>
                <div class="mt-4 overflow-x-auto"><table class="w-full min-w-[48rem] text-sm"><thead><tr class="text-left text-soft"><th class="p-2">Severidad</th><th class="p-2">Tipo</th><th class="p-2">Máquina</th><th class="p-2">Device</th><th class="p-2">Razón</th></tr></thead><tbody><tr v-for="alert in alerts" :key="`${alert.type}-${alert.device_uuid}-${alert.machine_code}`" class="border-t border-app"><td class="p-2 font-semibold" :class="alert.severity === 'HIGH' ? 'text-rose-600' : 'text-amber-600'">{{ alert.severity }}</td><td class="p-2">{{ alert.type }}</td><td class="p-2">{{ alert.machine_code || '—' }}</td><td class="p-2 font-mono text-xs">{{ alert.device_uuid || '—' }}</td><td class="p-2">{{ alert.message }}</td></tr><tr v-if="!alerts.length"><td colspan="5" class="p-3 text-soft">Sin alertas actuales.</td></tr></tbody></table></div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
