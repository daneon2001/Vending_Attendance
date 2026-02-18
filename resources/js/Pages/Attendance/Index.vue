<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
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
        ...extra,
    };

    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    );
};

const applyFilters = (page = 1) => {
    router.get(
        route('admin.asistencias.index'),
        buildQueryFromFilters(filterForm, { page }),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

const clearFilters = () => {
    Object.assign(filterForm, createFilters());
    applyFilters(1);
};

const handlePageChange = (page) => {
    applyFilters(page);
};

const handlePerPageChange = (value) => {
    filterForm.per_page = Number(value);
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

const statusBadgeClass = (status) => {
    if (status === 'anulada') {
        return 'bg-rose-50 text-rose-700';
    }

    if (status === 'corregida') {
        return 'bg-amber-50 text-amber-700';
    }

    return 'bg-emerald-50 text-emerald-700';
};

const queryFromAppliedFilters = computed(() => buildQueryFromFilters(createFilters(props.filters ?? {})));
const exportCsvUrl = computed(() =>
    route('admin.asistencias.export', {
        ...queryFromAppliedFilters.value,
        format: 'csv',
    }),
);
const exportExcelUrl = computed(() =>
    route('admin.asistencias.export', {
        ...queryFromAppliedFilters.value,
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

watch(
    () => adjustmentForm.hasErrors,
    (hasErrors) => {
        if (hasErrors) {
            showAdjustmentModal.value = true;
        }
    },
);
</script>

<template>
    <Head title="Central de Asistencias" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-xl font-semibold leading-tight">
                    Central de Asistencias
                </h1>
                <p class="text-sm text-slate-500">
                    Registros crudos centralizados, filtros operativos y ajustes auditados.
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

            <section class="rounded-3xl border border-slate-100 bg-white/90 p-4 shadow-sm">
                <form class="grid gap-3 md:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters(1)">
                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Desde
                        <input
                            v-model="filterForm.from"
                            type="date"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Hasta
                        <input
                            v-model="filterForm.to"
                            type="date"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Empleado (nombre/codigo)
                        <input
                            v-model="filterForm.employee"
                            type="text"
                            placeholder="Nombre o codigo"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        />
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Empleado exacto
                        <select
                            v-model="filterForm.employee_id"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="employee in employees"
                                :key="employee.id"
                                :value="String(employee.id)"
                            >
                                {{ employee.name }} ({{ employee.code }})
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Unidad / sucursal
                        <select
                            v-model="filterForm.location_id"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Todas</option>
                            <option
                                v-for="location in locations"
                                :key="location.id"
                                :value="String(location.id)"
                            >
                                {{ location.name }}{{ location.code ? ` (${location.code})` : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Reloj / dispositivo
                        <select
                            v-model="filterForm.device_id"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="clock in clocks"
                                :key="clock.id"
                                :value="String(clock.id)"
                            >
                                {{ clock.name }}{{ clock.serial_number ? ` (${clock.serial_number})` : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Tipo
                        <select
                            v-model="filterForm.type"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
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

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Fuente
                        <select
                            v-model="filterForm.source"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
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

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Estatus
                        <select
                            v-model="filterForm.status"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
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

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Registros por pagina
                        <select
                            v-model.number="filterForm.per_page"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                            <option :value="200">200</option>
                        </select>
                    </label>

                    <div class="flex flex-wrap items-end gap-2 lg:col-span-2">
                        <button
                            type="submit"
                            class="rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white"
                        >
                            Aplicar filtros
                        </button>
                        <button
                            type="button"
                            class="rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted"
                            @click="clearFilters"
                        >
                            Limpiar
                        </button>
                        <button
                            v-if="canEdit"
                            type="button"
                            class="rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-emerald-700"
                            @click="openAdjustmentModal"
                        >
                            Ajuste manual
                        </button>
                    </div>
                </form>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <PaginationBar
                    class="flex-1"
                    :meta="paginationMeta"
                    :per-page-options="[25, 50, 100, 200]"
                    @update:page="handlePageChange"
                    @update:perPage="handlePerPageChange"
                />
                <div v-if="canExport" class="flex gap-2">
                    <a
                        :href="exportCsvUrl"
                        class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted"
                    >
                        Exportar CSV
                    </a>
                    <a
                        :href="exportExcelUrl"
                        class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted"
                    >
                        Exportar Excel
                    </a>
                </div>
            </div>

            <section class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-[0.3em] text-soft">
                            <tr>
                                <th class="px-3 py-3">Fecha/hora</th>
                                <th class="px-3 py-3">Empleado</th>
                                <th class="px-3 py-3">Unidad</th>
                                <th class="px-3 py-3">Reloj</th>
                                <th class="px-3 py-3">Tipo</th>
                                <th class="px-3 py-3">Fuente</th>
                                <th class="px-3 py-3">Estatus</th>
                                <th class="px-3 py-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="!records.length">
                                <td colspan="8" class="px-3 py-8 text-center text-sm text-soft">
                                    No hay registros para los filtros seleccionados.
                                </td>
                            </tr>
                            <tr
                                v-for="record in records"
                                :key="record.id"
                                class="hover:bg-slate-50"
                            >
                                <td class="px-3 py-3 text-app">
                                    {{ record.log_date_display ?? 'N/A' }}
                                </td>
                                <td class="px-3 py-3">
                                    <p class="font-semibold text-app">{{ record.employee?.name ?? 'N/A' }}</p>
                                    <p class="text-xs text-soft">ID {{ record.employee?.code ?? 'N/A' }}</p>
                                </td>
                                <td class="px-3 py-3 text-muted">
                                    {{ record.location?.name ?? 'Sin unidad' }}
                                </td>
                                <td class="px-3 py-3 text-muted">
                                    {{ record.clock?.name ?? 'N/A' }}
                                </td>
                                <td class="px-3 py-3 text-muted">
                                    {{ record.log_type_label }} ({{ record.log_type }})
                                </td>
                                <td class="px-3 py-3 text-muted">
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
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <Link
                                            :href="route('admin.asistencias.show', record.id)"
                                            class="rounded-2xl border border-app px-3 py-1 text-xs font-semibold text-muted"
                                        >
                                            Ver detalle
                                        </Link>
                                        <button
                                            v-if="canEdit && record.attendance_status !== 'anulada'"
                                            type="button"
                                            class="rounded-2xl border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700"
                                            :disabled="annulForm.processing"
                                            @click="submitAnnulment(record.id)"
                                        >
                                            Anular
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <div
            v-if="canEdit && showAdjustmentModal"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="w-full max-w-2xl rounded-3xl bg-white p-6 shadow-2xl dark:bg-slate-900">
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
                        <select
                            v-model="adjustmentForm.employee_id"
                            required
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Selecciona empleado</option>
                            <option
                                v-for="employee in employees"
                                :key="employee.id"
                                :value="String(employee.id)"
                            >
                                {{ employee.name }} ({{ employee.code }})
                            </option>
                        </select>
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
                        <select
                            v-model="adjustmentForm.location_id"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Sin unidad especifica</option>
                            <option
                                v-for="location in locations"
                                :key="location.id"
                                :value="String(location.id)"
                            >
                                {{ location.name }}{{ location.code ? ` (${location.code})` : '' }}
                            </option>
                        </select>
                        <span v-if="adjustmentForm.errors.location_id" class="text-xs text-rose-600">
                            {{ adjustmentForm.errors.location_id }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Reloj (opcional)
                        <select
                            v-model="adjustmentForm.device_id"
                            class="rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app dark:bg-slate-900"
                        >
                            <option value="">Sin reloj especifico</option>
                            <option
                                v-for="clock in clocks"
                                :key="clock.id"
                                :value="String(clock.id)"
                            >
                                {{ clock.name }}{{ clock.serial_number ? ` (${clock.serial_number})` : '' }}
                            </option>
                        </select>
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

                    <div class="md:col-span-2 flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted"
                            @click="closeAdjustmentModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white disabled:opacity-60"
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
