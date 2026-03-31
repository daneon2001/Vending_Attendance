<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
import Toast from '@/Components/Toast.vue';
import UnitCard from './Partials/UnitCard.vue';
import UnitFormModal from './Partials/UnitFormModal.vue';
import UnitDetailDrawer from './Partials/UnitDetailDrawer.vue';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    initialUnits: {
        type: Object,
        default: () => ({
            data: [],
            meta: null,
        }),
    },
    companies: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};

const canCreateUnits = computed(() => can('units', 'create'));
const canUpdateUnits = computed(() => can('units', 'update'));
const canDisableUnits = computed(() => can('units', 'disable'));

const units = ref(props.initialUnits?.data ?? []);
const pagination = ref(props.initialUnits?.meta ?? null);
const perPage = ref(pagination.value?.per_page ?? 12);
const perPageOptions = [10, 12, 20, 50];
const listLoading = ref(false);
const listError = ref('');

const filters = reactive({
    search: '',
    company_id: '',
    status: '',
});

const filtersReady = ref(false);

const toast = reactive({
    show: false,
    type: 'success',
    title: '',
    message: '',
    duration: 5000,
});

const showToast = ({ type = 'success', title = '', message = '', duration }) => {
    toast.show = false;
    toast.type = type;
    toast.title = title;
    toast.message = message;
    toast.duration = duration ?? (type === 'error' ? 9000 : 5000);
    nextTick(() => {
        toast.show = true;
    });
};

const totalActive = computed(() => units.value.filter((unit) => unit.status === 1).length);
const totalInactive = computed(() => units.value.filter((unit) => unit.status === 0).length);

const pageSummary = computed(() => {
    const total = pagination.value?.total ?? units.value.length;
    if (!total) {
        return { start: 0, end: 0, total: 0 };
    }

    if (pagination.value?.from != null && pagination.value?.to != null) {
        return {
            start: pagination.value.from,
            end: pagination.value.to,
            total,
        };
    }

    const length = units.value.length;
    const start = length ? 1 : 0;
    const end = length;
    return { start, end, total };
});

const currentPage = computed(() => pagination.value?.current_page ?? 1);
const totalPages = computed(() => pagination.value?.last_page ?? 1);
const paginationMeta = computed(() => ({
    current_page: pagination.value?.current_page ?? 1,
    last_page: pagination.value?.last_page ?? 1,
    per_page: pagination.value?.per_page ?? perPage.value,
    total: pagination.value?.total ?? units.value.length,
    from: pagination.value?.from ?? pageSummary.value.start ?? 0,
    to: pagination.value?.to ?? pageSummary.value.end ?? 0,
}));

const collapseStorageKey = 'unit-card-collapsed';
const collapsedMap = ref({});

const loadCollapsedState = () => {
    if (typeof window === 'undefined') {
        collapsedMap.value = {};
        return;
    }

    try {
        const raw = window.localStorage.getItem(collapseStorageKey);
        collapsedMap.value = raw ? JSON.parse(raw) : {};
    } catch {
        collapsedMap.value = {};
    }
};

const persistCollapsedState = () => {
    if (typeof window === 'undefined') return;
    window.localStorage.setItem(collapseStorageKey, JSON.stringify(collapsedMap.value));
};

loadCollapsedState();

const defaultCollapsed = computed(() => units.value.length > 5);

const isCollapsed = (unitId) => {
    const key = String(unitId);
    if (Object.prototype.hasOwnProperty.call(collapsedMap.value, key)) {
        return collapsedMap.value[key];
    }
    return defaultCollapsed.value;
};

const toggleUnitCollapse = (unit) => {
    const key = String(unit.id);
    collapsedMap.value = {
        ...collapsedMap.value,
        [key]: !isCollapsed(unit.id),
    };
    persistCollapsedState();
};

const setCollapseStateForAll = (collapsed) => {
    const next = { ...collapsedMap.value };
    units.value.forEach((unit) => {
        next[String(unit.id)] = collapsed;
    });
    collapsedMap.value = next;
    persistCollapsedState();
};

const collapseAll = () => setCollapseStateForAll(true);
const expandAll = () => setCollapseStateForAll(false);
const allCollapsed = computed(
    () => units.value.length > 0 && units.value.every((unit) => isCollapsed(unit.id)),
);
const collapseToggleLabel = computed(() =>
    allCollapsed.value ? 'Desplegar todas' : 'Colapsar todas',
);
const canToggleAll = computed(() => units.value.length > 0);
const toggleAll = () => {
    if (!units.value.length) return;
    if (allCollapsed.value) {
        expandAll();
    } else {
        collapseAll();
    }
};

watch(
    units,
    (list) => {
        const ids = new Set(list.map((unit) => String(unit.id)));
        const next = { ...collapsedMap.value };
        let changed = false;

        Object.keys(next).forEach((key) => {
            if (!ids.has(key)) {
                delete next[key];
                changed = true;
            }
        });

        if (changed) {
            collapsedMap.value = next;
            persistCollapsedState();
        }
    },
    { deep: true },
);

const fetchUnits = async (page = currentPage.value) => {
    listLoading.value = true;
    listError.value = '';

    try {
        const { data } = await axios.get(route('units.list'), {
            params: {
                search: filters.search || undefined,
                company_id: filters.company_id || undefined,
                status: filters.status !== '' ? filters.status : undefined,
                page,
                per_page: perPage.value,
            },
        });
        units.value = data.data ?? [];
        pagination.value = data.meta ?? pagination.value;
    } catch (error) {
        listError.value = error.response?.data?.message ?? 'No se pudo cargar el catálogo.';
        showToast({
            type: 'error',
            title: 'Error al cargar',
            message: listError.value,
        });
    } finally {
        listLoading.value = false;
    }
};

onMounted(() => {
    filtersReady.value = true;
});

watch(
    () => ({ ...filters }),
    () => {
        if (!filtersReady.value) return;
        fetchUnits(1);
    },
    { deep: true },
);

const handlePageChange = (page) => {
    if (page < 1 || page > totalPages.value || page === currentPage.value) return;
    fetchUnits(page);
};

const handlePerPageChange = (value) => {
    if (perPage.value === value) return;
    perPage.value = value;
    fetchUnits(1);
};


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

useBodyScrollLock(() => confirmState.open);

const openCreateForm = () => {
    if (!canCreateUnits.value) return;

    formState.mode = 'create';
    formState.form = defaultForm();
    formState.errors = {};
    formState.open = true;
};

const openEditForm = (unit) => {
    if (!canUpdateUnits.value) return;

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
        let response;
        if (formState.mode === 'create') {
            response = await axios.post(route('units.store'), payload);
        } else {
            response = await axios.put(route('units.update', payload.id), payload);
        }
        formState.open = false;
        showToast({
            type: 'success',
            title: 'Unidad guardada',
            message: response?.data?.message ?? 'La unidad se guardo correctamente.',
        });
        await fetchUnits();
    } catch (error) {
        if (error.response?.status === 422) {
            formState.errors = error.response.data.errors ?? {};
        } else {
            showToast({
                type: 'error',
                title: 'Error al guardar',
                message: error.response?.data?.message ?? 'No se pudo guardar la unidad.',
            });
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
    if (!canDisableUnits.value) return;

    confirmState.unit = unit;
    confirmState.open = true;
};

const toggleStatus = async () => {
    if (!confirmState.unit) return;

    confirmState.loading = true;
    try {
        const { data } = await axios.put(route('units.toggle-status', confirmState.unit.id));
        confirmState.open = false;
        confirmState.unit = null;
        showToast({
            type: 'success',
            title: 'Estado actualizado',
            message: data.message ?? 'La unidad cambio de estado.',
        });
        await fetchUnits();
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Error al actualizar',
            message: error.response?.data?.message ?? 'No se pudo cambiar el estado.',
        });
    } finally {
        confirmState.loading = false;
    }
};

const clearFilters = () => {
    filters.search = '';
    filters.company_id = '';
    filters.status = '';
};
</script>

<template>
    <Head title="Catalogo de unidades" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-xl font-semibold leading-tight">
                    Catalogo de unidades
                </h1>
                <p class="text-sm text-slate-500">
                    Administra las unidades operativas y sus asignaciones de relojes.
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
                        {{ pageSummary.total }}
                    </p>
                    <p class="text-sm text-slate-500">Unidades registradas</p>
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
                    <p class="text-sm text-slate-500">Fuera de operacion</p>
                </article>
                <div
                    v-if="pageSummary.total"
                    class="sm:col-span-3 flex flex-col gap-3 lg:flex-row lg:items-center"
                >
                    <PaginationBar
                        class="flex-1 card"
                        :meta="paginationMeta"
                        :per-page-options="perPageOptions"
                        :disabled="listLoading"
                        @update:page="handlePageChange"
                        @update:perPage="handlePerPageChange"
                    />
                    <div class="card flex w-full items-center justify-center px-4 py-3 lg:w-auto">
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-app px-4 py-2 text-sm font-semibold text-muted hover:text-app disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                            :disabled="!canToggleAll"
                            @click="toggleAll"
                        >
                            {{ collapseToggleLabel }}
                        </button>
                    </div>
                </div>
                </div>

            <div class="flex flex-wrap items-stretch justify-between gap-4 rounded-3xl border border-slate-100 bg-white/90 p-4 shadow-sm sm:items-center">
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <div class="flex w-full items-center justify-between gap-2 rounded-2xl border border-slate-200 px-3 py-1.5 sm:w-auto sm:justify-start">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Empresa</span>
                        <select
                            v-model="filters.company_id"
                            class="w-full bg-transparent text-sm font-medium text-slate-700 focus:outline-none sm:w-auto"
                        >
                            <option value="">Todas</option>
                            <option v-for="company in companies" :key="company.id" :value="company.id">
                                {{ company.name }}
                            </option>
                        </select>
                    </div>

                    <div class="flex w-full items-center justify-between gap-2 rounded-2xl border border-slate-200 px-3 py-1.5 sm:w-auto sm:justify-start">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Estado</span>
                        <select
                            v-model="filters.status"
                            class="w-full bg-transparent text-sm font-medium text-slate-700 focus:outline-none sm:w-auto"
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
                        class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm shadow-sm focus:border-indigo-400 focus:outline-none sm:w-auto sm:min-w-[16rem]"
                    />
                </div>

                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <button
                        type="button"
                        class="w-full rounded-2xl border border-slate-200 px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900 sm:w-auto"
                        @click="clearFilters"
                    >
                        Limpiar
                    </button>
                    <button
                        v-if="canCreateUnits"
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-600/30 hover:bg-indigo-500 sm:w-auto"
                        @click="openCreateForm"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                fill-rule="evenodd"
                                d="M10 4a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 011-1z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        Nueva unidad
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
                    :collapsed="isCollapsed(unit.id)"
                    :can-update="canUpdateUnits"
                    :can-disable="canDisableUnits"
                    @collapse-toggle="toggleUnitCollapse"
                    @view="viewDetail"
                    @edit="openEditForm"
                    @toggle="requestToggle"
                />

                <p v-if="!units.length && !listLoading" class="rounded-3xl border border-slate-100 bg-white/80 p-6 text-center text-sm text-slate-500">
                    No se encontraron unidades con los filtros seleccionados.
                </p>
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
            <div class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl sm:p-6">
                <h3 class="text-xl font-semibold text-slate-900">
                    {{ confirmState.unit?.status ? 'Desactivar unidad' : 'Activar unidad' }}
                </h3>
                <p class="mt-2 text-sm text-slate-500">
                    {{
                        confirmState.unit?.status
                            ? 'Deseas desactivar esta unidad? Los relojes seguiran vinculados pero no se podran asignar nuevas operaciones.'
                            : 'Deseas activar la unidad para permitir asignaciones y monitoreo?'
                    }}
                </p>
                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="w-full rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 sm:w-auto"
                        @click="confirmState.open = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60 sm:w-auto"
                        :disabled="confirmState.loading"
                        @click="toggleStatus"
                    >
                        <span v-if="confirmState.loading">Procesando...</span>
                        <span v-else>Confirmar</span>
                    </button>
                </div>
            </div>
        </div>

        <Toast
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            :duration="toast.duration"
            @close="toast.show = false"
        />
    </AuthenticatedLayout>
</template>
