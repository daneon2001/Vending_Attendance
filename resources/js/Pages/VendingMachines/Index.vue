<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    machines: Object,
    sourceRecords: Object,
    filters: Object,
    statuses: Array,
    coordinateSources: Array,
    catalogSources: Array,
    syncStatuses: Array,
    sourceStatuses: Array,
    validationStatuses: Array,
    municipalities: Array,
    localities: Array,
    sybiIntegration: Object,
});

const filters = reactive({
    catalog_view: props.filters?.catalog_view ?? 'operational',
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    source: props.filters?.source ?? '',
    sync_status: props.filters?.sync_status ?? '',
    source_status: props.filters?.source_status ?? '',
    validation_status: props.filters?.validation_status ?? '',
    municipality: props.filters?.municipality ?? '',
    locality: props.filters?.locality ?? '',
});
const formOpen = ref(false);
const editing = ref(null);
const emptyForm = () => ({
    sybi_id: '', machine_code: '', operational_code: '', name: '', address_line: '',
    neighborhood: '', locality: '', municipality: '', state: '', postal_code: '', country: 'MX',
    latitude: '', longitude: '', coordinate_source: '', coordinates_verified: false,
    coordinates_verified_at: '', timezone: 'America/Mexico_City', status: 'DRAFT',
    default_geofence_radius_m: '', installed_at: '', retired_at: '',
});
const form = useForm(emptyForm());
const syncForm = useForm({});
const sybiOwnedFields = ['sybi_id', 'machine_code', 'name', 'address_line', 'neighborhood', 'postal_code', 'latitude', 'longitude'];
const isSybiEdit = () => editing.value?.source === 'SYBI';
const isSybiOwned = (field) => isSybiEdit() && sybiOwnedFields.includes(field);

const applyFilters = () => router.get(route('vending-machines.index'), filters, { preserveState: true, replace: true });
const clearFilters = () => {
    Object.assign(filters, {
        search: '', status: '', source: '', sync_status: '', source_status: '',
        validation_status: '', municipality: '', locality: '',
    });
    applyFilters();
};
const selectCatalogView = (view) => {
    filters.catalog_view = view;
    clearFilters();
};
const openCreate = () => {
    editing.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
    formOpen.value = true;
};
const openEdit = (machine) => {
    editing.value = machine;
    const values = emptyForm();
    Object.keys(values).forEach((key) => { values[key] = machine[key] ?? values[key]; });
    values.coordinates_verified_at = machine.coordinates_verified_at?.slice(0, 16) ?? '';
    values.installed_at = machine.installed_at?.slice(0, 16) ?? '';
    values.retired_at = machine.retired_at?.slice(0, 16) ?? '';
    form.defaults(values);
    form.reset();
    form.clearErrors();
    formOpen.value = true;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { formOpen.value = false; } };
    if (editing.value) form.put(route('vending-machines.update', editing.value.uuid), options);
    else form.post(route('vending-machines.store'), options);
};
const geofenceRadius = (machine) => machine.active_geofence?.radius_m ?? machine.default_geofence_radius_m ?? '—';
const validationLabel = (record) => ({
    READY: 'READY',
    INCOMPLETE_LOCATION: 'SIN COORDENADAS',
    IDENTIFIER_CONFLICT: 'CONFLICTO IDENTIFICADOR',
    INVALID: 'INVÁLIDO',
    SOURCE_MISSING: 'AUSENTE EN FUENTE',
}[record.validation_status] ?? record.validation_status);
const sourceAddress = (record) => record.sybi_full_address
    || [record.address_line, record.neighborhood, record.postal_code].filter(Boolean).join(', ')
    || '—';
const synchronizeSybi = () => {
    if (!window.confirm('¿Sincronizar ahora el catálogo maestro desde SYBIML?')) return;
    syncForm.post(route('vending-machines.sybi-sync'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Máquinas vending" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-xl font-semibold text-app">Máquinas vending</h1><p class="text-sm text-soft">Proyección fuente SYBIML separada del catálogo operacional.</p></div>
                <button v-if="sybiIntegration.manual_creation_allowed && filters.catalog_view === 'operational'" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" @click="openCreate">Nueva máquina</button>
            </div>
        </template>

        <section class="space-y-5">
            <article class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><h2 class="font-semibold text-app">Catálogo maestro SYBIML</h2><p class="text-sm text-soft">La fuente se conserva aunque una fila todavía no sea promovible. El navegador nunca recibe la credencial.</p></div>
                    <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="!sybiIntegration.configured || syncForm.processing" @click="synchronizeSybi">{{ syncForm.processing ? 'Sincronizando…' : 'Sincronizar ahora' }}</button>
                </div>
                <p v-if="!sybiIntegration.configured" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Integración no configurada. Define URL y token únicamente en el entorno del servidor.</p>
                <div v-if="sybiIntegration.result" class="mt-3 rounded-lg p-3 text-sm" :class="sybiIntegration.result.ok ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100' : 'bg-rose-50 text-rose-900 dark:bg-rose-950/30 dark:text-rose-100'">
                    <template v-if="sybiIntegration.result.ok">Resultado: {{ sybiIntegration.result.status }} · fuente {{ sybiIntegration.result.source_candidates }} candidatas / {{ sybiIntegration.result.source_invalid }} inválidas · operativas {{ sybiIntegration.result.operational_created }} creadas / {{ sybiIntegration.result.operational_updated }} actualizadas / {{ sybiIntegration.result.operational_unchanged }} sin cambios · incompletas {{ sybiIntegration.result.operational_incomplete }} · conflictos {{ sybiIntegration.result.operational_conflicts }} · ausentes {{ sybiIntegration.result.operational_missing }}.</template>
                    <template v-else>Error seguro: {{ sybiIntegration.result.error_code }}<span v-if="sybiIntegration.result.http_status"> · HTTP {{ sybiIntegration.result.http_status }}</span>.</template>
                </div>
                <dl v-if="sybiIntegration.latest_run" class="mt-4 grid gap-3 text-sm sm:grid-cols-4 lg:grid-cols-8">
                    <div><dt class="text-soft">Última ejecución</dt><dd class="text-app">{{ sybiIntegration.latest_run.finished_at || sybiIntegration.latest_run.started_at }}</dd></div>
                    <div><dt class="text-soft">Estado</dt><dd class="font-semibold text-app">{{ sybiIntegration.latest_run.status }}</dd></div>
                    <div v-for="metric in ['source_candidates','source_invalid','operational_ready','operational_incomplete','operational_conflicts']" :key="metric"><dt class="text-soft">{{ metric }}</dt><dd class="text-app">{{ sybiIntegration.latest_run[metric] }}</dd></div>
                    <div><dt class="text-soft">Duración</dt><dd class="text-app">{{ sybiIntegration.latest_run.duration_ms ?? '—' }} ms</dd></div>
                </dl>
                <p v-else class="mt-3 text-sm text-soft">Aún no hay ejecuciones persistidas. El scheduler automático está {{ sybiIntegration.automatic_sync_enabled ? 'habilitado' : 'deshabilitado' }}.</p>
            </article>

            <nav class="flex gap-2" aria-label="Vista del catálogo">
                <button v-for="tab in [{key:'operational',label:'Operativas'},{key:'sybi',label:'Catálogo SYBIML'}]" :key="tab.key" type="button" class="rounded-xl px-4 py-2 text-sm font-semibold" :class="filters.catalog_view === tab.key ? 'bg-indigo-600 text-white' : 'border border-app text-app'" @click="selectCatalogView(tab.key)">{{ tab.label }}</button>
            </nav>

            <form class="card grid gap-3 p-4 md:grid-cols-8" @submit.prevent="applyFilters">
                <input v-model="filters.search" class="rounded-xl border-app md:col-span-2" placeholder="Código, nombre o SYBI" />
                <template v-if="filters.catalog_view === 'operational'">
                    <select v-model="filters.status" class="rounded-xl border-app"><option value="">Todos los estados</option><option v-for="status in statuses" :key="status">{{ status }}</option></select>
                    <select v-model="filters.source" class="rounded-xl border-app"><option value="">Todos los orígenes</option><option v-for="source in catalogSources" :key="source">{{ source }}</option></select>
                    <select v-model="filters.sync_status" class="rounded-xl border-app"><option value="">Todos los estados sync</option><option v-for="status in syncStatuses" :key="status">{{ status }}</option></select>
                    <select v-model="filters.municipality" class="rounded-xl border-app"><option value="">Todos los municipios</option><option v-for="value in municipalities" :key="value">{{ value }}</option></select>
                    <select v-model="filters.locality" class="rounded-xl border-app"><option value="">Todas las localidades</option><option v-for="value in localities" :key="value">{{ value }}</option></select>
                </template>
                <template v-else>
                    <select v-model="filters.source_status" class="rounded-xl border-app"><option value="">Toda presencia fuente</option><option v-for="status in sourceStatuses" :key="status">{{ status }}</option></select>
                    <select v-model="filters.validation_status" class="rounded-xl border-app"><option value="">Toda validación</option><option v-for="status in validationStatuses" :key="status">{{ status }}</option></select>
                </template>
                <div class="flex gap-2"><button class="rounded-xl bg-slate-900 px-4 py-2 text-sm text-white dark:bg-slate-100 dark:text-slate-900">Buscar</button><button type="button" class="rounded-xl border border-app px-4 py-2 text-sm" @click="clearFilters">Limpiar</button></div>
            </form>

            <template v-if="filters.catalog_view === 'operational'">
                <div class="overflow-x-auto rounded-2xl border border-app bg-white dark:bg-slate-900">
                    <table class="min-w-[88rem] w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-soft dark:bg-slate-800"><tr><th class="p-3">Código</th><th class="p-3">Origen</th><th class="p-3">Estado</th><th class="p-3">Sync</th><th class="p-3">Municipio / localidad</th><th class="p-3">Coordenadas</th><th class="p-3">Verificadas</th><th class="p-3">Radio</th><th class="p-3">Empleados</th><th class="p-3">Acciones</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="machine in machines.data" :key="machine.uuid">
                                <td class="p-3"><Link class="font-semibold text-indigo-600" :href="route('vending-machines.show', machine.uuid)">{{ machine.machine_code }}</Link><div class="text-xs text-soft">{{ machine.name || machine.sybi_id || 'Sin nombre' }}</div></td>
                                <td class="p-3"><span class="rounded-full bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-200">{{ machine.source }}</span></td>
                                <td class="p-3"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold dark:bg-slate-800">{{ machine.status }}</span></td>
                                <td class="p-3"><span class="text-xs font-semibold" :class="machine.geofence_review_required ? 'text-amber-600' : 'text-soft'">{{ machine.sybi_sync_status }}</span><div class="text-xs text-soft">{{ machine.sybi_last_seen_at || '—' }}</div></td>
                                <td class="p-3">{{ machine.municipality || '—' }}<div class="text-xs text-soft">{{ machine.locality || '—' }}</div></td>
                                <td class="p-3 font-mono text-xs">{{ machine.latitude ?? '—' }}, {{ machine.longitude ?? '—' }}</td>
                                <td class="p-3">{{ machine.coordinates_verified ? 'Sí' : 'No' }}</td>
                                <td class="p-3">{{ geofenceRadius(machine) }} m</td>
                                <td class="p-3">{{ machine.active_assignments_count }}</td>
                                <td class="p-3"><button class="text-sm font-semibold text-indigo-600" @click="openEdit(machine)">Editar</button></td>
                            </tr>
                            <tr v-if="!machines.data.length"><td colspan="10" class="p-8 text-center text-soft">No hay máquinas con estos filtros.</td></tr>
                        </tbody>
                    </table>
                </div>
                <nav class="flex flex-wrap gap-2"><Link v-for="link in machines.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="rounded-lg border border-app px-3 py-1.5 text-sm" :class="{ 'bg-indigo-600 text-white': link.active, 'pointer-events-none opacity-40': !link.url }" v-html="link.label" /></nav>
            </template>

            <template v-else>
                <div class="overflow-x-auto rounded-2xl border border-app bg-white dark:bg-slate-900">
                    <table class="min-w-[88rem] w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-soft dark:bg-slate-800"><tr><th class="p-3">SYBI ID</th><th class="p-3">Vending ID</th><th class="p-3">Sucursal</th><th class="p-3">Dirección</th><th class="p-3">Coordenadas</th><th class="p-3">Presencia</th><th class="p-3">Estado validación</th><th class="p-3">Problemas</th><th class="p-3">Primera / última sincronización</th><th class="p-3">Estado operacional</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="record in sourceRecords.data" :key="record.uuid">
                                <td class="p-3 font-mono text-xs">{{ record.sybi_id }}</td>
                                <td class="p-3 font-semibold text-app">{{ record.identificador_vending || '—' }}</td>
                                <td class="p-3">{{ record.name || '—' }}</td>
                                <td class="max-w-sm p-3 text-xs">{{ sourceAddress(record) }}</td>
                                <td class="p-3 font-mono text-xs">{{ record.latitude ?? '—' }}, {{ record.longitude ?? '—' }}</td>
                                <td class="p-3"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold dark:bg-slate-800">{{ record.source_status }}</span></td>
                                <td class="p-3"><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="record.validation_status === 'READY' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'">{{ validationLabel(record) }}</span></td>
                                <td class="p-3"><div class="flex flex-wrap gap-1"><span v-for="code in record.validation_codes || []" :key="code" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs dark:bg-slate-800">{{ code }}</span><span v-if="!(record.validation_codes || []).length">—</span></div></td>
                                <td class="p-3 text-xs"><div>{{ record.first_seen_at }}</div><div class="text-soft">{{ record.last_seen_at }}</div></td>
                                <td class="p-3"><Link v-if="record.promoted_vending_machine" class="font-semibold text-indigo-600" :href="route('vending-machines.show', record.promoted_vending_machine.uuid)">OPERATIVE · {{ record.promoted_vending_machine.machine_code }} · {{ record.promoted_vending_machine.status }}</Link><span v-else class="font-semibold text-soft">NOT_READY</span></td>
                            </tr>
                            <tr v-if="!sourceRecords.data.length"><td colspan="10" class="p-8 text-center text-soft">No hay registros fuente con estos filtros.</td></tr>
                        </tbody>
                    </table>
                </div>
                <nav class="flex flex-wrap gap-2"><Link v-for="link in sourceRecords.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="rounded-lg border border-app px-3 py-1.5 text-sm" :class="{ 'bg-indigo-600 text-white': link.active, 'pointer-events-none opacity-40': !link.url }" v-html="link.label" /></nav>
            </template>
        </section>

        <div v-if="formOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 p-4" @click.self="formOpen = false">
            <form class="mx-auto grid max-w-4xl gap-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900 md:grid-cols-2" @submit.prevent="submit">
                <div class="md:col-span-2"><h2 class="text-lg font-semibold text-app">{{ editing ? 'Editar máquina' : 'Nueva máquina' }}</h2><p class="text-sm text-soft">Dirección textual y coordenadas se administran por separado.</p></div>
                <p v-if="isSybiEdit()" class="rounded-lg bg-indigo-50 p-3 text-sm text-indigo-900 dark:bg-indigo-950/30 dark:text-indigo-100 md:col-span-2">Los campos del catálogo maestro son de sólo lectura y se actualizan exclusivamente mediante SYBIML.</p>
                <label v-for="field in [{k:'machine_code',l:'Código *'},{k:'sybi_id',l:'SYBI ID'},{k:'operational_code',l:'Código operacional'},{k:'name',l:'Nombre'},{k:'address_line',l:'Dirección'},{k:'neighborhood',l:'Colonia'},{k:'locality',l:'Localidad'},{k:'municipality',l:'Municipio'},{k:'state',l:'Estado'},{k:'postal_code',l:'CP'},{k:'latitude',l:'Latitud'},{k:'longitude',l:'Longitud'},{k:'default_geofence_radius_m',l:'Radio predeterminado (m)'}]" :key="field.k" class="text-sm text-app">{{ field.l }}<input v-model="form[field.k]" class="mt-1 w-full rounded-xl border-app disabled:bg-slate-100 disabled:text-slate-500 dark:disabled:bg-slate-800" :disabled="isSybiOwned(field.k)" :type="['latitude','longitude','default_geofence_radius_m'].includes(field.k) ? 'number' : 'text'" :step="['latitude','longitude'].includes(field.k) ? '0.0000001' : undefined" /><span v-if="form.errors[field.k]" class="text-xs text-rose-600">{{ form.errors[field.k] }}</span></label>
                <label class="text-sm text-app">Origen<select v-model="form.coordinate_source" class="mt-1 w-full rounded-xl border-app disabled:bg-slate-100 dark:disabled:bg-slate-800" :disabled="isSybiEdit()"><option value="">Sin definir</option><option v-for="source in coordinateSources" :key="source">{{ source }}</option></select></label>
                <label class="text-sm text-app">Estado<select v-model="form.status" class="mt-1 w-full rounded-xl border-app"><option v-for="status in statuses" :key="status">{{ status }}</option></select></label>
                <label class="text-sm text-app">Zona horaria<input v-model="form.timezone" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="flex items-center gap-2 text-sm text-app"><input v-model="form.coordinates_verified" type="checkbox" /> Coordenadas verificadas</label>
                <div class="md:col-span-2 flex justify-end gap-2"><button type="button" class="rounded-xl border border-app px-4 py-2" @click="formOpen = false">Cancelar</button><button class="rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white" :disabled="form.processing">Guardar</button></div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
