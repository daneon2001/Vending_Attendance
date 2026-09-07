<script setup>
import Modal from '@/Components/Modal.vue';
import AdvancedFilters from '@/Components/AdvancedFilters.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import RecordPagination from '@/Components/RecordPagination.vue';
import { statusLabel, friendlyError, formatDateTime, activeFilterCount } from '@/presentation/labels';
import { canUse } from '@/presentation/navigation';
import { usePage } from '@inertiajs/vue3';
const page = usePage();
const can = (action) => canUse(page.props.auth?.permissions, 'vending_machines', action);
const metricLabels = {source_candidates:'Registros recibidos',source_invalid:'Con errores',operational_ready:'Listas para operar',operational_incomplete:'Incompletas',operational_conflicts:'Conflictos'};
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

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
const pageTitle = computed(() => filters.catalog_view === 'sybi' ? 'Catálogo SYBI' : 'Máquinas vending');
const pageSubtitle = computed(() => filters.catalog_view === 'sybi'
    ? 'Consulta la información de origen y su vinculación con las máquinas vending.'
    : 'Administra máquinas, asignaciones y zonas permitidas.');
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
const sourceAddress = (record) => record.sybi_full_address
    || [record.address_line, record.neighborhood, record.postal_code].filter(Boolean).join(', ')
    || '—';
const synchronizeSybi = () => {
    if (!window.confirm('¿Sincronizar ahora el catálogo desde SYBI?')) return;
    syncForm.post(route('vending-machines.sybi-sync'), { preserveScroll: true });
};
</script>

<template>
    <Head :title="pageTitle" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-xl font-semibold text-app">{{ pageTitle }}</h1><p class="text-sm text-soft">{{ pageSubtitle }}</p></div>
                <button v-if="can('create') && sybiIntegration.manual_creation_allowed && filters.catalog_view === 'operational'" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" @click="openCreate">Nueva máquina</button>
            </div>
        </template>

        <section class="space-y-5">
            <article v-if="filters.catalog_view === 'operational'" class="card p-5" aria-label="Resumen de fuente SYBI">
                <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold text-app">Fuente SYBI</h2><Link class="inline-flex min-h-11 items-center text-sm font-semibold text-indigo-600" :href="route('vending-machines.index', { catalog_view: 'sybi' })">Ver catálogo SYBI</Link></div>
                <p class="text-xs text-soft">Resultados de la última ejecución; no representan un conteo en tiempo real.</p>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-soft">Última sincronización</dt><dd>{{ formatDateTime(sybiIntegration.latest_run?.finished_at || sybiIntegration.latest_run?.started_at, 'Sin ejecuciones') }}</dd></div>
                    <div><dt class="text-soft">Listas para operar</dt><dd>{{ sybiIntegration.latest_run?.operational_ready ?? 'Sin información' }}</dd></div>
                    <div><dt class="text-soft">Por revisar (incompletas)</dt><dd>{{ sybiIntegration.latest_run?.operational_incomplete ?? 'Sin información' }}</dd></div>
                    <div><dt class="text-soft">Conflictos</dt><dd>{{ sybiIntegration.latest_run?.operational_conflicts ?? 'Sin información' }}</dd></div>
                </dl>
                <p v-if="!sybiIntegration.configured" class="mt-3 text-sm text-amber-700 dark:text-amber-300">La integración SYBI aún no está configurada.</p>
            </article>
            <article v-else class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><h2 class="font-semibold text-app">Catálogo SYBI</h2><p class="text-sm text-soft">Consulta la información de origen y revisa qué máquinas están listas para operar.</p></div>
                    <button v-if="can('manage')" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="!sybiIntegration.configured || syncForm.processing" @click="synchronizeSybi">{{ syncForm.processing ? 'Sincronizando…' : 'Sincronizar ahora' }}</button>
                </div>
                <p v-if="!sybiIntegration.configured" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">La integración SYBI aún no está configurada. Solicita apoyo al administrador.</p>
                <div v-if="sybiIntegration.result" class="mt-3 rounded-lg p-3 text-sm" :class="sybiIntegration.result.ok ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100' : 'bg-rose-50 text-rose-900 dark:bg-rose-950/30 dark:text-rose-100'">
                    <template v-if="sybiIntegration.result.ok">Resultado: {{ statusLabel(sybiIntegration.result.status) }} · fuente {{ sybiIntegration.result.source_candidates }} candidatas / {{ sybiIntegration.result.source_invalid }} inválidas · operativas {{ sybiIntegration.result.operational_created }} creadas / {{ sybiIntegration.result.operational_updated }} actualizadas / {{ sybiIntegration.result.operational_unchanged }} sin cambios · incompletas {{ sybiIntegration.result.operational_incomplete }} · conflictos {{ sybiIntegration.result.operational_conflicts }} · ausentes {{ sybiIntegration.result.operational_missing }}.</template>
                    <template v-else>{{ friendlyError(sybiIntegration.result.error_code) }}<TechnicalDetails>Código: {{ sybiIntegration.result.error_code }} · HTTP {{ sybiIntegration.result.http_status || '—' }}</TechnicalDetails></template>
                </div>
                <details v-if="sybiIntegration.latest_run" class="mt-3"><summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold">Última sincronización y resultados</summary><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-4 lg:grid-cols-4">
                    <div><dt class="text-soft">Última ejecución</dt><dd class="text-app">{{ formatDateTime(sybiIntegration.latest_run.finished_at || sybiIntegration.latest_run.started_at) }}</dd></div>
                    <div><dt class="text-soft">Estado</dt><dd class="font-semibold text-app">{{ statusLabel(sybiIntegration.latest_run.status) }}</dd></div>
                    <div v-for="metric in ['source_candidates','source_invalid','operational_ready','operational_incomplete','operational_conflicts']" :key="metric"><dt class="text-soft">{{ metricLabels[metric] }}</dt><dd class="text-app">{{ sybiIntegration.latest_run[metric] }}</dd></div>
                    <div><dt class="text-soft">Duración</dt><dd class="text-app">{{ sybiIntegration.latest_run.duration_ms ?? '—' }} ms</dd></div>
                </dl></details>
                <p v-else class="mt-3 text-sm text-soft">Aún no hay sincronizaciones registradas. La consulta automática está {{ sybiIntegration.automatic_sync_enabled ? 'habilitado' : 'deshabilitado' }}.</p>
            </article>

            <nav class="flex gap-2" aria-label="Vista del catálogo">
                <button v-for="tab in [{key:'operational',label:'Operativas'},{key:'sybi',label:'Catálogo SYBI'}]" :key="tab.key" type="button" class="rounded-xl px-4 py-2 text-sm font-semibold" :class="filters.catalog_view === tab.key ? 'bg-indigo-600 text-white' : 'border border-app text-app'" @click="selectCatalogView(tab.key)">{{ tab.label }}</button>
            </nav>

            <form class="card grid gap-3 p-4 sm:grid-cols-3" @submit.prevent="applyFilters">
 <label class="text-sm">Buscar<input v-model="filters.search" class="mt-1 w-full rounded-xl border-app" placeholder="Código, nombre o identificador SYBI" /></label>
 <label v-if="filters.catalog_view === 'operational'" class="text-sm">Estado<select v-model="filters.status" class="mt-1 w-full rounded-xl border-app"><option value="">Todos</option><option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
 <label v-else class="text-sm">Validación<select v-model="filters.validation_status" class="mt-1 w-full rounded-xl border-app"><option value="">Todas</option><option v-for="status in validationStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
 <div class="flex items-end gap-2"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-white">Buscar</button><button type="button" class="rounded-xl border border-app px-4 py-2" @click="clearFilters">Limpiar</button></div>
 <AdvancedFilters :count="activeFilterCount(filters, filters.catalog_view === 'operational' ? ['source','sync_status','municipality','locality'] : ['source_status'])">
  <template v-if="filters.catalog_view === 'operational'"><label class="text-sm">Origen<select v-model="filters.source" class="mt-1 w-full rounded-xl border-app"><option value="">Todos</option><option v-for="source in catalogSources" :key="source" :value="source">{{ statusLabel(source) }}</option></select></label><label class="text-sm">Sincronización<select v-model="filters.sync_status" class="mt-1 w-full rounded-xl border-app"><option value="">Todas</option><option v-for="status in syncStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label><label class="text-sm">Municipio<select v-model="filters.municipality" class="mt-1 w-full rounded-xl border-app"><option value="">Todos</option><option v-for="value in municipalities" :key="value" :value="value">{{ value }}</option></select></label><label class="text-sm">Localidad<select v-model="filters.locality" class="mt-1 w-full rounded-xl border-app"><option value="">Todas</option><option v-for="value in localities" :key="value" :value="value">{{ value }}</option></select></label></template>
  <label v-else class="text-sm">Presencia en SYBI<select v-model="filters.source_status" class="mt-1 w-full rounded-xl border-app"><option value="">Todas</option><option v-for="status in sourceStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
 </AdvancedFilters>
</form>

            <template v-if="filters.catalog_view === 'operational'">
                <div class="overflow-x-auto rounded-2xl border border-app bg-white dark:bg-slate-900">
                    <table class="w-full text-left text-sm">
 <caption class="sr-only">Máquinas operativas</caption>
 <thead class="bg-slate-50 text-soft dark:bg-slate-800"><tr><th class="p-3">Máquina</th><th class="p-3">Ubicación</th><th class="p-3">Estado</th><th class="p-3">Geocerca</th><th class="p-3">Empleados</th><th class="p-3">Acciones</th></tr></thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800"><tr v-for="machine in machines.data" :key="machine.uuid" class="align-top">
 <td class="p-3"><Link class="font-semibold text-indigo-600" :href="route('vending-machines.show',machine.uuid)">{{ machine.machine_code }}</Link><p class="text-sm">{{ machine.name || 'Sin nombre' }}</p><p class="text-xs text-soft">{{ statusLabel(machine.source) }}</p></td>
 <td class="p-3">{{ machine.municipality || 'Sin municipio' }}<p class="text-xs text-soft">{{ machine.locality || 'Sin localidad' }}</p></td>
 <td class="p-3"><StatusBadge :value="machine.status" /></td>
 <td class="p-3"><p>{{ machine.geofence_review_required ? 'Requiere revisión' : machine.active_geofence ? 'Activa' : 'Sin geocerca activa' }}</p><p class="text-xs text-soft">Radio: {{ geofenceRadius(machine) }} m</p></td>
 <td class="p-3">{{ machine.active_assignments_count }}<p class="text-xs text-soft">Asignaciones activas</p></td>
 <td class="p-3"><Link :href="route('vending-machines.show',machine.uuid)" class="block min-h-11 py-2 font-semibold text-indigo-600">Ver detalle y dispositivos</Link><button v-if="can('update')" class="min-h-11 text-indigo-600" @click="openEdit(machine)">Editar</button>
 <TechnicalDetails><p>SYBI: {{ machine.sybi_id || '—' }}</p><p>Sincronización: {{ statusLabel(machine.sybi_sync_status) }} · {{ formatDateTime(machine.sybi_last_seen_at) }}</p><p>Coordenadas: {{ machine.latitude ?? '—' }}, {{ machine.longitude ?? '—' }}</p><p>Verificadas: {{ machine.coordinates_verified ? 'Sí' : 'No' }}</p></TechnicalDetails></td>
 </tr><tr v-if="!machines.data.length"><td colspan="6" class="p-8 text-center text-soft">No hay máquinas con estos filtros. Prueba otra búsqueda o limpia los filtros.</td></tr></tbody>
</table>
                </div>
                <RecordPagination :links="machines.links" />
            </template>

            <template v-else>
                <div class="overflow-x-auto rounded-2xl border border-app bg-white dark:bg-slate-900">
                    <table class="w-full text-left text-sm"><caption class="sr-only">Información del catálogo SYBI</caption><thead class="bg-slate-50 text-soft dark:bg-slate-800"><tr><th class="p-3">Máquina</th><th class="p-3">Ubicación</th><th class="p-3">Validación</th><th class="p-3">Máquina vinculada</th><th class="p-3">Detalle</th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr v-for="record in sourceRecords.data" :key="record.uuid" class="align-top"><td class="p-3 font-semibold">{{ record.identificador_vending || 'Sin identificador' }}<p class="text-sm font-normal">{{ record.name || 'Sin nombre' }}</p><p class="mt-1 text-xs font-normal text-soft">{{ statusLabel(record.source_status) }}</p></td><td class="max-w-sm p-3 text-sm">{{ sourceAddress(record) }}</td><td class="p-3"><StatusBadge :value="record.validation_status" /><ul class="mt-2 space-y-1 text-xs text-soft"><li v-for="code in record.validation_codes || []" :key="code">{{ friendlyError(code) }}</li></ul></td><td class="p-3"><Link v-if="record.promoted_vending_machine" class="font-semibold text-indigo-600" :href="route('vending-machines.show',record.promoted_vending_machine.uuid)">{{ record.promoted_vending_machine.machine_code }}</Link><p v-if="record.promoted_vending_machine" class="text-xs text-soft">{{ statusLabel(record.promoted_vending_machine.status) }}</p><span v-else class="text-sm text-soft">Aún no incorporada a la operación</span></td><td class="p-3"><TechnicalDetails><p>Identificador SYBI: {{ record.sybi_id }}</p><p>Coordenadas: {{ record.latitude ?? '—' }}, {{ record.longitude ?? '—' }}</p><p>Primera consulta: {{ formatDateTime(record.first_seen_at) }}</p><p>Última consulta: {{ formatDateTime(record.last_seen_at) }}</p><p>Códigos: {{ (record.validation_codes || []).join(', ') || 'Ninguno' }}</p></TechnicalDetails></td></tr>
 <tr v-if="!sourceRecords.data.length"><td colspan="5" class="p-8 text-center text-soft">No hay registros de SYBI que coincidan con los filtros.</td></tr></tbody></table>
                </div>
                <RecordPagination :links="sourceRecords.links" />
            </template>
        </section>

        <Modal :show="formOpen" :closeable="!form.processing" @close="formOpen = false">
            <form class="mx-auto grid max-w-4xl gap-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900 md:grid-cols-2" @submit.prevent="submit">
                <div class="md:col-span-2"><h2 class="text-lg font-semibold text-app">{{ editing ? 'Editar máquina' : 'Nueva máquina' }}</h2><p class="text-sm text-soft">Dirección textual y coordenadas se administran por separado.</p></div>
                <p v-if="isSybiEdit()" class="rounded-lg bg-indigo-50 p-3 text-sm text-indigo-900 dark:bg-indigo-950/30 dark:text-indigo-100 md:col-span-2">Los campos del catálogo SYBI son de sólo lectura y se actualizan exclusivamente desde SYBI.</p>
                <label v-for="field in [{k:'machine_code',l:'Código *'},{k:'sybi_id',l:'SYBI ID'},{k:'operational_code',l:'Código operacional'},{k:'name',l:'Nombre'},{k:'address_line',l:'Dirección'},{k:'neighborhood',l:'Colonia'},{k:'locality',l:'Localidad'},{k:'municipality',l:'Municipio'},{k:'state',l:'Estado'},{k:'postal_code',l:'CP'},{k:'latitude',l:'Latitud'},{k:'longitude',l:'Longitud'},{k:'default_geofence_radius_m',l:'Radio predeterminado (m)'}]" :key="field.k" class="text-sm text-app">{{ field.l }}<input v-model="form[field.k]" class="mt-1 w-full rounded-xl border-app disabled:bg-slate-100 disabled:text-slate-500 dark:disabled:bg-slate-800" :disabled="isSybiOwned(field.k)" :type="['latitude','longitude','default_geofence_radius_m'].includes(field.k) ? 'number' : 'text'" :step="['latitude','longitude'].includes(field.k) ? '0.0000001' : undefined" /><span v-if="form.errors[field.k]" class="text-xs text-rose-600">{{ friendlyError(form.errors[field.k]) }}</span></label>
                <label class="text-sm text-app">Origen<select v-model="form.coordinate_source" class="mt-1 w-full rounded-xl border-app disabled:bg-slate-100 dark:disabled:bg-slate-800" :disabled="isSybiEdit()"><option value="">Sin definir</option><option v-for="source in coordinateSources" :key="source" :value="source">{{ statusLabel(source) }}</option></select></label>
                <label class="text-sm text-app">Estado<select v-model="form.status" class="mt-1 w-full rounded-xl border-app"><option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
                <label class="text-sm text-app">Zona horaria<input v-model="form.timezone" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="flex items-center gap-2 text-sm text-app"><input v-model="form.coordinates_verified" type="checkbox" /> Coordenadas verificadas</label>
                <div class="md:col-span-2 flex justify-end gap-2"><button type="button" class="rounded-xl border border-app px-4 py-2" @click="formOpen = false">Cancelar</button><button class="rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white" :disabled="form.processing">Guardar</button></div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
