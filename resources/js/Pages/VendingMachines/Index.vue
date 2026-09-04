<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    machines: Object,
    filters: Object,
    statuses: Array,
    coordinateSources: Array,
    municipalities: Array,
    localities: Array,
});

const filters = reactive({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
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

const applyFilters = () => router.get(route('vending-machines.index'), filters, { preserveState: true, replace: true });
const clearFilters = () => {
    Object.assign(filters, { search: '', status: '', municipality: '', locality: '' });
    applyFilters();
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
</script>

<template>
    <Head title="Máquinas vending" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-xl font-semibold text-app">Máquinas vending</h1><p class="text-sm text-soft">Catálogo maestro operacional independiente de sucursales y unidades.</p></div>
                <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" @click="openCreate">Nueva máquina</button>
            </div>
        </template>

        <section class="space-y-5">
            <form class="card grid gap-3 p-4 md:grid-cols-5" @submit.prevent="applyFilters">
                <input v-model="filters.search" class="rounded-xl border-app md:col-span-2" placeholder="Código, nombre o SYBI" />
                <select v-model="filters.status" class="rounded-xl border-app"><option value="">Todos los estados</option><option v-for="status in statuses" :key="status">{{ status }}</option></select>
                <select v-model="filters.municipality" class="rounded-xl border-app"><option value="">Todos los municipios</option><option v-for="value in municipalities" :key="value">{{ value }}</option></select>
                <div class="flex gap-2"><button class="rounded-xl bg-slate-900 px-4 py-2 text-sm text-white dark:bg-slate-100 dark:text-slate-900">Buscar</button><button type="button" class="rounded-xl border border-app px-4 py-2 text-sm" @click="clearFilters">Limpiar</button></div>
            </form>

            <div class="overflow-x-auto rounded-2xl border border-app bg-white dark:bg-slate-900">
                <table class="min-w-[72rem] w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-soft dark:bg-slate-800"><tr><th class="p-3">Código</th><th class="p-3">Estado</th><th class="p-3">Municipio / localidad</th><th class="p-3">Coordenadas</th><th class="p-3">Verificadas</th><th class="p-3">Radio</th><th class="p-3">Empleados</th><th class="p-3">Acciones</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="machine in machines.data" :key="machine.uuid">
                            <td class="p-3"><Link class="font-semibold text-indigo-600" :href="route('vending-machines.show', machine.uuid)">{{ machine.machine_code }}</Link><div class="text-xs text-soft">{{ machine.name || machine.sybi_id || 'Sin nombre' }}</div></td>
                            <td class="p-3"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold dark:bg-slate-800">{{ machine.status }}</span></td>
                            <td class="p-3">{{ machine.municipality || '—' }}<div class="text-xs text-soft">{{ machine.locality || '—' }}</div></td>
                            <td class="p-3 font-mono text-xs">{{ machine.latitude ?? '—' }}, {{ machine.longitude ?? '—' }}</td>
                            <td class="p-3">{{ machine.coordinates_verified ? 'Sí' : 'No' }}</td>
                            <td class="p-3">{{ geofenceRadius(machine) }} m</td>
                            <td class="p-3">{{ machine.active_assignments_count }}</td>
                            <td class="p-3"><button class="text-sm font-semibold text-indigo-600" @click="openEdit(machine)">Editar</button></td>
                        </tr>
                        <tr v-if="!machines.data.length"><td colspan="8" class="p-8 text-center text-soft">No hay máquinas con estos filtros.</td></tr>
                    </tbody>
                </table>
            </div>
            <nav class="flex flex-wrap gap-2"><Link v-for="link in machines.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="rounded-lg border border-app px-3 py-1.5 text-sm" :class="{ 'bg-indigo-600 text-white': link.active, 'pointer-events-none opacity-40': !link.url }" v-html="link.label" /></nav>
        </section>

        <div v-if="formOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 p-4" @click.self="formOpen = false">
            <form class="mx-auto grid max-w-4xl gap-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900 md:grid-cols-2" @submit.prevent="submit">
                <div class="md:col-span-2"><h2 class="text-lg font-semibold text-app">{{ editing ? 'Editar máquina' : 'Nueva máquina' }}</h2><p class="text-sm text-soft">Dirección textual y coordenadas se administran por separado.</p></div>
                <label v-for="field in [{k:'machine_code',l:'Código *'},{k:'sybi_id',l:'SYBI ID'},{k:'operational_code',l:'Código operacional'},{k:'name',l:'Nombre'},{k:'address_line',l:'Dirección'},{k:'neighborhood',l:'Colonia'},{k:'locality',l:'Localidad'},{k:'municipality',l:'Municipio'},{k:'state',l:'Estado'},{k:'postal_code',l:'CP'},{k:'latitude',l:'Latitud'},{k:'longitude',l:'Longitud'},{k:'default_geofence_radius_m',l:'Radio predeterminado (m)'}]" :key="field.k" class="text-sm text-app">{{ field.l }}<input v-model="form[field.k]" class="mt-1 w-full rounded-xl border-app" :type="['latitude','longitude','default_geofence_radius_m'].includes(field.k) ? 'number' : 'text'" :step="['latitude','longitude'].includes(field.k) ? '0.0000001' : undefined" /><span v-if="form.errors[field.k]" class="text-xs text-rose-600">{{ form.errors[field.k] }}</span></label>
                <label class="text-sm text-app">Origen<select v-model="form.coordinate_source" class="mt-1 w-full rounded-xl border-app"><option value="">Sin definir</option><option v-for="source in coordinateSources" :key="source">{{ source }}</option></select></label>
                <label class="text-sm text-app">Estado<select v-model="form.status" class="mt-1 w-full rounded-xl border-app"><option v-for="status in statuses" :key="status">{{ status }}</option></select></label>
                <label class="text-sm text-app">Zona horaria<input v-model="form.timezone" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="flex items-center gap-2 text-sm text-app"><input v-model="form.coordinates_verified" type="checkbox" /> Coordenadas verificadas</label>
                <div class="md:col-span-2 flex justify-end gap-2"><button type="button" class="rounded-xl border border-app px-4 py-2" @click="formOpen = false">Cancelar</button><button class="rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white" :disabled="form.processing">Guardar</button></div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
