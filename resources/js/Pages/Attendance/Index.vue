<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    initialRecords: {
        type: Object,
        default: () => ({
            data: [],
            meta: null,
        }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    viewMode: {
        type: String,
        default: 'grouped',
    },
    columns: {
        type: Object,
        default: () => ({
            available: {},
            default: {},
        }),
    },
    employees: {
        type: Array,
        default: () => [],
    },
    locations: {
        type: Array,
        default: () => [],
    },
    clocks: {
        type: Array,
        default: () => [],
    },
    typeLabels: {
        type: Object,
        default: () => ({}),
    },
    statusLabels: {
        type: Object,
        default: () => ({}),
    },
    sourceLabels: {
        type: Object,
        default: () => ({}),
    },
    flash: {
        type: Object,
        default: () => ({}),
    },
});

const currentViewMode = computed(() => props.viewMode ?? props.filters?.view_mode ?? 'grouped');
const records = computed(() => props.initialRecords?.data ?? []);
const paginationMeta = computed(() => ({
    current_page: props.initialRecords?.meta?.current_page ?? 1,
    last_page: props.initialRecords?.meta?.last_page ?? 1,
    per_page: props.initialRecords?.meta?.per_page ?? 25,
    total: props.initialRecords?.meta?.total ?? 0,
    from: props.initialRecords?.meta?.from ?? 0,
    to: props.initialRecords?.meta?.to ?? 0,
}));

const createFilters = (value = {}) => ({
    from: value.from ?? '',
    to: value.to ?? '',
    employee: value.employee ?? '',
    employee_id: value.employee_id ? String(value.employee_id) : '',
    location_id: value.location_id ? String(value.location_id) : '',
    device_id: value.device_id ? String(value.device_id) : '',
    type: value.type ?? '',
    source: value.source ?? '',
    status: value.status ?? '',
    per_page: Number(value.per_page ?? 25),
    view_mode: value.view_mode ?? currentViewMode.value,
});

const filterForm = reactive(createFilters(props.filters));

watch(
    () => props.filters,
    (value) => {
        Object.assign(filterForm, createFilters(value ?? {}));
    },
    { deep: true },
);

const buildQueryFromFilters = (sourceFilters, extra = {}) => {
    const query = {
        from: sourceFilters.from || undefined,
        to: sourceFilters.to || undefined,
        employee: sourceFilters.employee || undefined,
        employee_id: sourceFilters.employee_id || undefined,
        location_id: sourceFilters.location_id || undefined,
        device_id: sourceFilters.device_id || undefined,
        type: sourceFilters.type || undefined,
        source: sourceFilters.source || undefined,
        status: sourceFilters.status || undefined,
        per_page: sourceFilters.per_page || undefined,
        view_mode: sourceFilters.view_mode || currentViewMode.value,
        ...extra,
    };

    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    );
};

const applyFilters = (page = 1) => {
    router.get(route('admin.asistencias.index'), buildQueryFromFilters(filterForm, { page }), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    Object.assign(filterForm, createFilters({ view_mode: filterForm.view_mode || currentViewMode.value }));
    applyFilters(1);
};

const handlePageChange = (page) => {
    applyFilters(page);
};

const handlePerPageChange = (value) => {
    filterForm.per_page = Number(value);
    applyFilters(1);
};

const setViewMode = (mode) => {
    if (filterForm.view_mode === mode) {
        return;
    }

    filterForm.view_mode = mode;
    applyFilters(1);
};

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};

const canEdit = computed(
    () => can('asistencias', 'edit') || can('asistencias', 'admin') || can('settings', 'manage'),
);
const canExport = computed(
    () => can('asistencias', 'export') || can('asistencias', 'admin') || can('settings', 'manage'),
);

const flashStatus = computed(() => props.flash?.status ?? null);
const flashWarning = computed(() => props.flash?.warning ?? null);
const employeeSelectOptions = computed(() =>
    (props.employees ?? []).map((employee) => ({
        ...employee,
        label: employee.code ? `${employee.name} (${employee.code})` : employee.name,
        code: employee.code,
    })),
);
const locationSelectOptions = computed(() =>
    (props.locations ?? []).map((location) => ({
        ...location,
        label: location.code ? `${location.name} (${location.code})` : location.name,
        code: location.code,
    })),
);
const clockSelectOptions = computed(() =>
    (props.clocks ?? []).map((clock) => ({
        ...clock,
        label: clock.serial_number ? `${clock.name} (${clock.serial_number})` : clock.name,
        code: clock.serial_number,
    })),
);

const statusBadgeClass = (status) => {
    if (status === 'anulada') {
        return 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300';
    }

    if (status === 'corregida') {
        return 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300';
    }

    if (status === 'mixto') {
        return 'bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300';
    }

    return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300';
};

const availableColumns = computed(() => props.columns?.available?.[currentViewMode.value] ?? []);
const defaultColumns = computed(() => props.columns?.default?.[currentViewMode.value] ?? []);
const columnStorageKey = computed(() => `attendance-central.columns.${currentViewMode.value}`);
const visibleColumnKeys = ref([]);
const showColumnsPanel = ref(false);

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

    const fallback = [];

    for (const column of availableColumns.value) {
        if (defaultColumns.value.includes(column.key) || column.locked) {
            fallback.push(column.key);
        }
    }

    return fallback.filter((key) => allowed.has(key));
};

const syncVisibleColumns = () => {
    if (typeof window === 'undefined') {
        visibleColumnKeys.value = sanitizeColumnKeys(defaultColumns.value);
        return;
    }

    try {
        const stored = JSON.parse(window.localStorage.getItem(columnStorageKey.value) ?? '[]');
        visibleColumnKeys.value = sanitizeColumnKeys(Array.isArray(stored) ? stored : defaultColumns.value);
    } catch {
        visibleColumnKeys.value = sanitizeColumnKeys(defaultColumns.value);
    }
};

watch([availableColumns, currentViewMode], syncVisibleColumns, { immediate: true });

watch(
    visibleColumnKeys,
    (value) => {
        if (typeof window === 'undefined') {
            return;
        }

        window.localStorage.setItem(columnStorageKey.value, JSON.stringify(sanitizeColumnKeys(value)));
    },
    { deep: true },
);

const orderedVisibleColumns = computed(() =>
    availableColumns.value.filter((column) => visibleColumnKeys.value.includes(column.key)),
);

const visibleExportColumns = computed(() =>
    orderedVisibleColumns.value
        .filter((column) => column.exportable !== false)
        .map((column) => column.key),
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

const queryFromAppliedFilters = computed(() => buildQueryFromFilters(createFilters(props.filters ?? {})));
const exportCsvUrl = computed(() =>
    route('admin.asistencias.export', {
        ...queryFromAppliedFilters.value,
        columns: visibleExportColumns.value,
        format: 'csv',
    }),
);
const exportExcelUrl = computed(() =>
    route('admin.asistencias.export', {
        ...queryFromAppliedFilters.value,
        columns: visibleExportColumns.value,
        format: 'excel',
    }),
);

const annulForm = useForm({
    reason: '',
});

const submitAnnulment = (recordId) => {
    const reason = window.prompt('Motivo de anulacion del registro:');

    if (!reason) {
        return;
    }

    annulForm.reason = reason;
    annulForm.patch(route('admin.asistencias.annul', recordId), {
        preserveScroll: true,
        onFinish: () => annulForm.reset('reason'),
    });
};

const formatDateTimeForInput = () => {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
};

const showAdjustmentModal = ref(false);
const adjustmentForm = useForm({
    employee_id: '',
    log_date: formatDateTimeForInput(),
    log_type: 'in',
    location_id: '',
    device_id: '',
    reason: '',
    notes: '',
});

const openAdjustmentModal = () => {
    showAdjustmentModal.value = true;
};

const closeAdjustmentModal = () => {
    showAdjustmentModal.value = false;
};

const submitAdjustment = () => {
    adjustmentForm.post(route('admin.asistencias.adjustments.store'), {
        preserveScroll: true,
        onError: () => {
            showAdjustmentModal.value = true;
        },
    });
};

const groupedDetailState = reactive({
    open: false,
    loading: false,
    error: null,
    payload: null,
});

const openGroupedDetail = async (record) => {
    groupedDetailState.open = true;
    groupedDetailState.loading = true;
    groupedDetailState.error = null;
    groupedDetailState.payload = null;

    try {
        const url = route('admin.asistencias.grouped-detail', {
            ...queryFromAppliedFilters.value,
            employee_id: record.employee_id,
            local_date: record.local_date,
        });

        const response = await window.fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json();

        if (!response.ok || payload.success === false) {
            throw new Error(payload.message ?? 'No fue posible cargar el detalle del colaborador.');
        }

        groupedDetailState.payload = payload.data;
    } catch (error) {
        groupedDetailState.error = error instanceof Error ? error.message : 'No fue posible cargar el detalle.';
    } finally {
        groupedDetailState.loading = false;
    }
};

const closeGroupedDetail = () => {
    groupedDetailState.open = false;
    groupedDetailState.loading = false;
    groupedDetailState.error = null;
    groupedDetailState.payload = null;
};

useBodyScrollLock(() => showAdjustmentModal.value || groupedDetailState.open);

watch(
    () => adjustmentForm.hasErrors,
    (hasErrors) => {
        if (hasErrors) {
            showAdjustmentModal.value = true;
        }
    },
);

const employeeNameForRow = (record) =>
    currentViewMode.value === 'grouped'
        ? record.employee_name ?? 'N/A'
        : record.employee?.name ?? 'N/A';

const employeeCodeForRow = (record) =>
    currentViewMode.value === 'grouped'
        ? record.employee_code ?? record.fortia_employee_id ?? record.employee_id ?? 'N/A'
        : record.employee?.code ?? 'N/A';

const detailLabelForColumn = (record, key) => {
    if (currentViewMode.value === 'grouped') {
        switch (key) {
        case 'fecha_local':
            return record.local_date_display ?? record.local_date ?? '—';
        case 'primera_checada':
            return record.first_check_display ?? '—';
        case 'ultima_checada':
            return record.last_check_display ?? '—';
        case 'unidad':
            return record.location_name ?? 'Sin unidad';
        case 'reloj':
            return record.clock_name ?? 'Sin reloj';
        case 'employee_id':
            return record.employee_id ?? '—';
        case 'fortia_employee_id':
            return record.fortia_employee_id ?? '—';
        case 'employee_code':
            return record.employee_code ?? '—';
        case 'total_checadas':
            return record.total_checks ?? 0;
        case 'entradas':
            return record.entry_count ?? 0;
        case 'salidas':
            return record.exit_count ?? 0;
        case 'fuente':
            return record.source_label ?? '—';
        case 'tipo':
            return record.type_label ?? '—';
        case 'primera_unidad':
            return record.first_location_name ?? '—';
        case 'ultima_unidad':
            return record.last_location_name ?? '—';
        case 'primer_reloj':
            return record.first_clock_name ?? '—';
        case 'ultimo_reloj':
            return record.last_clock_name ?? '—';
        case 'empresa':
            return record.company ?? '—';
        case 'departamento':
            return record.department ?? '—';
        case 'puesto':
            return record.position ?? '—';
        case 'created_at':
            return record.created_at_display ?? '—';
        case 'updated_at':
            return record.updated_at_display ?? '—';
        case 'estado_validacion':
            return record.status_label ?? '—';
        case 'observaciones':
            return record.observation_summary ?? '—';
        default:
            return '—';
        }
    }

    switch (key) {
    case 'hora_local':
        return record.log_date_display ?? '—';
    case 'fecha_local':
        return record.log_date_display?.slice(0, 10) ?? '—';
    case 'fecha_utc':
        return record.log_date_utc_display ?? '—';
    case 'unidad':
        return record.location?.name ?? 'Sin unidad';
    case 'reloj':
        return record.clock?.name ?? 'Sin reloj';
    case 'tipo':
        return `${record.log_type_label ?? '—'}${record.log_type !== undefined ? ` (${record.log_type})` : ''}`;
    case 'fuente':
        return record.source_label ?? '—';
    case 'employee_id':
        return record.employee?.id ?? '—';
    case 'fortia_employee_id':
    case 'employee_code':
        return record.employee?.code ?? '—';
    case 'empresa':
        return record.company_name ?? '—';
    case 'departamento':
        return record.department_name ?? '—';
    case 'puesto':
        return record.position_name ?? '—';
    case 'created_at':
        return record.created_at_display ?? '—';
    case 'updated_at':
        return record.updated_at_display ?? '—';
    case 'estado_validacion':
        return record.status_label ?? '—';
    case 'observaciones':
        return record.adjustment_reason ?? '—';
    default:
        return '—';
    }
};
</script>

<template>
    <Head title="Central de Asistencias" />

    <AuthenticatedLayout>
        <template #header>
            <div class="min-w-0">
                <h1 class="text-app truncate text-base font-semibold leading-tight sm:text-xl">
                    Central de Asistencias
                </h1>
                <p class="hidden truncate text-xs text-slate-500 sm:block sm:text-sm">
                    Vista operativa agrupada por colaborador y dia con detalle completo de checadas.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div
                v-if="flashStatus"
                class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
            >
                {{ flashStatus }}
            </div>
            <div
                v-if="flashWarning"
                class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                {{ flashWarning }}
            </div>

            <section class="card min-w-0 overflow-visible p-4">
                <form class="grid min-w-0 grid-cols-1 gap-3 overflow-hidden sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters(1)">
                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Desde
                        <input
                            v-model="filterForm.from"
                            type="date"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Hasta
                        <input
                            v-model="filterForm.to"
                            type="date"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Empleado (nombre/codigo)
                        <input
                            v-model="filterForm.employee"
                            type="text"
                            placeholder="Nombre o codigo"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Empleado exacto
                        <SearchableSelect
                            v-model="filterForm.employee_id"
                            :options="employeeSelectOptions"
                            placeholder="Todos"
                            search-placeholder="Buscar por nombre, código o ID"
                            :searchable="true"
                            input-class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Unidad / sucursal
                        <SearchableSelect
                            v-model="filterForm.location_id"
                            :options="locationSelectOptions"
                            placeholder="Todas"
                            search-placeholder="Buscar unidad..."
                            :searchable="true"
                            input-class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Reloj / dispositivo
                        <SearchableSelect
                            v-model="filterForm.device_id"
                            :options="clockSelectOptions"
                            placeholder="Todos"
                            search-placeholder="Buscar reloj o serie..."
                            :searchable="true"
                            input-class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Tipo
                        <select
                            v-model="filterForm.type"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Todos</option>
                            <option value="in">IN (Entrada)</option>
                            <option value="out">OUT (Salida)</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="unknown">Desconocido</option>
                        </select>
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Fuente
                        <select
                            v-model="filterForm.source"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Todas</option>
                            <option
                                v-for="(label, key) in sourceLabels"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Estatus
                        <select
                            v-model="filterForm.status"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="(label, key) in statusLabels"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Registros por pagina
                        <select
                            v-model.number="filterForm.per_page"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                            <option :value="200">200</option>
                        </select>
                    </label>

                    <div class="flex min-w-0 flex-col gap-2 sm:col-span-2 sm:flex-row sm:flex-wrap sm:items-end sm:justify-end lg:col-span-4">
                        <button
                            type="submit"
                            class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-white sm:w-auto sm:tracking-[0.3em]"
                        >
                            Aplicar filtros
                        </button>
                        <button
                            type="button"
                            class="w-full rounded-2xl border border-app px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-muted sm:w-auto sm:tracking-[0.3em]"
                            @click="clearFilters"
                        >
                            Limpiar
                        </button>
                        <button
                            v-if="canEdit"
                            type="button"
                            class="w-full rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-emerald-700 sm:w-auto sm:tracking-[0.3em]"
                            @click="openAdjustmentModal"
                        >
                            Ajuste manual
                        </button>
                    </div>
                </form>
            </section>

            <section class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-2xl px-4 py-2 text-xs font-semibold uppercase tracking-[0.15em] transition sm:tracking-[0.3em]"
                        :class="filterForm.view_mode === 'grouped'
                            ? 'bg-indigo-600 text-white'
                            : 'border border-app text-muted'"
                        @click="setViewMode('grouped')"
                    >
                        Vista agrupada
                    </button>
                    <button
                        type="button"
                        class="rounded-2xl px-4 py-2 text-xs font-semibold uppercase tracking-[0.15em] transition sm:tracking-[0.3em]"
                        :class="filterForm.view_mode === 'raw'
                            ? 'bg-indigo-600 text-white'
                            : 'border border-app text-muted'"
                        @click="setViewMode('raw')"
                    >
                        Registros crudos
                    </button>
                </div>

                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                    <div class="relative">
                        <button
                            type="button"
                            class="w-full rounded-2xl border border-app px-3 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-muted sm:w-auto sm:tracking-[0.3em]"
                            @click="showColumnsPanel = !showColumnsPanel"
                        >
                            Columnas
                        </button>

                        <div
                            v-if="showColumnsPanel"
                            class="absolute right-0 z-20 mt-2 w-72 rounded-2xl border border-app bg-white p-3 shadow-xl dark:bg-slate-900"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <p class="text-sm font-semibold text-app">Columnas visibles</p>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-soft"
                                    @click="showColumnsPanel = false"
                                >
                                    Cerrar
                                </button>
                            </div>

                            <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
                                <label
                                    v-for="column in availableColumns"
                                    :key="column.key"
                                    class="flex items-start gap-2 rounded-2xl px-2 py-1 text-sm text-app hover:bg-slate-50 dark:hover:bg-slate-800"
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
                        </div>
                    </div>

                    <div v-if="canExport" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:justify-end">
                        <a
                            :href="exportCsvUrl"
                            class="w-full rounded-2xl border border-app px-3 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-muted sm:w-auto sm:tracking-[0.3em]"
                        >
                            Exportar CSV
                        </a>
                        <a
                            :href="exportExcelUrl"
                            class="w-full rounded-2xl border border-app px-3 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-muted sm:w-auto sm:tracking-[0.3em]"
                        >
                            Exportar Excel
                        </a>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-stretch justify-between gap-3 sm:items-center">
                <PaginationBar
                    class="flex-1"
                    :meta="paginationMeta"
                    :per-page-options="[25, 50, 100, 200]"
                    @update:page="handlePageChange"
                    @update:perPage="handlePerPageChange"
                />
            </div>

            <section class="card overflow-hidden">
                <div v-if="!records.length" class="p-4">
                    <p class="text-center text-sm text-soft">No hay registros para los filtros seleccionados.</p>
                </div>

                <div v-else class="space-y-3 p-3 sm:hidden">
                    <article
                        v-for="record in records"
                        :key="record.group_key ?? `mobile-${record.id}`"
                        class="card-subtle p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-app">{{ employeeNameForRow(record) }}</p>
                                <p class="text-xs text-soft">ID {{ employeeCodeForRow(record) }}</p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusBadgeClass(record.status ?? record.attendance_status)">
                                {{ record.status_label }}
                            </span>
                        </div>

                        <dl class="mt-3 grid grid-cols-1 gap-2 text-xs">
                            <template
                                v-for="column in orderedVisibleColumns.filter((column) => !['empleado', 'status', 'acciones'].includes(column.key))"
                                :key="`${record.group_key ?? record.id}-${column.key}`"
                            >
                                <div>
                                    <dt class="text-soft">{{ column.label }}</dt>
                                    <dd class="break-words text-app">{{ detailLabelForColumn(record, column.key) }}</dd>
                                </div>
                            </template>
                        </dl>

                        <div class="mt-3 flex justify-end">
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold text-muted"
                                    >
                                        Acciones
                                    </button>
                                </template>

                                <template #content>
                                    <template v-if="currentViewMode === 'grouped'">
                                        <button
                                            type="button"
                                            class="block w-full px-4 py-2 text-left text-sm text-app hover:bg-slate-100 dark:hover:bg-slate-800"
                                            @click="openGroupedDetail(record)"
                                        >
                                            Ver detalle
                                        </button>
                                        <DropdownLink
                                            v-if="record.first_record_id"
                                            :href="route('admin.asistencias.show', record.first_record_id)"
                                        >
                                            Abrir primera checada
                                        </DropdownLink>
                                    </template>
                                    <template v-else>
                                        <DropdownLink :href="route('admin.asistencias.show', record.id)">
                                            Ver detalle
                                        </DropdownLink>
                                        <button
                                            v-if="canEdit && record.attendance_status !== 'anulada'"
                                            type="button"
                                            class="block w-full px-4 py-2 text-left text-sm text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-slate-800"
                                            :disabled="annulForm.processing"
                                            @click="submitAnnulment(record.id)"
                                        >
                                            Anular
                                        </button>
                                    </template>
                                </template>
                            </Dropdown>
                        </div>
                    </article>
                </div>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full min-w-[72rem] divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-[0.3em] text-soft dark:bg-slate-900/70">
                            <tr>
                                <th
                                    v-for="column in orderedVisibleColumns"
                                    :key="column.key"
                                    class="px-3 py-3"
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="record in records"
                                :key="record.group_key ?? record.id"
                                class="hover:bg-slate-50 dark:hover:bg-slate-900/40"
                            >
                                <td
                                    v-for="column in orderedVisibleColumns"
                                    :key="`${record.group_key ?? record.id}-${column.key}`"
                                    class="px-3 py-3 align-top"
                                >
                                    <template v-if="column.key === 'empleado'">
                                        <p class="max-w-[16rem] truncate font-semibold text-app" :title="employeeNameForRow(record)">
                                            {{ employeeNameForRow(record) }}
                                        </p>
                                        <p class="text-xs text-soft">ID {{ employeeCodeForRow(record) }}</p>
                                    </template>

                                    <template v-else-if="column.key === 'status'">
                                        <span
                                            class="rounded-full px-3 py-1 text-xs font-semibold"
                                            :class="statusBadgeClass(record.status ?? record.attendance_status)"
                                        >
                                            {{ record.status_label }}
                                        </span>
                                    </template>

                                    <template v-else-if="column.key === 'acciones'">
                                        <Dropdown align="right" width="48">
                                            <template #trigger>
                                                <button
                                                    type="button"
                                                    class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold text-muted"
                                                >
                                                    Acciones
                                                </button>
                                            </template>

                                            <template #content>
                                                <template v-if="currentViewMode === 'grouped'">
                                                    <button
                                                        type="button"
                                                        class="block w-full px-4 py-2 text-left text-sm text-app hover:bg-slate-100 dark:hover:bg-slate-800"
                                                        @click="openGroupedDetail(record)"
                                                    >
                                                        Ver detalle
                                                    </button>
                                                    <DropdownLink
                                                        v-if="record.first_record_id"
                                                        :href="route('admin.asistencias.show', record.first_record_id)"
                                                    >
                                                        Abrir primera checada
                                                    </DropdownLink>
                                                </template>
                                                <template v-else>
                                                    <DropdownLink :href="route('admin.asistencias.show', record.id)">
                                                        Ver detalle
                                                    </DropdownLink>
                                                    <button
                                                        v-if="canEdit && record.attendance_status !== 'anulada'"
                                                        type="button"
                                                        class="block w-full px-4 py-2 text-left text-sm text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-slate-800"
                                                        :disabled="annulForm.processing"
                                                        @click="submitAnnulment(record.id)"
                                                    >
                                                        Anular
                                                    </button>
                                                </template>
                                            </template>
                                        </Dropdown>
                                    </template>

                                    <template v-else-if="currentViewMode === 'grouped' && column.key === 'fecha_local'">
                                        <p class="text-app">{{ record.local_date_display ?? record.local_date ?? '—' }}</p>
                                        <p class="text-xs text-soft">America/Mexico_City</p>
                                    </template>

                                    <template v-else-if="currentViewMode === 'grouped' && column.key === 'unidad'">
                                        <p class="max-w-[12rem] truncate text-app" :title="record.location_name ?? 'Sin unidad'">
                                            {{ record.location_name ?? 'Sin unidad' }}
                                        </p>
                                        <p
                                            v-if="record.has_multiple_locations"
                                            class="max-w-[12rem] truncate text-xs text-soft"
                                            :title="`${record.first_location_name ?? '—'} / ${record.last_location_name ?? '—'}`"
                                        >
                                            {{ record.first_location_name ?? '—' }} / {{ record.last_location_name ?? '—' }}
                                        </p>
                                    </template>

                                    <template v-else-if="currentViewMode === 'grouped' && column.key === 'reloj'">
                                        <p class="max-w-[12rem] truncate text-app" :title="record.clock_name ?? 'Sin reloj'">
                                            {{ record.clock_name ?? 'Sin reloj' }}
                                        </p>
                                        <p
                                            v-if="record.has_multiple_clocks"
                                            class="max-w-[12rem] truncate text-xs text-soft"
                                            :title="`${record.first_clock_name ?? '—'} / ${record.last_clock_name ?? '—'}`"
                                        >
                                            {{ record.first_clock_name ?? '—' }} / {{ record.last_clock_name ?? '—' }}
                                        </p>
                                    </template>

                                    <template v-else-if="currentViewMode === 'raw' && column.key === 'hora_local'">
                                        <p class="text-app">{{ record.log_date_display ?? 'N/A' }}</p>
                                        <p class="text-xs text-soft">{{ record.log_date_timezone ?? 'America/Mexico_City' }}</p>
                                    </template>

                                    <template v-else>
                                        <span class="block max-w-[14rem] break-words text-app">
                                            {{ detailLabelForColumn(record, column.key) }}
                                        </span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <div
            v-if="groupedDetailState.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="card flex w-full max-w-5xl flex-col overflow-hidden">
                <div class="flex items-center justify-between border-b border-app px-4 py-4">
                    <div class="min-w-0">
                        <h3 class="truncate text-lg font-semibold text-app">Detalle de checadas del colaborador</h3>
                        <p class="text-sm text-soft">
                            {{ groupedDetailState.payload?.employee?.name ?? 'Cargando...' }}
                            <span v-if="groupedDetailState.payload?.local_date"> · {{ groupedDetailState.payload.local_date }}</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-2xl border border-app px-3 py-1 text-xs font-semibold text-muted"
                        @click="closeGroupedDetail"
                    >
                        Cerrar
                    </button>
                </div>

                <div class="max-h-[80vh] overflow-y-auto p-4">
                    <div v-if="groupedDetailState.loading" class="py-10 text-center text-sm text-soft">
                        Cargando detalle...
                    </div>

                    <div v-else-if="groupedDetailState.error" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        {{ groupedDetailState.error }}
                    </div>

                    <template v-else-if="groupedDetailState.payload">
                        <section class="grid gap-3 md:grid-cols-4">
                            <article class="card-subtle p-3">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Primera checada</p>
                                <p class="mt-2 text-sm font-semibold text-app">
                                    {{ groupedDetailState.payload.summary?.first_check_display ?? '—' }}
                                </p>
                            </article>
                            <article class="card-subtle p-3">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Última checada</p>
                                <p class="mt-2 text-sm font-semibold text-app">
                                    {{ groupedDetailState.payload.summary?.last_check_display ?? '—' }}
                                </p>
                            </article>
                            <article class="card-subtle p-3">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Total</p>
                                <p class="mt-2 text-sm font-semibold text-app">
                                    {{ groupedDetailState.payload.summary?.total_checks ?? 0 }} checadas
                                </p>
                                <p class="text-xs text-soft">
                                    Entradas {{ groupedDetailState.payload.summary?.entry_count ?? 0 }} · Salidas {{ groupedDetailState.payload.summary?.exit_count ?? 0 }}
                                </p>
                            </article>
                            <article class="card-subtle p-3">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Contexto</p>
                                <p class="mt-2 text-sm font-semibold text-app">
                                    {{ groupedDetailState.payload.employee?.name ?? '—' }}
                                </p>
                                <p class="text-xs text-soft">
                                    {{ groupedDetailState.payload.employee?.code ?? '—' }}
                                </p>
                            </article>
                        </section>

                        <section class="mt-4 grid gap-3 md:grid-cols-2">
                            <article class="card-subtle p-3">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Unidades usadas</p>
                                <p class="mt-2 text-sm text-app">
                                    {{ (groupedDetailState.payload.summary?.units ?? []).join(', ') || 'Sin unidades registradas' }}
                                </p>
                            </article>
                            <article class="card-subtle p-3">
                                <p class="text-xs uppercase tracking-[0.3em] text-soft">Relojes usados</p>
                                <p class="mt-2 text-sm text-app">
                                    {{ (groupedDetailState.payload.summary?.clocks ?? []).join(', ') || 'Sin relojes registrados' }}
                                </p>
                            </article>
                        </section>

                        <section class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[62rem] divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-[0.3em] text-soft dark:bg-slate-900/70">
                                    <tr>
                                        <th class="px-3 py-3">Hora local</th>
                                        <th class="px-3 py-3">Tipo</th>
                                        <th class="px-3 py-3">Unidad</th>
                                        <th class="px-3 py-3">Reloj</th>
                                        <th class="px-3 py-3">Fuente</th>
                                        <th class="px-3 py-3">Status</th>
                                        <th class="px-3 py-3">Observación</th>
                                        <th class="px-3 py-3">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr
                                        v-for="record in groupedDetailState.payload.records"
                                        :key="`detail-${record.id}`"
                                    >
                                        <td class="px-3 py-3 text-app">
                                            <p>{{ record.log_date_display ?? '—' }}</p>
                                            <p class="text-xs text-soft">{{ record.log_date_timezone ?? 'America/Mexico_City' }}</p>
                                        </td>
                                        <td class="px-3 py-3 text-app">
                                            {{ record.log_type_label }} ({{ record.log_type }})
                                        </td>
                                        <td class="px-3 py-3 text-app">
                                            {{ record.location?.name ?? 'Sin unidad' }}
                                        </td>
                                        <td class="px-3 py-3 text-app">
                                            {{ record.clock?.name ?? 'Sin reloj' }}
                                        </td>
                                        <td class="px-3 py-3 text-app">
                                            {{ record.source_label }}
                                        </td>
                                        <td class="px-3 py-3">
                                            <span
                                                class="rounded-full px-3 py-1 text-xs font-semibold"
                                                :class="statusBadgeClass(record.attendance_status)"
                                            >
                                                {{ record.status_label }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-3 text-app">
                                            {{ record.adjustment_reason ?? '—' }}
                                        </td>
                                        <td class="px-3 py-3">
                                            <Dropdown align="right" width="48">
                                                <template #trigger>
                                                    <button
                                                        type="button"
                                                        class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold text-muted"
                                                    >
                                                        Acciones
                                                    </button>
                                                </template>

                                                <template #content>
                                                    <DropdownLink :href="route('admin.asistencias.show', record.id)">
                                                        Ver detalle
                                                    </DropdownLink>
                                                    <button
                                                        v-if="canEdit && record.attendance_status !== 'anulada'"
                                                        type="button"
                                                        class="block w-full px-4 py-2 text-left text-sm text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-slate-800"
                                                        :disabled="annulForm.processing"
                                                        @click="submitAnnulment(record.id)"
                                                    >
                                                        Anular checada
                                                    </button>
                                                </template>
                                            </Dropdown>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                    </template>
                </div>
            </div>
        </div>

        <div
            v-if="canEdit && showAdjustmentModal"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl dark:bg-slate-900 sm:p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-app">Nuevo ajuste manual</h3>
                    <button
                        type="button"
                        class="rounded-2xl border border-app px-3 py-1 text-xs font-semibold text-muted"
                        @click="closeAdjustmentModal"
                    >
                        Cerrar
                    </button>
                </div>

                <form class="grid gap-3 md:grid-cols-2" @submit.prevent="submitAdjustment">
                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft md:col-span-2">
                        Empleado
                        <SearchableSelect
                            v-model="adjustmentForm.employee_id"
                            :options="employeeSelectOptions"
                            placeholder="Selecciona empleado"
                            search-placeholder="Buscar empleado..."
                            :searchable="true"
                            input-class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                        <span v-if="adjustmentForm.errors.employee_id" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.employee_id }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Fecha y hora
                        <input
                            v-model="adjustmentForm.log_date"
                            type="datetime-local"
                            required
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                        <span v-if="adjustmentForm.errors.log_date" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.log_date }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Tipo
                        <select
                            v-model="adjustmentForm.log_type"
                            required
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="in">IN (Entrada)</option>
                            <option value="out">OUT (Salida)</option>
                            <option value="unknown">Desconocido</option>
                        </select>
                        <span v-if="adjustmentForm.errors.log_type" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.log_type }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Unidad (opcional)
                        <SearchableSelect
                            v-model="adjustmentForm.location_id"
                            :options="locationSelectOptions"
                            placeholder="Sin unidad específica"
                            search-placeholder="Buscar unidad..."
                            :searchable="true"
                            input-class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                        <span v-if="adjustmentForm.errors.location_id" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.location_id }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Reloj (opcional)
                        <SearchableSelect
                            v-model="adjustmentForm.device_id"
                            :options="clockSelectOptions"
                            placeholder="Sin reloj específico"
                            search-placeholder="Buscar reloj o serie..."
                            :searchable="true"
                            input-class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                        <span v-if="adjustmentForm.errors.device_id" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.device_id }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft md:col-span-2">
                        Motivo del ajuste
                        <input
                            v-model="adjustmentForm.reason"
                            type="text"
                            maxlength="500"
                            required
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                        <span v-if="adjustmentForm.errors.reason" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.reason }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft md:col-span-2">
                        Notas (opcional)
                        <textarea
                            v-model="adjustmentForm.notes"
                            rows="3"
                            maxlength="1000"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                        <span v-if="adjustmentForm.errors.notes" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.notes }}
                        </span>
                    </label>

                    <div class="md:col-span-2 flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="w-full rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted sm:w-auto"
                            @click="closeAdjustmentModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white disabled:opacity-60 sm:w-auto"
                            :disabled="adjustmentForm.processing"
                        >
                            <span v-if="adjustmentForm.processing">Guardando...</span>
                            <span v-else>Guardar ajuste</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
