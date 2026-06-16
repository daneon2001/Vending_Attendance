<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import Toast from '@/Components/Toast.vue';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import UnitDetailDrawer from './Partials/UnitDetailDrawer.vue';
import UnitFormModal from './Partials/UnitFormModal.vue';
import UnitCard from './Partials/UnitCard.vue';

const props = defineProps({
    initialUnits: {
        type: Object,
        default: () => ({
            data: [],
            meta: null,
        }),
    },
    summary: {
        type: Object,
        default: () => ({
            total_units: 0,
            active_units: 0,
            inactive_units: 0,
        }),
    },
    filteredMeta: {
        type: Object,
        default: () => ({
            filtered_total: 0,
            current_page_count: 0,
        }),
    },
    columns: {
        type: Object,
        default: () => ({
            available: [],
            default: [],
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
const canBulkDeactivateUnits = computed(() => canDisableUnits.value);

const units = ref(props.initialUnits?.data ?? []);
const pagination = ref(props.initialUnits?.meta ?? null);
const summary = ref(props.summary ?? {
    total_units: 0,
    active_units: 0,
    inactive_units: 0,
});
const filteredMeta = ref(props.filteredMeta ?? {
    filtered_total: pagination.value?.total ?? 0,
    current_page_count: (props.initialUnits?.data ?? []).length,
});
const perPage = ref(pagination.value?.per_page ?? 12);
const perPageOptions = [10, 12, 20, 50];
const listLoading = ref(false);
const listError = ref('');

const filters = reactive({
    search: '',
    company_id: '',
    status: '',
});
const companySelectOptions = computed(() =>
    (props.companies ?? []).map((company) => ({
        ...company,
        label: company.code ? `${company.name} (${company.code})` : company.name,
    })),
);

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

const pageSummary = computed(() => {
    const total = filteredMeta.value?.filtered_total ?? pagination.value?.total ?? units.value.length;
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

const buildListParams = (page = currentPage.value) => ({
    search: filters.search || undefined,
    company_id: filters.company_id || undefined,
    status: filters.status !== '' ? filters.status : undefined,
    page,
    per_page: perPage.value,
});

const fetchUnits = async (page = currentPage.value) => {
    listLoading.value = true;
    listError.value = '';

    try {
        const { data } = await axios.get(route('units.list'), {
            params: buildListParams(page),
        });
        units.value = data.data ?? [];
        pagination.value = data.meta ?? pagination.value;
        summary.value = data.summary ?? summary.value;
        filteredMeta.value = data.filtered_meta ?? {
            filtered_total: data.meta?.total ?? units.value.length,
            current_page_count: (data.data ?? []).length,
        };
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

const availableColumns = computed(() => props.columns?.available ?? []);
const defaultColumns = computed(() => props.columns?.default ?? []);
const columnStorageKey = 'units.catalog.visible-columns';
const visibleColumnKeys = ref([]);

const sanitizeColumnKeys = (keys = [], enforceDefaults = true) => {
    const allowed = new Map(availableColumns.value.map((column) => [column.key, column]));
    const ordered = [];

    for (const column of availableColumns.value) {
        if (keys.includes(column.key) || column.locked) {
            ordered.push(column.key);
        }
    }

    const sanitized = ordered.filter((key) => allowed.has(key));
    const nonLockedVisible = sanitized.filter((key) => !allowed.get(key)?.locked);

    if (!enforceDefaults || nonLockedVisible.length > 0) {
        return sanitized;
    }

    return availableColumns.value
        .filter((column) => defaultColumns.value.includes(column.key) || column.locked)
        .map((column) => column.key);
};

const syncVisibleColumns = () => {
    if (typeof window === 'undefined') {
        visibleColumnKeys.value = sanitizeColumnKeys(defaultColumns.value);
        return;
    }

    try {
        const stored = JSON.parse(window.localStorage.getItem(columnStorageKey) ?? '[]');
        visibleColumnKeys.value = sanitizeColumnKeys(Array.isArray(stored) ? stored : defaultColumns.value);
    } catch {
        visibleColumnKeys.value = sanitizeColumnKeys(defaultColumns.value);
    }
};

watch(availableColumns, syncVisibleColumns, { immediate: true });

watch(
    visibleColumnKeys,
    (value) => {
        if (typeof window === 'undefined') return;
        window.localStorage.setItem(columnStorageKey, JSON.stringify(sanitizeColumnKeys(value)));
    },
    { deep: true },
);

const toggleColumn = (key) => {
    const column = availableColumns.value.find((item) => item.key === key);

    if (!column || column.locked) {
        return;
    }

    if (visibleColumnKeys.value.includes(key)) {
        const nextKeys = sanitizeColumnKeys(
            visibleColumnKeys.value.filter((value) => value !== key),
            false,
        );
        const stillVisible = nextKeys.filter((value) => {
            const candidate = availableColumns.value.find((item) => item.key === value);

            return candidate && !candidate.locked;
        });

        if (stillVisible.length === 0) {
            return;
        }

        visibleColumnKeys.value = nextKeys;
        return;
    }

    visibleColumnKeys.value = sanitizeColumnKeys([...visibleColumnKeys.value, key]);
};

const orderedVisibleColumns = computed(() =>
    availableColumns.value.filter((column) => visibleColumnKeys.value.includes(column.key)),
);

const visibleExportColumns = computed(() =>
    orderedVisibleColumns.value
        .filter((column) => column.exportable !== false)
        .map((column) => column.key),
);

const exportCsvUrl = computed(() =>
    route('units.export', {
        ...buildListParams(1),
        columns: visibleExportColumns.value,
        format: 'csv',
    }),
);

const exportExcelUrl = computed(() =>
    route('units.export', {
        ...buildListParams(1),
        columns: visibleExportColumns.value,
        format: 'excel',
    }),
);

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

const bulkDeactivationState = reactive({
    open: false,
    loading: false,
    executing: false,
    preview: null,
    error: '',
    confirmationText: '',
    confirmChecked: false,
});

const canExecuteBulkDeactivation = computed(
    () => bulkDeactivationState.confirmationText === 'DESACTIVAR' || bulkDeactivationState.confirmChecked,
);

useBodyScrollLock(() => confirmState.open || bulkDeactivationState.open);

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
            message: response?.data?.message ?? 'La unidad se guardó correctamente.',
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
            message: data.message ?? 'La unidad cambió de estado.',
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

const openBulkDeactivationModal = async () => {
    if (!canBulkDeactivateUnits.value) return;

    bulkDeactivationState.open = true;
    bulkDeactivationState.loading = true;
    bulkDeactivationState.executing = false;
    bulkDeactivationState.preview = null;
    bulkDeactivationState.error = '';
    bulkDeactivationState.confirmationText = '';
    bulkDeactivationState.confirmChecked = false;

    try {
        const { data } = await axios.get(route('units.bulk-deactivation-preview'));
        bulkDeactivationState.preview = data.data;
    } catch (error) {
        bulkDeactivationState.error = error.response?.data?.message ?? 'No se pudo calcular la vista previa de desactivación.';
    } finally {
        bulkDeactivationState.loading = false;
    }
};

const closeBulkDeactivationModal = () => {
    bulkDeactivationState.open = false;
    bulkDeactivationState.loading = false;
    bulkDeactivationState.executing = false;
    bulkDeactivationState.preview = null;
    bulkDeactivationState.error = '';
    bulkDeactivationState.confirmationText = '';
    bulkDeactivationState.confirmChecked = false;
};

const runBulkDeactivation = async (dryRun = false) => {
    bulkDeactivationState.executing = true;

    try {
        const { data } = await axios.post(route('units.bulk-deactivate-inactive'), {
            dry_run: dryRun ? 1 : 0,
        });

        if (dryRun) {
            showToast({
                type: 'success',
                title: 'Prevalidación completada',
                message: data.message,
            });
        } else {
            showToast({
                type: 'success',
                title: 'Desactivación masiva completada',
                message: data.message,
            });
            await fetchUnits();
            closeBulkDeactivationModal();
        }
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Error en desactivación masiva',
            message: error.response?.data?.message ?? 'No se pudo completar la desactivación masiva.',
        });
    } finally {
        bulkDeactivationState.executing = false;
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
                    Administra las unidades operativas, su actividad y sus exportaciones.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <article class="card-kpi bg-gradient-to-br from-indigo-50 to-white dark:from-indigo-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-indigo-500">
                        Total
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ summary.total_units }}
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Unidades registradas</p>
                </article>
                <article class="card-kpi bg-gradient-to-br from-emerald-50 to-white dark:from-emerald-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-500">
                        Activas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ summary.active_units }}
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Operando actualmente</p>
                </article>
                <article class="card-kpi bg-gradient-to-br from-rose-50 to-white dark:from-rose-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-rose-500">
                        Inactivas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ summary.inactive_units }}
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Fuera de operación</p>
                </article>
                <article class="card p-4 sm:col-span-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400 dark:text-slate-400">
                        Resultado filtrado
                    </p>
                    <p class="mt-2 text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {{ filteredMeta.filtered_total ?? pageSummary.total }} unidades
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Mostrando {{ pageSummary.start }}-{{ pageSummary.end }} de {{ filteredMeta.filtered_total ?? pageSummary.total }} resultados filtrados.
                    </p>
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

            <div class="card flex flex-wrap items-stretch justify-between gap-4 p-4 sm:items-center">
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <div class="flex w-full min-w-0 flex-col gap-1 rounded-2xl border border-slate-200 px-3 py-2 sm:w-[19rem]">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Empresa</span>
                        <SearchableSelect
                            v-model="filters.company_id"
                            :options="companySelectOptions"
                            placeholder="Todas"
                            search-placeholder="Buscar empresa..."
                            :searchable="true"
                            input-class="w-full bg-transparent px-0 py-0 text-sm font-medium text-slate-700 dark:text-slate-200"
                        />
                    </div>

                    <div class="flex w-full items-center justify-between gap-2 rounded-2xl border border-slate-200 px-3 py-1.5 sm:w-auto sm:justify-start">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Estado</span>
                        <select
                            v-model="filters.status"
                            class="w-full bg-transparent text-sm font-medium text-slate-700 focus:outline-none dark:text-slate-200 sm:w-auto"
                        >
                            <option value="">Todos</option>
                            <option :value="1">Activas</option>
                            <option :value="0">Inactivas</option>
                        </select>
                    </div>

                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Buscar por nombre, código o ID Fortia"
                        class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm shadow-sm focus:border-indigo-400 focus:outline-none dark:bg-slate-900 dark:text-slate-100 sm:w-auto sm:min-w-[18rem]"
                    />
                </div>

                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="w-full rounded-2xl border border-slate-200 px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 sm:w-auto"
                            >
                                Columnas
                            </button>
                        </template>

                        <template #content>
                            <div class="max-h-80 overflow-y-auto px-2 py-2">
                                <label
                                    v-for="column in availableColumns"
                                    :key="column.key"
                                    class="flex items-start gap-2 rounded-2xl px-2 py-2 text-sm text-app hover:bg-slate-50 dark:hover:bg-slate-800"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        :checked="visibleColumnKeys.includes(column.key)"
                                        :disabled="column.locked"
                                        @change="toggleColumn(column.key)"
                                    />
                                    <span>
                                        {{ column.label }}
                                        <span v-if="column.locked" class="block text-[11px] text-soft">Siempre visible</span>
                                    </span>
                                </label>
                            </div>
                        </template>
                    </Dropdown>

                    <a
                        :href="exportCsvUrl"
                        class="w-full rounded-2xl border border-slate-200 px-3 py-2 text-center text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 sm:w-auto"
                    >
                        Exportar CSV
                    </a>
                    <a
                        :href="exportExcelUrl"
                        class="w-full rounded-2xl border border-slate-200 px-3 py-2 text-center text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 sm:w-auto"
                    >
                        Exportar Excel
                    </a>
                    <button
                        type="button"
                        class="w-full rounded-2xl border border-slate-200 px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 sm:w-auto"
                        @click="clearFilters"
                    >
                        Limpiar
                    </button>
                    <button
                        v-if="canBulkDeactivateUnits"
                        type="button"
                        class="w-full rounded-2xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 sm:w-auto"
                        @click="openBulkDeactivationModal"
                    >
                        Desactivar sin código ni actividad
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

            <div v-if="listLoading" class="rounded-3xl border border-slate-100 bg-white/80 p-6 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/80 dark:text-slate-400">
                Cargando catálogo...
            </div>

            <div v-if="!listLoading" class="space-y-4">
                <UnitCard
                    v-for="unit in units"
                    :key="unit.id"
                    :unit="unit"
                    :collapsed="isCollapsed(unit.id)"
                    :visible-columns="visibleColumnKeys"
                    :can-update="canUpdateUnits"
                    :can-disable="canDisableUnits"
                    @collapse-toggle="toggleUnitCollapse"
                    @view="viewDetail"
                    @edit="openEditForm"
                    @toggle="requestToggle"
                />

                <p v-if="!units.length && !listLoading" class="rounded-3xl border border-slate-100 bg-white/80 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/80 dark:text-slate-400">
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
            <div class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl dark:bg-slate-900 sm:p-6">
                <h3 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                    {{ confirmState.unit?.status ? 'Desactivar unidad' : 'Activar unidad' }}
                </h3>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{
                        confirmState.unit?.status
                            ? 'Deseas desactivar esta unidad? Los relojes seguirán vinculados pero no se podrán asignar nuevas operaciones.'
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

        <div
            v-if="bulkDeactivationState.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="card w-full max-w-4xl max-h-[90vh] overflow-hidden">
                <div class="border-b border-app px-5 py-4">
                    <h3 class="text-xl font-semibold text-app">Desactivación masiva segura</h3>
                    <p class="mt-1 text-sm text-soft">
                        Esta acción sólo desactivará unidades activas sin código válido, sin reloj activo, sin asistencia histórica y sin heartbeat asociado.
                    </p>
                </div>

                <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                    <div v-if="bulkDeactivationState.loading" class="text-sm text-soft">
                        Calculando candidatos...
                    </div>

                    <div v-else-if="bulkDeactivationState.error" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        {{ bulkDeactivationState.error }}
                    </div>

                    <template v-else-if="bulkDeactivationState.preview">
                        <div class="grid gap-3 md:grid-cols-3">
                            <article class="card-subtle p-4">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Activas totales</p>
                                <p class="mt-2 text-2xl font-semibold text-app">{{ bulkDeactivationState.preview.total_active_units }}</p>
                            </article>
                            <article class="card-subtle p-4">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Candidatas</p>
                                <p class="mt-2 text-2xl font-semibold text-app">{{ bulkDeactivationState.preview.total_candidates }}</p>
                            </article>
                            <article class="card-subtle p-4">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Vista previa</p>
                                <p class="mt-2 text-sm font-semibold text-app">
                                    Primeras {{ bulkDeactivationState.preview.preview?.length ?? 0 }} unidades
                                </p>
                            </article>
                        </div>

                        <article class="mt-4 rounded-3xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                            Esta acción desactivará unidades activas sin código y sin actividad relacionada. El Fortia ID no bloquea la acción si la unidad sigue sin código operativo. No se eliminarán registros.
                        </article>

                        <div class="mt-4">
                            <p class="text-sm font-semibold text-app">Criterios aplicados</p>
                            <ul class="mt-2 space-y-2 text-sm text-soft">
                                <li v-for="criterion in bulkDeactivationState.preview.criteria" :key="criterion">
                                    • {{ criterion }}
                                </li>
                            </ul>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[42rem] divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-[0.3em] text-soft dark:bg-slate-900/70">
                                    <tr>
                                        <th class="px-3 py-3">ID</th>
                                        <th class="px-3 py-3">Empresa</th>
                                        <th class="px-3 py-3">Unidad</th>
                                        <th class="px-3 py-3">Código</th>
                                        <th class="px-3 py-3">Fortia</th>
                                        <th class="px-3 py-3">Actualizado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr
                                        v-for="unit in bulkDeactivationState.preview.preview"
                                        :key="`candidate-${unit.id}`"
                                    >
                                        <td class="px-3 py-3 text-app">{{ unit.id }}</td>
                                        <td class="px-3 py-3 text-app">{{ unit.company_name ?? '—' }}</td>
                                        <td class="px-3 py-3 text-app">{{ unit.name ?? '—' }}</td>
                                        <td class="px-3 py-3 text-app">{{ unit.code ?? '—' }}</td>
                                        <td class="px-3 py-3 text-app">{{ unit.fortia_location_id ?? '—' }}</td>
                                        <td class="px-3 py-3 text-app">{{ unit.updated_at ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 space-y-3">
                            <label class="flex flex-col gap-1 text-sm text-app">
                                Escribe <span class="font-semibold">DESACTIVAR</span> para confirmar
                                <input
                                    v-model="bulkDeactivationState.confirmationText"
                                    type="text"
                                    class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                                />
                            </label>

                            <label class="flex items-center gap-2 text-sm text-app">
                                <input
                                    v-model="bulkDeactivationState.confirmChecked"
                                    type="checkbox"
                                    class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                Confirmo que revisé la vista previa y deseo continuar.
                            </label>
                        </div>
                    </template>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-app px-5 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="w-full rounded-2xl border border-app px-4 py-2 text-sm font-semibold text-muted sm:w-auto"
                        @click="closeBulkDeactivationModal"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="w-full rounded-2xl border border-app px-4 py-2 text-sm font-semibold text-muted disabled:opacity-60 sm:w-auto"
                        :disabled="bulkDeactivationState.executing || bulkDeactivationState.loading"
                        @click="runBulkDeactivation(true)"
                    >
                        <span v-if="bulkDeactivationState.executing">Procesando...</span>
                        <span v-else>Dry run</span>
                    </button>
                    <button
                        type="button"
                        class="w-full rounded-2xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60 sm:w-auto"
                        :disabled="!canExecuteBulkDeactivation || bulkDeactivationState.executing || bulkDeactivationState.loading"
                        @click="runBulkDeactivation(false)"
                    >
                        <span v-if="bulkDeactivationState.executing">Desactivando...</span>
                        <span v-else>Desactivar candidatas</span>
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
