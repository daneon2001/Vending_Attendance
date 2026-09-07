<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdvancedFilters from '@/Components/AdvancedFilters.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import RecordPagination from '@/Components/RecordPagination.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SupportNotificationFeed from '@/Components/SupportNotificationFeed.vue';
import { activeFilterCount, formatDateTime } from '@/presentation/labels';
import { canSupport, supportAdvancedKeys, supportBadgeClass, supportError, supportLabel, supportOperationUuid, supportPageLinks, supportSeverities, supportSources, supportStatuses } from '@/presentation/support';

const props = defineProps({ tickets: Array, pagination: Object, filters: Object, stats: Object, options: Object });
const page = usePage();
const canReport = computed(() => canSupport(page.props.auth?.permissions, 'report'));
const rows = computed(() => props.tickets ?? []);
const choices = ref(props.options ?? {});
const machines = computed(() => choices.value.machines ?? []);
const categories = computed(() => choices.value.categories ?? []);
const assignees = computed(() => choices.value.assignees ?? []);
const filterFields = ['search', 'status', 'severity', 'assignee_id', ...supportAdvancedKeys];
const filters = reactive(Object.fromEntries(filterFields.map((key) => [key, props.filters?.[key] ?? ''])));
const loading = ref(false);
const filterError = ref('');
const apply = (pageNumber = 1) => {
    loading.value = true;
    filterError.value = '';
    router.get(route('support.tickets.index'), { ...filters, page: pageNumber }, {
        preserveState: true, replace: true,
        onError: () => { filterError.value = 'Revisa los filtros e intenta nuevamente.'; },
        onFinish: () => { loading.value = false; },
    });
};
const clear = () => { filterFields.forEach((key) => { filters[key] = ''; }); apply(); };
const advancedCount = computed(() => activeFilterCount(filters, supportAdvancedKeys));
const links = computed(() => supportPageLinks(props.pagination, (number) => route('support.tickets.index', { ...filters, page: number })));
const selectedFilterMachineMissing = computed(() => filters.vending_machine_id && !machines.value.some((machine) => String(machine.id) === String(filters.vending_machine_id)));

const machineSearch = ref('');
const searchingMachines = ref(false);
const machineError = ref('');
const machinesSearched = ref(false);
const searchMachines = async () => {
    searchingMachines.value = true;
    machineError.value = '';
    try {
        const { data } = await axios.get(route('support.options'), { params: { search: machineSearch.value } });
        choices.value = data;
        machinesSearched.value = true;
    } catch (error) {
        machineError.value = supportError(error, 'No fue posible buscar las máquinas. Intenta nuevamente.');
    } finally {
        searchingMachines.value = false;
    }
};

const create = useForm({ client_operation_uuid: '', vending_machine_id: '', device_id: '', category: '', title: '', description: '', severity: 'MEDIUM', priority: 'NORMAL' });
const createError = ref('');
let createFingerprint = '';
const availableDevices = computed(() => (choices.value.devices ?? []).filter((device) => String(device.vending_machine_id) === String(create.vending_machine_id)));
const createTicket = () => {
    createError.value = '';
    try {
        const fingerprint = JSON.stringify([create.vending_machine_id, create.device_id, create.category, create.title, create.description, create.severity, create.priority]);
        if (!create.client_operation_uuid || fingerprint !== createFingerprint) create.client_operation_uuid = supportOperationUuid();
        createFingerprint = fingerprint;
        create.post(route('support.tickets.store'), { onSuccess: () => { create.reset(); createFingerprint = ''; } });
    } catch (error) {
        createError.value = error.message;
    }
};
const metrics = [
    ['open', 'Abiertos'], ['high_critical', 'Impacto alto o crítico'], ['unassigned', 'Sin responsable'],
    ['sla_warning', 'Tiempo objetivo próximo'], ['sla_breached', 'Tiempo objetivo excedido'], ['created_today', 'Creados hoy'],
];
</script>

<template>
    <Head title="Tickets de soporte" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-xl font-semibold text-app">Tickets</h1><p class="mt-1 text-sm text-soft">Reporta incidencias y da seguimiento a la atención de las máquinas.</p></div>
        </template>
        <div class="space-y-5">
            <section class="grid grid-cols-2 gap-3 xl:grid-cols-6" aria-label="Resumen de los reportes que puedes consultar">
                <article v-for="[key, label] in metrics" :key="key" class="card p-4">
                    <h2 class="text-sm text-soft">{{ label }}</h2><p class="mt-2 text-2xl font-semibold text-app">{{ stats?.[key] ?? '—' }}</p>
                </article>
            </section>

            <SupportNotificationFeed />

            <details v-if="canReport" class="card p-4 sm:p-5">
                <summary class="min-h-11 cursor-pointer py-2 font-semibold text-indigo-700 dark:text-indigo-300">Reportar incidencia</summary>
                <p class="mt-2 text-sm text-soft">Describe el problema de la máquina. Podrás adjuntar fotos después de crear el reporte.</p>
                <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                    <label class="min-w-0 flex-1 text-sm">Buscar máquina<input v-model="machineSearch" maxlength="160" class="mt-1 w-full border-app" placeholder="Código o nombre de la máquina" @keydown.enter.prevent="searchMachines" /></label>
                    <SecondaryButton :disabled="searchingMachines" @click="searchMachines">{{ searchingMachines ? 'Buscando…' : 'Buscar máquinas' }}</SecondaryButton>
                </div>
                <p v-if="machineError" class="mt-2 text-sm text-rose-700 dark:text-rose-300" role="alert">{{ machineError }}</p>
                <p v-else-if="machinesSearched" class="mt-2 text-sm text-soft" role="status">{{ machines.length ? 'Selecciona una máquina de los resultados.' : 'No hay máquinas que coincidan con la búsqueda.' }}</p>
                <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="createTicket">
                    <label class="text-sm">Máquina<select v-model="create.vending_machine_id" required class="mt-1 w-full border-app" :disabled="create.processing" @change="create.device_id = ''"><option value="">Selecciona una máquina</option><option v-for="machine in machines" :key="machine.id" :value="machine.id">{{ machine.machine_code }}{{ machine.name ? ' · ' + machine.name : '' }}</option></select><InputError :message="create.errors.vending_machine_id" /></label>
                    <label class="text-sm">Categoría<select v-model="create.category" required class="mt-1 w-full border-app" :disabled="create.processing"><option value="">Selecciona el problema</option><option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option></select><InputError :message="create.errors.category" /></label>
                    <label class="text-sm sm:col-span-2">Problema<input v-model="create.title" required maxlength="160" class="mt-1 w-full border-app" placeholder="Por ejemplo: la pantalla no responde" :disabled="create.processing" /><InputError :message="create.errors.title" /></label>
                    <label class="text-sm sm:col-span-2">Descripción<textarea v-model="create.description" required maxlength="5000" rows="4" class="mt-1 w-full border-app" placeholder="Explica lo que observaste y cuándo ocurrió." :disabled="create.processing" /><InputError :message="create.errors.description" /></label>
                    <label class="text-sm">Impacto técnico<select v-model="create.severity" class="mt-1 w-full border-app" :disabled="create.processing"><option v-for="severity in supportSeverities" :key="severity" :value="severity">{{ supportLabel(severity) }}</option></select></label>
                    <label class="text-sm">Prioridad operativa<select v-model="create.priority" class="mt-1 w-full border-app" :disabled="create.processing"><option v-for="priority in ['LOW', 'NORMAL', 'HIGH', 'URGENT']" :key="priority" :value="priority">{{ supportLabel(priority) }}</option></select></label>
                    <label class="text-sm sm:col-span-2">Equipo relacionado <span class="text-soft">(opcional)</span><select v-model="create.device_id" class="mt-1 w-full border-app" :disabled="create.processing || !create.vending_machine_id"><option value="">Sin equipo específico</option><option v-for="device in availableDevices" :key="device.id" :value="device.id">{{ device.device_name || 'Equipo de la máquina' }}</option></select><InputError :message="create.errors.device_id" /></label>
                    <div class="sm:col-span-2"><p v-if="createError" class="mb-2 text-sm text-rose-700 dark:text-rose-300" role="alert">{{ createError }}</p><InputError :message="create.errors.support || create.errors.client_operation_uuid" /><button class="min-h-11 rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="create.processing">{{ create.processing ? 'Enviando reporte…' : 'Enviar reporte' }}</button></div>
                </form>
            </details>

            <form class="card grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="apply()">
                <label class="text-sm">Buscar<input v-model="filters.search" maxlength="160" class="mt-1 w-full border-app" placeholder="Problema o folio" /></label>
                <label class="text-sm">Estado<select v-model="filters.status" class="mt-1 w-full border-app"><option value="">Todos</option><option v-for="status in supportStatuses" :key="status" :value="status">{{ supportLabel(status) }}</option></select></label>
                <label class="text-sm">Impacto técnico<select v-model="filters.severity" class="mt-1 w-full border-app"><option value="">Todos</option><option v-for="severity in supportSeverities" :key="severity" :value="severity">{{ supportLabel(severity) }}</option></select></label>
                <label class="text-sm">Responsable<select v-model="filters.assignee_id" class="mt-1 w-full border-app"><option value="">Todos</option><option v-for="assignee in assignees" :key="assignee.id" :value="assignee.id">{{ assignee.name }}</option></select></label>
                <AdvancedFilters :count="advancedCount">
                    <div class="col-span-full flex flex-col gap-2 sm:flex-row sm:items-end"><label class="min-w-0 flex-1 text-sm">Buscar máquina por código o nombre<input v-model="machineSearch" maxlength="160" class="mt-1 w-full border-app" placeholder="Buscar en el catálogo de máquinas" @keydown.enter.prevent="searchMachines" /></label><SecondaryButton :disabled="searchingMachines" @click="searchMachines">{{ searchingMachines ? 'Buscando…' : 'Buscar máquinas' }}</SecondaryButton></div>
                    <p v-if="machineError" class="col-span-full text-sm text-rose-700 dark:text-rose-300" role="alert">{{ machineError }}</p>
                    <p v-else-if="machinesSearched" class="col-span-full text-xs text-soft" role="status">{{ machines.length ? 'Elige la máquina entre los resultados y aplica los filtros.' : 'No se encontraron máquinas para esa búsqueda.' }}</p>
                    <label class="text-sm">Máquina<select v-model="filters.vending_machine_id" class="mt-1 w-full border-app"><option value="">Todas</option><option v-if="selectedFilterMachineMissing" :value="filters.vending_machine_id">Máquina seleccionada</option><option v-for="machine in machines" :key="machine.id" :value="machine.id">{{ machine.machine_code }}{{ machine.name ? ' · ' + machine.name : '' }}</option></select></label>
                    <label class="text-sm">Equipo<select v-model="filters.device_id" class="mt-1 w-full border-app"><option value="">Todos</option><option v-for="device in choices.devices ?? []" :key="device.id" :value="device.id">{{ device.device_name || 'Equipo de la máquina' }}</option></select></label>
                    <label class="text-sm">Categoría<select v-model="filters.category" class="mt-1 w-full border-app"><option value="">Todas</option><option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option></select></label>
                    <label class="text-sm">Origen<select v-model="filters.source" class="mt-1 w-full border-app"><option value="">Todos</option><option v-for="source in supportSources" :key="source" :value="source">{{ supportLabel(source) }}</option></select></label>
                    <label class="text-sm">Desde<input v-model="filters.from" type="date" class="mt-1 w-full border-app" /></label>
                    <label class="text-sm">Hasta<input v-model="filters.to" type="date" class="mt-1 w-full border-app" /></label>
                </AdvancedFilters>
                <div class="col-span-full flex flex-wrap gap-2"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="loading">{{ loading ? 'Buscando…' : 'Aplicar filtros' }}</button><SecondaryButton :disabled="loading" @click="clear">Limpiar</SecondaryButton></div>
                <p v-if="filterError" class="col-span-full text-sm text-rose-700 dark:text-rose-300" role="alert">{{ filterError }}</p>
            </form>

            <section v-if="rows.length" class="card overflow-x-auto" aria-label="Listado de tickets">
                <table class="w-full text-left text-sm"><caption class="sr-only">Tickets de soporte que puedes consultar</caption><thead class="bg-slate-50 text-soft dark:bg-slate-800"><tr><th scope="col" class="p-3">Folio</th><th scope="col" class="p-3">Máquina</th><th scope="col" class="p-3">Problema</th><th scope="col" class="p-3">Impacto</th><th scope="col" class="p-3">Estado</th><th scope="col" class="p-3">Responsable</th><th scope="col" class="p-3">Actualizado</th><th scope="col" class="p-3">Acción</th></tr></thead>
                    <tbody><tr v-for="ticket in rows" :key="ticket.uuid" class="border-t border-app align-top"><td class="whitespace-nowrap p-3 font-semibold">{{ ticket.folio }}</td><td class="p-3">{{ ticket.machine?.code || 'Sin información' }}</td><td class="min-w-48 max-w-sm break-words p-3 font-medium">{{ ticket.title }}</td><td class="p-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="supportBadgeClass(ticket.severity)">{{ supportLabel(ticket.severity) }}</span></td><td class="p-3"><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="supportBadgeClass(ticket.status)">{{ supportLabel(ticket.status) }}</span></td><td class="p-3">{{ ticket.assignee?.name || 'Sin responsable' }}</td><td class="whitespace-nowrap p-3">{{ formatDateTime(ticket.updated_at) }}</td><td class="p-3"><Link :href="route('support.tickets.show', ticket.uuid)" class="inline-flex min-h-11 items-center font-semibold text-indigo-700 dark:text-indigo-300" :aria-label="'Ver reporte ' + ticket.folio">Ver reporte</Link></td></tr></tbody>
                </table>
            </section>
            <EmptyState v-else title="Sin reportes para mostrar" message="No hay reportes que coincidan con los filtros dentro de tu acceso. Prueba otra búsqueda o limpia los filtros." />
            <p class="text-sm text-soft">{{ pagination?.total ?? 0 }} reportes en esta consulta · Horarios de Ciudad de México.</p>
            <RecordPagination v-if="links.length" :links="links" />
        </div>
    </AuthenticatedLayout>
</template>
