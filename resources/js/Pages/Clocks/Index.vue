<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    initialClocks: {
        type: Object,
        default: () => ({
            data: [],
            meta: null,
        }),
    },
    locations: {
        type: Array,
        default: () => [],
    },
    companies: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    perPage: {
        type: Number,
        default: 12,
    },
});

const clockList = ref(props.initialClocks?.data ?? []);
const pagination = ref(props.initialClocks?.meta ?? null);
const perPage = ref(pagination.value?.per_page ?? props.perPage ?? 12);
const listLoading = ref(false);
const perPageOptions = [10, 12, 20, 50];
const filterSyncing = ref(false);
let searchDebounceTimer = null;

const createFilters = (value = {}, fallbackPerPage = 12) => ({
    q: value?.q ?? '',
    company_id: value?.company_id ? String(value.company_id) : '',
    location_id: value?.location_id ? String(value.location_id) : '',
    status: value?.status !== null && value?.status !== undefined && value?.status !== ''
        ? String(value.status)
        : '',
    monitoring_status: value?.monitoring_status ?? '',
    program_status: value?.program_status ?? '',
    per_page: Number(value?.per_page ?? fallbackPerPage ?? 12),
});

const filterForm = reactive(createFilters(props.filters ?? {}, perPage.value));

const syncFiltersFromProps = (filters, perPageValue) => {
    filterSyncing.value = true;
    Object.assign(filterForm, createFilters(filters ?? {}, perPageValue ?? 12));
    perPage.value = Number(filterForm.per_page || perPageValue || 12);

    setTimeout(() => {
        filterSyncing.value = false;
    }, 0);
};

watch(
    () => props.initialClocks,
    (value) => {
        clockList.value = value?.data ?? [];
        pagination.value = value?.meta ?? null;
        if (value?.meta?.per_page) {
            perPage.value = value.meta.per_page;
        }
    },
    { deep: true },
);

watch(
    () => props.filters,
    (value) => {
        syncFiltersFromProps(value ?? {}, props.perPage ?? perPage.value);
    },
    { deep: true },
);

const locationOptions = computed(() => props.locations ?? []);
const companyOptions = computed(() => props.companies ?? []);

const monitoringStyles = {
    online: {
        label: 'En linea',
        badge: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
        dot: 'bg-emerald-500',
    },
    warning: {
        label: 'Con alertas',
        badge: 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
        dot: 'bg-amber-400',
    },
    offline: {
        label: 'Sin conexion',
        badge: 'bg-rose-50 text-rose-700 ring-1 ring-rose-100',
        dot: 'bg-rose-400',
    },
};

const monitoringOptions = [
    { value: 'online', label: 'En linea' },
    { value: 'warning', label: 'Con alertas' },
    { value: 'offline', label: 'Sin conexion' },
];

const programOptions = [
    { value: 'online', label: 'Encendido' },
    { value: 'standby', label: 'Standby' },
    { value: 'offline', label: 'Apagado' },
];

const statusOptions = [
    { value: 1, label: 'Activo' },
    { value: 0, label: 'Inactivo' },
];

const programStatusLabels = {
    online: {
        label: 'Encendido',
        detail: 'Reportando en tiempo real',
    },
    standby: {
        label: 'Standby',
        detail: 'Listo para reconectar',
    },
    offline: {
        label: 'Apagado',
        detail: 'Esperando reconexion',
    },
};

const defaultClockForm = (clock = null) => ({
    id: clock?.id ?? null,
    company_id: clock?.company?.id ?? companyOptions.value[0]?.id ?? null,
    location_id: clock?.location?.id ?? null,
    clock_name: clock?.clock_name ?? '',
    serial_number: clock?.serial_number ?? '',
    firmware_version: clock?.firmware_version ?? '',
    ip_address: clock?.ip_address ?? '',
    type_inout: clock?.type_inout ?? 'INOUT',
    status: clock?.status ?? 1,
    monitoring_status: clock?.monitoring_status ?? 'online',
    program_status: clock?.program_status ?? (clock?.is_online ? 'online' : 'offline'),
    last_status_message: clock?.monitoring_message ?? '',
});

const formModal = reactive({
    open: false,
    mode: 'create',
    loading: false,
    errors: {},
    form: defaultClockForm(),
});

const importModal = reactive({
    open: false,
    file: null,
    loading: false,
    summary: null,
    errors: [],
});

const assignModal = reactive({
    open: false,
    clock: null,
    location_id: null,
    loading: false,
    errors: {},
});

const createEmptyLogFilters = () => ({
    level: '',
    event_type: '',
    date_from: '',
    date_to: '',
});

const logsDrawer = reactive({
    open: false,
    clock: null,
    entries: [],
    meta: null,
    loading: false,
    error: null,
    filters: createEmptyLogFilters(),
});

const anyOverlayOpen = computed(
    () => importModal.open || formModal.open || assignModal.open || logsDrawer.open,
);
useBodyScrollLock(() => anyOverlayOpen.value);

const formatDateInput = (date) => date.toISOString().split('T')[0];

const setDefaultLogRange = () => {
    const today = formatDateInput(new Date());
    logsDrawer.filters.date_from = today;
    logsDrawer.filters.date_to = today;
};

const normalizeDateTimeBoundary = (value, isStart) => {
    if (!value) {
        return undefined;
    }

    if (value.includes(':')) {
        return value;
    }

    return `${value} ${isStart ? '00:00:00' : '23:59:59'}`;
};

const clocks = computed(() => clockList.value);
const totalLocations = computed(() => locationOptions.value.length);
const totalClocks = computed(() => pagination.value?.total ?? clockList.value.length);
const currentPage = computed(() => pagination.value?.current_page ?? 1);
const totalPages = computed(() => pagination.value?.last_page ?? 1);
const totalOnline = computed(
    () => clockList.value.filter((clock) => clock.monitoring_status === 'online').length,
);
const totalWarning = computed(
    () => clockList.value.filter((clock) => clock.monitoring_status === 'warning').length,
);
const totalOffline = computed(
    () =>
        clockList.value.filter(
            (clock) => clock.monitoring_status === 'offline' || !clock.is_online,
        ).length,
);
const pageSummary = computed(() => {
    const total = totalClocks.value;
    if (!total) {
        return { start: 0, end: 0, total: 0 };
    }

    if (!pagination.value) {
        const end = clockList.value.length;
        return {
            start: end ? 1 : 0,
            end,
            total,
        };
    }

    const length = clockList.value.length;
    const start =
        (pagination.value.current_page - 1) * pagination.value.per_page + (length ? 1 : 0);
    const end = length ? Math.min(start + length - 1, total) : 0;

    return { start, end, total };
});

const formatRelative = (timestamp) => {
    if (!timestamp) return 'Sin beat registrado';
    const diffMs = Date.now() - Date.parse(timestamp);
    const diffMinutes = Math.round(diffMs / 60000);
    if (diffMinutes <= 1) return 'Hace instantes';
    if (diffMinutes < 60) return `Hace ${diffMinutes} min`;
    const diffHours = Math.round(diffMinutes / 60);
    if (diffHours < 24) return `Hace ${diffHours} h`;
    const diffDays = Math.round(diffHours / 24);
    return `Hace ${diffDays} dias`;
};

const formatDateTime = (timestamp) => {
    if (!timestamp) return 'Sin registro';
    return new Date(timestamp).toLocaleString();
};

const collapseStorageKey = 'clock-card-collapsed';
const collapsedMap = ref({});

const loadCollapsedState = () => {
    if (typeof window === 'undefined') {
        collapsedMap.value = {};
        return;
    }

    try {
        const raw = window.localStorage.getItem(collapseStorageKey);
        collapsedMap.value = raw ? JSON.parse(raw) : {};
    } catch (error) {
        collapsedMap.value = {};
    }
};

const persistCollapsedState = () => {
    if (typeof window === 'undefined') {
        return;
    }
    window.localStorage.setItem(collapseStorageKey, JSON.stringify(collapsedMap.value));
};

loadCollapsedState();

const defaultCollapsed = computed(() => clockList.value.length > 5);

const isCollapsed = (clockId) => {
    const key = String(clockId);
    const state = collapsedMap.value?.[key];
    if (typeof state === 'boolean') {
        return state;
    }
    return defaultCollapsed.value;
};

const toggleClockCollapse = (clockId) => {
    const key = String(clockId);
    collapsedMap.value = {
        ...collapsedMap.value,
        [key]: !isCollapsed(clockId),
    };
    persistCollapsedState();
};

const setCollapseStateForAll = (collapsed) => {
    const next = { ...collapsedMap.value };
    clocks.value.forEach((clock) => {
        next[String(clock.id)] = collapsed;
    });
    collapsedMap.value = next;
    persistCollapsedState();
};

const collapseAll = () => setCollapseStateForAll(true);
const expandAll = () => setCollapseStateForAll(false);
const allCollapsed = computed(
    () => clocks.value.length > 0 && clocks.value.every((clock) => isCollapsed(clock.id)),
);
const collapseToggleLabel = computed(() =>
    allCollapsed.value ? 'Desplegar todas' : 'Colapsar todas',
);
const canToggleAll = computed(() => clocks.value.length > 0);
const toggleAll = () => {
    if (!clocks.value.length) {
        return;
    }

    if (allCollapsed.value) {
        expandAll();
    } else {
        collapseAll();
    }
};

watch(
    clockList,
    (list) => {
        const ids = new Set(list.map((clock) => String(clock.id)));
        const updated = { ...collapsedMap.value };
        let changed = false;

        Object.keys(updated).forEach((key) => {
            if (!ids.has(key)) {
                delete updated[key];
                changed = true;
            }
        });

        if (changed) {
            collapsedMap.value = updated;
            persistCollapsedState();
        }
    },
    { deep: true },
);

const fetchClocks = async (page = currentPage.value) => {
    if (listLoading.value) {
        return;
    }

    listLoading.value = true;
    try {
        const { data } = await axios.get(route('clocks.list'), {
            params: {
                q: filterForm.q || undefined,
                company_id: filterForm.company_id || undefined,
                location_id: filterForm.location_id || undefined,
                status: filterForm.status || undefined,
                monitoring_status: filterForm.monitoring_status || undefined,
                program_status: filterForm.program_status || undefined,
                page,
                per_page: perPage.value,
            },
        });

        clockList.value = data.data ?? [];
        pagination.value = data.meta ?? null;
    } catch (error) {
        // eslint-disable-next-line no-console
        console.error('No se pudo actualizar el catálogo de relojes', error);
    } finally {
        listLoading.value = false;
    }
};

const buildFilterQuery = (page = 1) => {
    const query = {
        q: filterForm.q || undefined,
        company_id: filterForm.company_id || undefined,
        location_id: filterForm.location_id || undefined,
        status: filterForm.status || undefined,
        monitoring_status: filterForm.monitoring_status || undefined,
        program_status: filterForm.program_status || undefined,
        page,
        per_page: perPage.value || undefined,
    };

    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    );
};

const applyFilters = (page = 1) => {
    if (filterSyncing.value) {
        return;
    }

    filterForm.per_page = Number(perPage.value);

    router.get(route('clocks.index'), buildFilterQuery(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['initialClocks', 'filters', 'perPage'],
        onStart: () => {
            listLoading.value = true;
        },
        onFinish: () => {
            listLoading.value = false;
        },
    });
};

const applySelectFilters = () => {
    applyFilters(1);
};

let refreshTimer = null;

onMounted(() => {
    syncFiltersFromProps(props.filters ?? {}, props.perPage ?? perPage.value);

    refreshTimer = window.setInterval(() => {
        fetchClocks(currentPage.value);
    }, 15000);
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        window.clearInterval(refreshTimer);
    }

    if (searchDebounceTimer) {
        window.clearTimeout(searchDebounceTimer);
    }
});

const changePage = (page) => {
    if (!pagination.value) {
        return;
    }

    if (page < 1 || page > (pagination.value?.last_page ?? 1) || page === pagination.value?.current_page) {
        return;
    }

    applyFilters(page);
};

const goToPrevPage = () => {
    changePage(currentPage.value - 1);
};

const goToNextPage = () => {
    changePage(currentPage.value + 1);
};

const handlePerPageChange = () => {
    filterForm.per_page = Number(perPage.value);
    applyFilters(1);
};

const clearFilters = () => {
    filterSyncing.value = true;
    Object.assign(filterForm, {
        q: '',
        company_id: '',
        location_id: '',
        status: '',
        monitoring_status: '',
        program_status: '',
        per_page: Number(perPage.value),
    });

    if (searchDebounceTimer) {
        window.clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }

    filterSyncing.value = false;
    applyFilters(1);
};

watch(
    () => filterForm.q,
    () => {
        if (filterSyncing.value) {
            return;
        }

        if (searchDebounceTimer) {
            window.clearTimeout(searchDebounceTimer);
        }

        searchDebounceTimer = window.setTimeout(() => {
            applyFilters(1);
        }, 350);
    },
);

const triggerAction = (action, clock) => {
    switch (action) {
        case 'import':
            openImportModal();
            break;
        case 'create':
            openCreateModal();
            break;
        case 'assign':
            openAssignModal(clock);
            break;
        case 'edit':
            openEditModal(clock);
            break;
        case 'view':
            openLogsDrawer(clock);
            break;
        default:
            break;
    }
};

const upsertClocks = (items) => {
    let updated = [...clockList.value];
    items.forEach((clock) => {
        const index = updated.findIndex((existing) => existing.id === clock.id);
        if (index !== -1) {
            updated[index] = clock;
        } else {
            updated = [clock, ...updated];
        }
    });
    clockList.value = updated;
};

const openCreateModal = () => {
    formModal.mode = 'create';
    formModal.form = defaultClockForm();
    formModal.errors = {};
    formModal.open = true;
};

const openEditModal = (clock) => {
    formModal.mode = 'edit';
    formModal.form = defaultClockForm(clock);
    formModal.errors = {};
    formModal.open = true;
};

const submitClockForm = async () => {
    formModal.loading = true;
    formModal.errors = {};

    try {
        const payload = { ...formModal.form };
        let response;

        if (formModal.mode === 'create') {
            response = await axios.post(route('clocks.store'), payload);
        } else {
            response = await axios.put(route('clocks.update', formModal.form.id), payload);
        }

        upsertClocks([response.data.data]);
        formModal.open = false;
    } catch (error) {
        if (error.response?.status === 422) {
            formModal.errors = error.response.data.errors ?? {};
        }
    } finally {
        formModal.loading = false;
    }
};

const openAssignModal = (clock) => {
    assignModal.clock = clock;
    assignModal.location_id = clock.location?.id ?? null;
    assignModal.errors = {};
    assignModal.open = true;
};

const submitAssignment = async () => {
    if (!assignModal.clock) return;

    assignModal.loading = true;
    assignModal.errors = {};

    try {
        const { data } = await axios.put(route('clocks.assign-unit', assignModal.clock.id), {
            location_id: assignModal.location_id,
        });

        upsertClocks([data.data]);

        const refreshed = clockList.value.find((clock) => clock.id === assignModal.clock.id);
        assignModal.clock = refreshed ?? assignModal.clock;
        assignModal.open = false;
    } catch (error) {
        if (error.response?.status === 422) {
            assignModal.errors = error.response.data.errors ?? {};
        }
    } finally {
        assignModal.loading = false;
    }
};

const openImportModal = () => {
    importModal.open = true;
    importModal.summary = null;
    importModal.errors = [];
    importModal.file = null;
};

const handleFileInput = (event) => {
    importModal.file = event.target.files?.[0] ?? null;
    importModal.summary = null;
    importModal.errors = [];
};

const submitImport = async () => {
    if (!importModal.file) {
        importModal.errors = ['Selecciona un archivo CSV antes de importar.'];
        return;
    }

    importModal.loading = true;
    importModal.errors = [];
    importModal.summary = null;

    const formData = new FormData();
    formData.append('file', importModal.file);

    try {
        const { data } = await axios.post(route('clocks.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        importModal.summary = data.summary;
        if (data.summary?.errors?.length) {
            importModal.errors = data.summary.errors;
        }
        if (data.data?.length) {
            upsertClocks(data.data);
        }
    } catch (error) {
        if (error.response?.data?.message) {
            importModal.errors = [error.response.data.message];
        }
    } finally {
        importModal.loading = false;
    }
};

const openLogsDrawer = (clock) => {
    logsDrawer.clock = clock;
    logsDrawer.entries = [];
    logsDrawer.meta = null;
    logsDrawer.error = null;
    Object.assign(logsDrawer.filters, createEmptyLogFilters());
    setDefaultLogRange();
    logsDrawer.open = true;
    fetchLogs();
};

const fetchLogs = async (url = null, append = false) => {
    if (!logsDrawer.clock) return;

    logsDrawer.loading = true;
    logsDrawer.error = null;

    try {
        const endpoint = url ?? route('clocks.logs', logsDrawer.clock.id);
        const config = {};

        if (!url) {
            config.params = {
                level: logsDrawer.filters.level || undefined,
                event_type: logsDrawer.filters.event_type || undefined,
                date_from: normalizeDateTimeBoundary(logsDrawer.filters.date_from, true),
                date_to: normalizeDateTimeBoundary(logsDrawer.filters.date_to, false),
            };
        }

        const { data } = await axios.get(endpoint, config);

        logsDrawer.entries = append
            ? [...logsDrawer.entries, ...(data.data ?? [])]
            : data.data ?? [];
        logsDrawer.meta = data.meta ?? null;
    } catch (error) {
        logsDrawer.error = error.response?.data?.message ?? 'No se pudo cargar la bitacora.';
    } finally {
        logsDrawer.loading = false;
    }
};

const resetLogsFilters = () => {
    Object.assign(logsDrawer.filters, createEmptyLogFilters());
    fetchLogs();
};
</script>

<template>
    <Head title="Relojes biométricos" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-xl font-semibold leading-tight">
                    Relojes biométricos
                </h1>
                <p class="text-sm text-slate-500">
                    Controla estado, asignaciones y monitoreo de cada checador.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-emerald-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-500">
                        En línea
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalOnline }}
                    </p>
                    <p class="text-sm text-slate-500">Operando y sincronizando</p>
                </article>
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-amber-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-amber-500">
                        Alertas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalWarning }}
                    </p>
                    <p class="text-sm text-slate-500">Beats tardíos o firmas pendientes</p>
                </article>
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-rose-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-rose-500">
                        Sin conexión
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalOffline }}
                    </p>
                    <p class="text-sm text-slate-500">Programas on-prem fuera de línea</p>
                </article>

                <div
                    v-if="totalClocks > 0 || clocks.length"
                    class="sm:col-span-3"
                >
                    <div
                        class="flex flex-col gap-4 rounded-3xl border border-slate-100 bg-white/80 p-4 shadow-sm ring-1 ring-transparent dark:border-slate-800 dark:bg-slate-900/60"
                    >
                        <div class="flex flex-wrap items-stretch justify-between gap-3 sm:items-center lg:flex-nowrap">
                            <div class="min-w-0 w-full sm:w-auto">
                                <p class="text-sm font-semibold text-app dark:text-slate-100">
                                    <span v-if="pageSummary.total">
                                        Mostrando {{ pageSummary.start }}–{{ pageSummary.end }} de {{ pageSummary.total }}
                                    </span>
                                    <span v-else>Sin checadores registrados</span>
                                </p>
                            </div>
                            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
                                <label class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                    Registros por página
                                    <select
                                        v-model.number="perPage"
                                        @change="handlePerPageChange"
                                        class="w-full rounded-2xl border border-slate-200 px-3 py-1 text-sm dark:border-slate-700 dark:bg-slate-900 sm:w-auto"
                                    >
                                        <option v-for="option in perPageOptions" :key="option" :value="option">
                                            {{ option }}
                                        </option>
                                    </select>
                                </label>
                                <button
                                    type="button"
                                    class="inline-flex w-full items-center justify-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 sm:w-auto"
                                    :disabled="!canToggleAll"
                                    @click="toggleAll"
                                >
                                    {{ collapseToggleLabel }}
                                </button>
                            </div>
                            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
                                <button
                                    type="button"
                                    class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 sm:w-auto"
                                    :aria-label="'Página anterior'"
                                    :disabled="listLoading || currentPage <= 1"
                                    @click="goToPrevPage"
                                >
                                    Anterior
                                </button>
                                <button
                                    type="button"
                                    class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 sm:w-auto"
                                    :aria-label="'Página siguiente'"
                                    :disabled="listLoading || currentPage >= totalPages"
                                    @click="goToNextPage"
                                >
                                    Siguiente
                                </button>
                                <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                    Página {{ pageSummary.total ? `${currentPage} de ${totalPages}` : '—' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-stretch justify-between gap-3 sm:items-center">
                <div>
                    <h2 class="text-app text-xl font-semibold leading-tight">
                        Catálogo
                        <span class="text-sm font-medium text-slate-400">({{ totalClocks }} checadores)</span>
                    </h2>
                    <p class="text-sm text-slate-500">
                        Asigna cada equipo a unidades y mantén visibilidad.
                    </p>
                    <p class="text-xs uppercase tracking-[0.3em] text-slate-400">
                        {{ totalLocations }} unidades monitoreadas
                    </p>
                </div>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-1 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm hover:text-slate-900 sm:w-auto"
                        @click="triggerAction('import', { id: null })"
                    >
                        <span>Importar</span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-600/30 hover:bg-indigo-500 sm:w-auto"
                        @click="triggerAction('create', { id: null })"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                fill-rule="evenodd"
                                d="M10 4a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 011-1z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        <span>Nuevo reloj</span>
                    </button>
                </div>
            </div>

            <section class="rounded-3xl border border-slate-100 bg-white/90 p-4 shadow-sm">
                <form class="grid gap-3 md:grid-cols-2 lg:grid-cols-3" @submit.prevent="applyFilters(1)">
                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400 lg:col-span-3">
                        Buscar
                        <input
                            v-model="filterForm.q"
                            type="search"
                            placeholder="Nombre, serial, IP, empresa o unidad"
                            class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-indigo-400 focus:outline-none"
                        />
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        Empresa
                        <select
                            v-model="filterForm.company_id"
                            class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
                            @change="applySelectFilters"
                        >
                            <option value="">Todas</option>
                            <option v-for="company in companyOptions" :key="company.id" :value="String(company.id)">
                                {{ company.name }}
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        Unidad
                        <select
                            v-model="filterForm.location_id"
                            class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
                            @change="applySelectFilters"
                        >
                            <option value="">Todas</option>
                            <option value="unassigned">Sin unidad asignada</option>
                            <option v-for="location in locationOptions" :key="location.id" :value="String(location.id)">
                                {{ location.name }}{{ location.code ? ` (${location.code})` : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        Estatus
                        <select
                            v-model="filterForm.status"
                            class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
                            @change="applySelectFilters"
                        >
                            <option value="">Todos</option>
                            <option value="1">Activos</option>
                            <option value="0">Inactivos</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        Monitoreo
                        <select
                            v-model="filterForm.monitoring_status"
                            class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
                            @change="applySelectFilters"
                        >
                            <option value="">Todos</option>
                            <option v-for="option in monitoringOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        Programa
                        <select
                            v-model="filterForm.program_status"
                            class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
                            @change="applySelectFilters"
                        >
                            <option value="">Todos</option>
                            <option v-for="option in programOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>

                    <div class="flex flex-col gap-2 md:col-span-2 lg:col-span-3 sm:flex-row sm:justify-end">
                        <button
                            type="submit"
                            class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white sm:w-auto"
                        >
                            Aplicar filtros
                        </button>
                        <button
                            type="button"
                            class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900 sm:w-auto"
                            @click="clearFilters"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>
            </section>

            <div class="space-y-4">
                <p
                    v-if="listLoading"
                    class="rounded-2xl border border-indigo-100 bg-indigo-50/70 px-4 py-2 text-sm font-semibold text-indigo-700 dark:border-indigo-900/40 dark:bg-indigo-900/30 dark:text-indigo-200"
                >
                    Actualizando catálogo de relojes...
                </p>
                <article
                    v-for="clock in clocks"
                    :key="clock.id"
                    class="rounded-3xl border border-slate-100 bg-white/90 p-5 shadow-sm ring-1 ring-transparent transition hover:border-indigo-100 hover:ring-indigo-50"
                >
                    <header class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                {{ clock.company?.name ?? 'Compañía' }}
                            </p>
                            <h3 class="text-xl font-semibold text-slate-900">
                                {{ clock.clock_name }}
                            </h3>
                            <p
                                v-if="!isCollapsed(clock.id)"
                                class="text-sm text-slate-500"
                            >
                                Serie {{ clock.serial_number ?? 'sin registrar' }} • Firmware {{ clock.firmware_version ?? 'pendiente' }}
                            </p>
                        </div>
                        <p
                            v-if="isCollapsed(clock.id)"
                            class="text-xs font-semibold text-slate-500 sm:text-right"
                        >
                            Último latido: {{ formatRelative(clock.last_heartbeat_at) }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                                :class="monitoringStyles[clock.monitoring_status || 'offline']?.badge"
                            >
                                <span
                                    class="h-2 w-2 rounded-full"
                                    :class="monitoringStyles[clock.monitoring_status || 'offline']?.dot"
                                />
                                {{ monitoringStyles[clock.monitoring_status || 'offline']?.label }}
                            </span>
                            <span
                                class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium uppercase tracking-[0.3em]"
                                :class="clock.status ? 'text-emerald-600 border-emerald-100 bg-emerald-50' : 'text-rose-600 border-rose-100 bg-rose-50'"
                            >
                                {{ clock.status ? 'Activo' : 'Inactivo' }}
                            </span>
                            <button
                                type="button"
                                class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300"
                                :aria-label="isCollapsed(clock.id) ? 'Desplegar checador' : 'Colapsar checador'"
                                :aria-expanded="!isCollapsed(clock.id)"
                                @click="toggleClockCollapse(clock.id)"
                            >
                                <svg
                                    class="h-4 w-4 transition-transform duration-150"
                                    :class="{ 'rotate-180': !isCollapsed(clock.id) }"
                                    viewBox="0 0 20 20"
                                    fill="none"
                                    stroke="currentColor"
                                >
                                    <path d="M6 8l4 4 4-4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </header>

                    <div v-if="!isCollapsed(clock.id)">
                        <div class="mt-4 grid gap-4 md:grid-cols-4">
                            <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm dark:border-slate-800 dark:bg-slate-900/60">
                                <dt class="text-xs uppercase tracking-wide text-slate-400">
                                    IP local
                                </dt>
                                <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                                    {{ clock.ip_address ?? 'No asignada' }}
                                </dd>
                                <dd class="text-xs text-slate-500">{{ clock.type_inout ?? 'Modo no definido' }}</dd>
                            </dl>

                            <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm dark:border-slate-800 dark:bg-slate-900/60">
                                <dt class="text-xs uppercase tracking-wide text-slate-400">
                                    Último latido
                                </dt>
                                <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                                    {{ formatRelative(clock.last_heartbeat_at) }}
                                </dd>
                                <dd class="text-xs text-slate-500">
                                    {{ clock.monitoring_message ?? 'Sin bitácora' }}
                                </dd>
                            </dl>

                            <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm dark:border-slate-800 dark:bg-slate-900/60">
                                <dt class="text-xs uppercase tracking-wide text-slate-400">
                                    Unidad asignada
                                </dt>
                                <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                                    {{ clock.location?.name ?? 'Sin unidad asignada' }}
                                </dd>
                                <dd class="text-xs text-slate-500">
                                    Código {{ clock.location?.code ?? 'N/A' }}
                                </dd>
                            </dl>

                            <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm dark:border-slate-800 dark:bg-slate-900/60">
                                <dt class="text-xs uppercase tracking-wide text-slate-400">
                                    Programa on-prem
                                </dt>
                                <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                                    {{
                                        programStatusLabels[clock.program_status || 'offline']?.label
                                    }}
                                </dd>
                                <dd class="text-xs text-slate-500">
                                    {{
                                        programStatusLabels[clock.program_status || 'offline']?.detail
                                    }}
                                </dd>
                            </dl>
                        </div>

                        <div class="mt-5 flex flex-col gap-2 text-sm font-medium text-slate-600 sm:flex-row sm:flex-wrap">
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 py-2 hover:border-slate-300 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 sm:w-auto"
                                @click="triggerAction('view', clock)"
                            >
                                Consultar bitácora
                            </button>
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 py-2 hover:border-slate-300 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 sm:w-auto"
                                @click="triggerAction('assign', clock)"
                            >
                                Asignar a unidad
                            </button>
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 py-2 hover:border-slate-300 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 sm:w-auto"
                                @click="triggerAction('edit', clock)"
                            >
                                Editar configuración
                            </button>
                        </div>
                    </div>
                </article>

                <p
                    v-if="!listLoading && !clocks.length"
                    class="rounded-2xl border border-slate-100 bg-white/80 px-4 py-6 text-center text-sm text-slate-500"
                >
                    No se encontraron relojes con los filtros seleccionados.
                </p>
            </div>
        </section>

        <!-- Import modal -->
        <div
            v-if="importModal.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            Importar
                        </p>
                        <h3 class="text-xl font-semibold text-slate-900">Relojes biométricos</h3>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900"
                        aria-label="Cerrar importacion de relojes"
                        @click="importModal.open = false"
                    >
                        <span class="sr-only">Cerrar</span>
                        ✕
                    </button>
                </div>

                <p class="mt-2 text-sm text-slate-500">
                    Selecciona un archivo CSV con los encabezados <strong>clock_name, serial_number, ip_address, unit_code</strong>.
                </p>

                <div class="mt-4">
                    <input
                        type="file"
                        accept=".csv"
                        class="w-full rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-600"
                        @change="handleFileInput"
                    />
                </div>

                <div
                    v-if="importModal.summary"
                    class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600"
                >
                    <p class="font-semibold text-slate-900">Resumen</p>
                    <p class="mt-1">Creados: {{ importModal.summary.created }}</p>
                    <p>Actualizados: {{ importModal.summary.updated }}</p>
                </div>

                <ul v-if="importModal.errors.length" class="mt-4 space-y-1 text-sm text-rose-600">
                    <li v-for="(error, index) in importModal.errors" :key="index">
                        • {{ error }}
                    </li>
                </ul>

                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="w-full rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 sm:w-auto"
                        @click="importModal.open = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60 sm:w-auto"
                        :disabled="importModal.loading || !importModal.file"
                        @click="submitImport"
                    >
                        <span v-if="importModal.loading">Importando...</span>
                        <span v-else>Importar</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Create / Edit modal -->
        <div
            v-if="formModal.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            {{ formModal.mode === 'create' ? 'Registrar' : 'Editar' }}
                        </p>
                        <h3 class="text-2xl font-semibold text-slate-900">
                            {{ formModal.mode === 'create' ? 'Nuevo reloj' : 'Configuración del reloj' }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900"
                        aria-label="Cerrar formulario de reloj"
                        @click="formModal.open = false"
                    >
                        <span class="sr-only">Cerrar</span>
                        ✕
                    </button>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="submitClockForm">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium text-slate-600">
                            Nombre del reloj
                            <input
                                v-model="formModal.form.clock_name"
                                type="text"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            />
                            <span v-if="formModal.errors.clock_name" class="text-xs text-rose-600">
                                {{ formModal.errors.clock_name[0] }}
                            </span>
                        </label>
                        <label class="text-sm font-medium text-slate-600">
                            Serie
                            <input
                                v-model="formModal.form.serial_number"
                                type="text"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            />
                            <span v-if="formModal.errors.serial_number" class="text-xs text-rose-600">
                                {{ formModal.errors.serial_number[0] }}
                            </span>
                        </label>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="text-sm font-medium text-slate-600">
                            Firmware
                            <input
                                v-model="formModal.form.firmware_version"
                                type="text"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            />
                        </label>
                        <label class="text-sm font-medium text-slate-600">
                            IP local
                            <input
                                v-model="formModal.form.ip_address"
                                type="text"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            />
                            <span v-if="formModal.errors.ip_address" class="text-xs text-rose-600">
                                {{ formModal.errors.ip_address[0] }}
                            </span>
                        </label>
                        <label class="text-sm font-medium text-slate-600">
                            Tipo (IN/OUT)
                            <input
                                v-model="formModal.form.type_inout"
                                type="text"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            />
                        </label>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="text-sm font-medium text-slate-600">
                            Compañía
                            <select
                                v-model.number="formModal.form.company_id"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            >
                                <option :value="null">Sin asignar</option>
                                <option v-for="company in companyOptions" :key="company.id" :value="company.id">
                                    {{ company.name }}
                                </option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-slate-600">
                            Unidad
                            <select
                                v-model.number="formModal.form.location_id"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            >
                                <option :value="null">Sin asignar</option>
                                <option v-for="location in locationOptions" :key="location.id" :value="location.id">
                                    {{ location.name }} ({{ location.code ?? 'N/A' }})
                                </option>
                            </select>
                            <span v-if="formModal.errors.location_id" class="text-xs text-rose-600">
                                {{ formModal.errors.location_id[0] }}
                            </span>
                        </label>
                        <label class="text-sm font-medium text-slate-600">
                            Estado
                            <select
                                v-model.number="formModal.form.status"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            >
                                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="text-sm font-medium text-slate-600">
                            Estado monitoreo
                            <select
                                v-model="formModal.form.monitoring_status"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            >
                                <option v-for="option in monitoringOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-slate-600">
                            Programa on-prem
                            <select
                                v-model="formModal.form.program_status"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            >
                                <option v-for="option in programOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-slate-600">
                            Mensaje de estado
                            <input
                                v-model="formModal.form.last_status_message"
                                type="text"
                                class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                            />
                        </label>
                    </div>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="w-full rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 sm:w-auto"
                            @click="formModal.open = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60 sm:w-auto"
                            :disabled="formModal.loading"
                        >
                            <span v-if="formModal.loading">Guardando...</span>
                            <span v-else>Guardar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assign modal -->
        <div
            v-if="assignModal.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            Unidad
                        </p>
                        <h3 class="text-xl font-semibold text-slate-900">Asignar reloj</h3>
                        <p class="text-sm text-slate-500">{{ assignModal.clock?.clock_name }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900"
                        aria-label="Cerrar asignacion de unidad"
                        @click="assignModal.open = false"
                    >
                        <span class="sr-only">Cerrar</span>
                        ✕
                    </button>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="submitAssignment">
                    <label class="text-sm font-medium text-slate-600">
                        Selecciona la unidad
                        <select
                            v-model.number="assignModal.location_id"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        >
                            <option :value="null">Sin asignar</option>
                            <option v-for="location in locationOptions" :key="location.id" :value="location.id">
                                {{ location.name }} ({{ location.code ?? 'N/A' }})
                            </option>
                        </select>
                        <span v-if="assignModal.errors.location_id" class="text-xs text-rose-600">
                            {{ assignModal.errors.location_id[0] }}
                        </span>
                    </label>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="w-full rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 sm:w-auto"
                            @click="assignModal.open = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60 sm:w-auto"
                            :disabled="assignModal.loading"
                        >
                            <span v-if="assignModal.loading">Asignando...</span>
                            <span v-else>Asignar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Logs drawer -->
        <div v-if="logsDrawer.open" class="fixed inset-0 z-40 flex overflow-hidden">
            <div class="flex-1 bg-slate-900/50" @click="logsDrawer.open = false" />
            <div class="w-full max-w-xl overflow-y-auto bg-white p-4 shadow-2xl sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            Bitácora
                        </p>
                        <h3 class="text-2xl font-semibold text-slate-900">
                            {{ logsDrawer.clock?.clock_name }}
                        </h3>
                        <p class="text-sm text-slate-500">
                            Serie {{ logsDrawer.clock?.serial_number ?? 'N/A' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900"
                        aria-label="Cerrar bitacora de reloj"
                        @click="logsDrawer.open = false"
                    >
                        <span class="sr-only">Cerrar</span>
                        ✕
                    </button>
                </div>

                <form class="mt-4 grid gap-3 text-sm sm:grid-cols-2" @submit.prevent="fetchLogs()">
                    <label class="font-medium text-slate-600">
                        Nivel
                        <select
                            v-model="logsDrawer.filters.level"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2"
                        >
                            <option value="">Todos</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                        </select>
                    </label>
                    <label class="font-medium text-slate-600">
                        Tipo
                        <input
                            v-model="logsDrawer.filters.event_type"
                            type="text"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2"
                        />
                    </label>
                    <label class="font-medium text-slate-600">
                        Desde
                        <input
                            v-model="logsDrawer.filters.date_from"
                            type="date"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2"
                        />
                    </label>
                    <label class="font-medium text-slate-600">
                        Hasta
                        <input
                            v-model="logsDrawer.filters.date_to"
                            type="date"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2"
                        />
                    </label>
                    <div class="flex flex-col gap-2 sm:col-span-2 sm:flex-row">
                        <button
                            type="submit"
                            class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white sm:w-auto"
                            :disabled="logsDrawer.loading"
                        >
                            Aplicar filtros
                        </button>
                        <button
                            type="button"
                            class="w-full rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 sm:w-auto"
                            @click="resetLogsFilters"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <div class="mt-6 space-y-4">
                    <div v-if="logsDrawer.loading" class="rounded-2xl border border-slate-100 p-4 text-sm text-slate-500">
                        Cargando eventos...
                    </div>
                    <div v-if="logsDrawer.error" class="rounded-2xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-600">
                        {{ logsDrawer.error }}
                    </div>
                    <article
                        v-for="log in logsDrawer.entries"
                        :key="log.id"
                        class="rounded-2xl border border-slate-100 p-4 text-sm"
                    >
                        <header class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-xs uppercase tracking-[0.3em] text-slate-400">
                                    {{ log.event_type }}
                                </p>
                                <p class="font-semibold text-slate-900">
                                    {{ log.message }}
                                </p>
                            </div>
                            <span
                                class="rounded-full px-3 py-1 text-xs font-semibold capitalize"
                                :class="{
                                    'bg-emerald-50 text-emerald-700': log.level === 'info',
                                    'bg-amber-50 text-amber-700': log.level === 'warning',
                                    'bg-rose-50 text-rose-700': log.level === 'error',
                                }"
                            >
                                {{ log.level }}
                            </span>
                        </header>
                        <p class="mt-2 text-xs text-slate-500">
                            {{ formatDateTime(log.occurred_at) }} • {{ log.source ?? 'sin origen' }}
                        </p>
                    </article>

                    <button
                        v-if="logsDrawer.meta && logsDrawer.meta.next_page_url"
                        type="button"
                        class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 disabled:opacity-60"
                        :disabled="logsDrawer.loading"
                        @click="fetchLogs(logsDrawer.meta.next_page_url, true)"
                    >
                        {{ logsDrawer.loading ? 'Cargando...' : 'Ver más' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
