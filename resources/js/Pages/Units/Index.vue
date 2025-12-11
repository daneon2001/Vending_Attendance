<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UnitCard from './Partials/UnitCard.vue';
import UnitFormModal from './Partials/UnitFormModal.vue';
import UnitDetailDrawer from './Partials/UnitDetailDrawer.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    units: {
        type: Array,
        default: () => [],
    },
    companies: {
        type: Array,
        default: () => [],
    },
});

const units = ref(props.units ?? []);
const meta = ref({
    total: units.value.length,
    next_page_url: null,
});
const listLoading = ref(false);
const listError = ref('');

const filters = reactive({
    search: '',
    company_id: '',
    status: '',
});

const filtersReady = ref(false);

const defaultForm = (unit = null) => ({
    id: unit?.id ?? null,
    company_id: unit?.company?.id ?? props.companies[0]?.id ?? '',
    code: unit?.code ?? '',
    name: unit?.name ?? '',
    description: unit?.description ?? '',
    city: unit?.city ?? '',
    state: unit?.state ?? '',
    country: unit?.country ?? '',
    address: unit?.address ?? '',
    timezone: unit?.timezone ?? 'America/Mexico_City',
    status: unit?.status ?? 1,
});

const formState = reactive({
    open: false,
    mode: 'create',
    form: defaultForm(),
    errors: {},
    loading: false,
});

const detailState = reactive({
    open: false,
    data: null,
    loading: false,
    error: null,
});

const confirmState = reactive({
    open: false,
    unit: null,
    loading: false,
});

const totalActive = computed(() => units.value.filter((unit) => unit.status === 1).length);
const totalInactive = computed(() => units.value.filter((unit) => unit.status === 0).length);

const fetchUnits = async (url = null, append = false) => {
    listLoading.value = true;
    listError.value = '';

    try {
        const endpoint = url ?? route('units.list');
        const config = {};
        if (!url) {
            config.params = {
                search: filters.search || undefined,
                company_id: filters.company_id || undefined,
                status: filters.status !== '' ? filters.status : undefined,
            };
        }

        const { data } = await axios.get(endpoint, config);
        units.value = append ? [...units.value, ...(data.data ?? [])] : data.data ?? [];
        meta.value = data.meta ?? meta.value;
    } catch (error) {
        listError.value = error.response?.data?.message ?? 'No se pudo cargar el catálogo.';
    } finally {
        listLoading.value = false;
    }
};

onMounted(async () => {
    await fetchUnits();
    filtersReady.value = true;
});

watch(
    () => ({ ...filters }),
    () => {
        if (!filtersReady.value) return;
        fetchUnits();
    },
    { deep: true },
);

const openCreateForm = () => {
    formState.mode = 'create';
    formState.form = defaultForm();
    formState.errors = {};
    formState.open = true;
};

const openEditForm = (unit) => {
    formState.mode = 'edit';
    formState.form = defaultForm(unit);
    formState.errors = {};
    formState.open = true;
};

const submitForm = async () => {
    formState.loading = true;
    formState.errors = {};

    try {
        const payload = { ...formState.form };
        if (formState.mode === 'create') {
            await axios.post(route('units.store'), payload);
        } else {
            await axios.put(route('units.update', payload.id), payload);
        }
        formState.open = false;
        await fetchUnits();
    } catch (error) {
        if (error.response?.status === 422) {
            formState.errors = error.response.data.errors ?? {};
        }
    } finally {
        formState.loading = false;
    }
};

const viewDetail = async (unit) => {
    detailState.open = true;
    detailState.loading = true;
    detailState.error = null;
    detailState.data = null;

    try {
        const { data } = await axios.get(route('units.show', unit.id));
        detailState.data = data.data;
    } catch (error) {
        detailState.error = error.response?.data?.message ?? 'No se pudo cargar la información.';
    } finally {
        detailState.loading = false;
    }
};

const requestToggle = (unit) => {
    confirmState.unit = unit;
    confirmState.open = true;
};

const toggleStatus = async () => {
    if (!confirmState.unit) return;

    confirmState.loading = true;
    try {
        await axios.put(route('units.toggle-status', confirmState.unit.id));
        confirmState.open = false;
        confirmState.unit = null;
        await fetchUnits();
    } finally {
        confirmState.loading = false;
    }
};

const loadMore = () => {
    if (meta.value?.next_page_url) {
        fetchUnits(meta.value.next_page_url, true);
    }
};

const clearFilters = () => {
    filters.search = '';
    filters.company_id = '';
    filters.status = '';
};
</script>

<template>
    <Head title="Catálogo de sucursales" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="mt-1 text-2xl font-semibold text-slate-900">
                    Catálogo de sucursales
                </h1>
                <p class="text-sm text-slate-500">
                    Administra las unidades operativas y asignaciones de relojes.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-indigo-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-indigo-500">
                        Total
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ meta.total ?? units.length }}
                    </p>
                    <p class="text-sm text-slate-500">Sucursales registradas</p>
                </article>
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-emerald-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-500">
                        Activas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalActive }}
                    </p>
                    <p class="text-sm text-slate-500">Operando actualmente</p>
                </article>
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-rose-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-rose-500">
                        Inactivas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalInactive }}
                    </p>
                    <p class="text-sm text-slate-500">En mantenimiento o pausa</p>
                </article>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-100 bg-white/90 p-4 shadow-sm">
                <div class="flex flex-wrap gap-3">
                    <div class="flex items-center gap-2 rounded-2xl border border-slate-200 px-3 py-1.5">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Empresa</span>
                        <select
                            v-model="filters.company_id"
                            class="bg-transparent text-sm font-medium text-slate-700 focus:outline-none"
                        >
                            <option value="">Todas</option>
                            <option v-for="company in companies" :key="company.id" :value="company.id">
                                {{ company.name }}
                            </option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2 rounded-2xl border border-slate-200 px-3 py-1.5">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Estado</span>
                        <select
                            v-model="filters.status"
                            class="bg-transparent text-sm font-medium text-slate-700 focus:outline-none"
                        >
                            <option value="">Todos</option>
                            <option :value="1">Activas</option>
                            <option :value="0">Inactivas</option>
                        </select>
                    </div>

                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Buscar por nombre o código"
                        class="rounded-2xl border border-slate-200 px-4 py-2 text-sm shadow-sm focus:border-indigo-400 focus:outline-none"
                    />
                </div>

                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-2xl border border-slate-200 px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900"
                        @click="clearFilters"
                    >
                        Limpiar
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-600/30 hover:bg-indigo-500"
                        @click="openCreateForm"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                fill-rule="evenodd"
                                d="M10 4a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 011-1z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        Nueva sucursal
                    </button>
                </div>
            </div>

            <div v-if="listError" class="rounded-3xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-600">
                {{ listError }}
            </div>

            <div v-if="listLoading" class="rounded-3xl border border-slate-100 bg-white/80 p-6 text-sm text-slate-500">
                Cargando catálogo...
            </div>

            <div v-if="!listLoading" class="space-y-4">
                <UnitCard
                    v-for="unit in units"
                    :key="unit.id"
                    :unit="unit"
                    @view="viewDetail"
                    @edit="openEditForm"
                    @toggle="requestToggle"
                />

                <p v-if="!units.length && !listLoading" class="rounded-3xl border border-slate-100 bg-white/80 p-6 text-center text-sm text-slate-500">
                    No se encontraron sucursales con los filtros seleccionados.
                </p>

                <button
                    v-if="meta?.next_page_url"
                    type="button"
                    class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 disabled:opacity-60"
                    :disabled="listLoading"
                    @click="loadMore"
                >
                    {{ listLoading ? 'Cargando...' : 'Ver más' }}
                </button>
            </div>
        </section>

        <UnitFormModal
            :open="formState.open"
            :mode="formState.mode"
            :companies="companies"
            :form="formState.form"
            :errors="formState.errors"
            :loading="formState.loading"
            @close="formState.open = false"
            @submit="submitForm"
        />

        <UnitDetailDrawer
            :open="detailState.open"
            :detail="detailState.data"
            :loading="detailState.loading"
            :error="detailState.error"
            @close="detailState.open = false"
        />

        <div
            v-if="confirmState.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
                <h3 class="text-xl font-semibold text-slate-900">
                    {{ confirmState.unit?.status ? 'Desactivar sucursal' : 'Activar sucursal' }}
                </h3>
                <p class="mt-2 text-sm text-slate-500">
                    {{
                        confirmState.unit?.status
                            ? '¿Deseas desactivar esta sucursal? Los relojes seguirán vinculados pero no se podrán asignar nuevas operaciones.'
                            : '¿Deseas activar la sucursal para permitir asignaciones y monitoreo?'
                    }}
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900"
                        @click="confirmState.open = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60"
                        :disabled="confirmState.loading"
                        @click="toggleStatus"
                    >
                        <span v-if="confirmState.loading">Procesando...</span>
                        <span v-else>Confirmar</span>
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
