<script setup>
import StatusBadge from '@/Components/StatusBadge.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import { statusLabel, friendlyError, formatDateTime, isFortiaMock } from '@/presentation/labels';
import { canUse } from '@/presentation/navigation';
import { apiUrl } from '@/utils/url';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import StagedEmployeeImport from './Partials/StagedEmployeeImport.vue';

const props = defineProps({ employees: Object, filters: Object, fortia: Object, capabilities: Object, limits: Object });
const filters = reactive({ q: props.filters.q ?? '', source: props.filters.source ?? '', status: props.filters.status ?? '' });
const importOpen = ref(false);
const page = usePage();
const canReadAssignments = computed(() => canUse(page.props.auth?.permissions, 'vending_machines', 'view', true));
const assignments = reactive({});
async function loadAssignments(employee) {
 if (!canReadAssignments.value || assignments[employee.id]?.busy) return;
 assignments[employee.id] = { busy: true, rows: null, error: '' };
 try {
  const { data } = await axios.get(apiUrl('/api/v1/employees/' + employee.id + '/vending-machines'));
  assignments[employee.id] = { busy: false, rows: data.data, error: '' };
 } catch { assignments[employee.id] = { busy: false, rows: null, error: 'No fue posible consultar las asignaciones. Intenta de nuevo.' }; }
}
const syncBusy = ref(false);
const syncResult = ref(null);
const syncError = ref('');
const writeConfirmed = ref(false);
const metricLabels = { received: 'Recibidos', created: 'Nuevos', updated: 'Cambios', unchanged: 'Sin cambios', inactive: 'Inactivos', rejected: 'Rechazados', conflicts: 'Conflictos' };
const search = () => router.get(route('vending-employees.index'), filters, { preserveState: true, replace: true });
const refresh = () => router.reload({ only: ['employees'] });
async function sync(dryRun) {
    syncBusy.value = true; syncError.value = '';
    try {
        const { data } = await axios.post(route('vending-employees.sync'), { dry_run: dryRun, ...(dryRun ? {} : { confirmed: writeConfirmed.value }) });
        syncResult.value = data; writeConfirmed.value = false;
        if (!dryRun) refresh();
    } catch (error) { syncResult.value = null; syncError.value = error.response?.data?.error_code ?? 'SYNC_UNAVAILABLE'; }
    finally { syncBusy.value = false; }
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Empleados" />
        <template #header>
            <div><h1 class="text-xl font-semibold text-app">Empleados</h1><p class="text-sm text-soft">Consulta y administra los empleados disponibles para las máquinas vending.</p></div>
        </template>
        <div class="mx-auto max-w-7xl space-y-6">
            <div v-if="capabilities.import" class="flex justify-end"><button class="btn-primary min-h-11" @click="importOpen = true">Importar empleados</button></div>
            <section v-if="isFortiaMock(fortia) || !fortia.real_api_ready || capabilities.sync" class="rounded-xl border border-app bg-surface p-4" aria-labelledby="fortia-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><h2 id="fortia-heading" class="font-semibold text-app">Fortia<span v-if="isFortiaMock(fortia)"> · Modo de prueba</span></h2><p v-if="!fortia.real_api_ready" class="mt-1 text-sm text-amber-700 dark:text-amber-300">La conexión productiva aún no está habilitada.</p></div>
                    <button v-if="capabilities.sync" class="btn-secondary min-h-11" title="Consulta sin guardar cambios" :disabled="syncBusy" @click="sync(true)">{{ syncBusy ? 'Consultando…' : 'Consultar cambios' }}</button>
                </div>
                <template v-if="capabilities.sync">
                    <details class="mt-2 text-sm"><summary class="min-h-11 cursor-pointer py-2 font-medium text-soft">Más información</summary><p class="text-muted">Consultar cambios no guarda información. Revisa el resultado antes de autorizar su aplicación.</p><p v-if="!fortia.write_enabled" class="mt-2 text-muted">La aplicación de cambios de Fortia está desactivada en este entorno.</p></details>
                    <div v-if="fortia.write_enabled && syncResult?.dry_run" class="mt-3 flex flex-wrap gap-3"><label class="flex min-h-11 items-center gap-2 text-sm text-app"><input v-model="writeConfirmed" type="checkbox" :disabled="syncBusy" />Autorizar sincronización</label><button class="btn-primary min-h-11" :disabled="syncBusy || !writeConfirmed" @click="sync(false)">Aplicar sincronización</button></div>
                    <p v-if="syncError" role="alert" class="mt-3 text-sm text-rose-700">{{ friendlyError(syncError) }}</p>
                    <TechnicalDetails v-if="syncError"><p>Código: {{ syncError }}</p></TechnicalDetails>
                    <div v-if="syncResult" class="mt-4" role="status"><p class="text-sm font-medium text-app">{{ syncResult.dry_run ? 'Candidatos; no se guardaron cambios' : 'Sincronización aplicada' }}</p><dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4"><div v-for="(label, key) in metricLabels" :key="key"><dt class="text-xs text-muted">{{ label }}</dt><dd class="text-lg text-app">{{ syncResult.metrics[key] }}</dd></div></dl></div>
                </template>
            </section>
            <form class="grid gap-3 rounded-xl border border-app bg-surface p-4 sm:grid-cols-4" @submit.prevent="search"><label class="text-sm text-app">Número o nombre<input v-model="filters.q" maxlength="120" class="mt-1 min-h-11 w-full rounded-lg border-app bg-surface" /></label><label class="text-sm text-app">Fuente<select v-model="filters.source" class="mt-1 min-h-11 w-full rounded-lg border-app bg-surface"><option value="">Todas</option><option v-for="source in ['FORTIA','MANUAL','DEMO','LEGACY']" :key="source" :value="source">{{ statusLabel(source) }}</option></select></label><label class="text-sm text-app">Estado<select v-model="filters.status" class="mt-1 min-h-11 w-full rounded-lg border-app bg-surface"><option value="">Todos</option><option value="A">Activo</option><option value="B">Inactivo</option></select></label><button class="btn-secondary min-h-11 self-end">Buscar</button></form>
            <div class="overflow-x-auto rounded-xl border border-app bg-surface"><table class="min-w-full text-left text-sm"><caption class="sr-only">Catálogo operacional de empleados</caption><thead><tr><th class="p-4" scope="col">Número</th><th class="p-4" scope="col">Nombre</th><th class="p-4" scope="col">Estado</th><th class="p-4" scope="col">Fuente</th><th class="p-4" scope="col">Última sincronización</th><th v-if="canReadAssignments" class="p-4" scope="col">Máquinas asignadas</th></tr></thead><tbody class="divide-y divide-app"><tr v-for="employee in employees.data" :key="employee.id"><td class="p-4 font-mono">{{ employee.employee_number ?? employee.fortia_employee_id ?? 'Número pendiente' }}</td><td class="p-4">{{ employee.full_name }}</td><td class="p-4"><StatusBadge :value="employee.status" /></td><td class="p-4">{{ statusLabel(employee.source) }}<span v-if="employee.source === 'FORTIA'" class="block text-xs text-muted">Gobernado por Fortia</span></td><td class="whitespace-nowrap p-4">{{ formatDateTime(employee.source_synced_at, 'Sin sincronización') }}</td><td v-if="canReadAssignments" class="p-4"><button type="button" class="min-h-11 text-indigo-600" :disabled="assignments[employee.id]?.busy" @click="loadAssignments(employee)">{{ assignments[employee.id]?.busy ? 'Consultando…' : 'Ver asignaciones' }}</button><p v-if="assignments[employee.id]?.error" role="alert" class="text-xs text-rose-700">{{ assignments[employee.id].error }}</p><ul v-if="assignments[employee.id]?.rows" class="space-y-2"><li v-for="assignment in assignments[employee.id].rows" :key="assignment.uuid"><Link :href="route('vending-machines.show', assignment.machine.uuid)" class="text-indigo-600">{{ assignment.machine.machine_code }}</Link><span class="block text-xs text-soft">{{ statusLabel(assignment.assignment_type) }}</span></li><li v-if="!assignments[employee.id].rows.length" class="text-xs text-soft">Sin asignaciones vigentes.</li></ul></td></tr><tr v-if="!employees.data.length"><td :colspan="canReadAssignments ? 6 : 5" class="p-8 text-center text-muted">No hay empleados con estos filtros.</td></tr></tbody></table></div>
            <nav aria-label="Paginación de empleados" class="flex items-center justify-between gap-3 text-sm"><Link v-if="employees.prev_page_url" :href="employees.prev_page_url" class="btn-secondary min-h-11">Anterior</Link><span class="text-muted">Página {{ employees.current_page }} de {{ employees.last_page }} · {{ employees.total }} empleados</span><Link v-if="employees.next_page_url" :href="employees.next_page_url" class="btn-secondary min-h-11">Siguiente</Link></nav>
            <StagedEmployeeImport v-if="capabilities.import" :open="importOpen" :limits="limits" @close="importOpen = false" @applied="refresh" />
        </div>
    </AuthenticatedLayout>
</template>
