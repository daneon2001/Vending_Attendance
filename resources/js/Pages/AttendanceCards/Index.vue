<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    period: {
        type: Object,
        default: () => ({}),
    },
    timezone: {
        type: Object,
        default: () => ({}),
    },
    periodOptions: {
        type: Array,
        default: () => [],
    },
    companies: {
        type: Array,
        default: () => [],
    },
    locations: {
        type: Array,
        default: () => [],
    },
    departments: {
        type: Array,
        default: () => [],
    },
    employees: {
        type: Array,
        default: () => [],
    },
    employeeScope: {
        type: Object,
        default: () => ({}),
    },
    card: {
        type: Object,
        default: () => ({
            employee: null,
            summary: {},
            rows: [],
        }),
    },
    flash: {
        type: Object,
        default: () => ({}),
    },
});

const createFilters = (value = {}) => ({
    employee_id: value.employee_id ?? '',
    company_id: value.company_id ?? '',
    location_id: value.location_id ?? '',
    department_id: value.department_id ?? '',
    period: value.period ?? 'today',
    from_date: value.from_date ?? '',
    to_date: value.to_date ?? '',
});

const filterForm = reactive(createFilters(props.filters));
const loading = ref(false);
const employeeSearchLoading = ref(false);
const employeeOptions = ref(props.employees ?? []);
let employeeSearchDebounceTimer = null;

onBeforeUnmount(() => {
    if (employeeSearchDebounceTimer) {
        clearTimeout(employeeSearchDebounceTimer);
    }
});

watch(
    () => props.filters,
    (value) => {
        Object.assign(filterForm, createFilters(value ?? {}));
        loading.value = false;
    },
    { deep: true },
);

watch(
    () => props.employees,
    (value) => {
        employeeOptions.value = value ?? [];
        employeeSearchLoading.value = false;
    },
    { deep: true },
);

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};

const canExport = computed(
    () => can('asistencias', 'export') || can('asistencias', 'admin') || can('settings', 'manage'),
);

const flashStatus = computed(() => props.flash?.status ?? null);
const flashWarning = computed(() => props.flash?.warning ?? null);

const filteredLocations = computed(() => {
    if (!filterForm.company_id) {
        return props.locations;
    }

    return props.locations.filter((location) => String(location.company_id ?? '') === String(filterForm.company_id));
});
const companySelectOptions = computed(() =>
    props.companies.map((company) => ({
        ...company,
        label: company.code ? `${company.name} (${company.code})` : company.name,
    })),
);
const locationSelectOptions = computed(() =>
    filteredLocations.value.map((location) => ({
        ...location,
        label: location.code ? `${location.name} (${location.code})` : location.name,
    })),
);
const departmentSelectOptions = computed(() =>
    props.departments.map((department) => ({
        ...department,
        label: department.name,
    })),
);

const employeeSelectOptions = computed(() => employeeOptions.value ?? []);

const selectedEmployee = computed(() => props.card?.employee ?? null);
const rows = computed(() => props.card?.rows ?? []);
const summary = computed(() => props.card?.summary ?? {});
const requiresEmployee = computed(() => props.card?.requires_employee ?? false);
const isCustomPeriod = computed(() => filterForm.period === 'custom');
const exportUrl = computed(() =>
    route('attendance-cards.export', buildQuery(filterForm)),
);

const statusToneClass = (tone) => {
    if (tone === 'emerald') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if (tone === 'amber') return 'bg-amber-50 text-amber-700 border-amber-200';
    if (tone === 'rose') return 'bg-rose-50 text-rose-700 border-rose-200';
    return 'bg-slate-100 text-slate-600 border-slate-200';
};

const buildQuery = (source) =>
    Object.fromEntries(
        Object.entries({
            employee_id: source.employee_id || undefined,
            company_id: source.company_id || undefined,
            location_id: source.location_id || undefined,
            department_id: source.department_id || undefined,
            period: source.period || undefined,
            from_date: source.from_date || undefined,
            to_date: source.to_date || undefined,
        }).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    );

const applyFilters = () => {
    loading.value = true;
    router.get(route('attendance-cards.index'), buildQuery(filterForm), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onFinish: () => {
            loading.value = false;
        },
    });
};

const clearFilters = () => {
    Object.assign(filterForm, {
        employee_id: '',
        company_id: '',
        location_id: '',
        department_id: '',
        period: 'today',
        from_date: '',
        to_date: '',
    });
    applyFilters();
};

const mergeEmployeeOptions = (items = []) => {
    const merged = [...items];
    const selectedId = String(filterForm.employee_id || '');

    if (selectedId !== '') {
        const selectedFromPayload = (props.employees ?? []).find((option) => String(option?.value ?? option?.id ?? '') === selectedId);

        if (selectedFromPayload && !merged.some((option) => String(option?.value ?? option?.id ?? '') === selectedId)) {
            merged.unshift(selectedFromPayload);
        }
    }

    employeeOptions.value = merged.filter((option, index, all) =>
        all.findIndex((candidate) => String(candidate?.value ?? candidate?.id ?? '') === String(option?.value ?? option?.id ?? '')) === index,
    );
};

const fetchEmployeeOptions = async (query = '') => {
    employeeSearchLoading.value = true;

    try {
        const { data } = await axios.get(route('attendance-cards.employees.search'), {
            params: {
                q: query || undefined,
                company_id: filterForm.company_id || undefined,
                location_id: filterForm.location_id || undefined,
                department_id: filterForm.department_id || undefined,
                employee_id: filterForm.employee_id || undefined,
                limit: 25,
            },
        });

        mergeEmployeeOptions(data?.data ?? []);
    } catch (error) {
        mergeEmployeeOptions(props.employees ?? []);
    } finally {
        employeeSearchLoading.value = false;
    }
};

const queueEmployeeSearch = (query = '') => {
    if (employeeSearchDebounceTimer) {
        clearTimeout(employeeSearchDebounceTimer);
    }

    employeeSearchDebounceTimer = setTimeout(() => {
        fetchEmployeeOptions(query);
    }, 250);
};

watch(
    () => filterForm.company_id,
    () => {
        const locationExists = filteredLocations.value.some(
            (location) => String(location.id) === String(filterForm.location_id),
        );

        if (!locationExists) {
            filterForm.location_id = '';
        }

        mergeEmployeeOptions(props.employees ?? []);
    },
);

watch(
    () => [filterForm.location_id, filterForm.department_id],
    () => {
        mergeEmployeeOptions(props.employees ?? []);
    },
);

watch(
    () => filterForm.period,
    (period) => {
        if (period === 'custom') {
            return;
        }

        filterForm.from_date = props.period?.from_date ?? filterForm.from_date;
        filterForm.to_date = props.period?.to_date ?? filterForm.to_date;
    },
);

const summaryCards = computed(() => [
    { label: 'Dias asistidos', value: summary.value.days_attended ?? 0, tone: 'emerald' },
    { label: 'Faltas', value: summary.value.absences ?? 0, tone: 'rose' },
    { label: 'Retardos', value: summary.value.late_days ?? 0, tone: 'amber' },
    { label: 'Incompletas', value: summary.value.incomplete_days ?? 0, tone: 'amber' },
    { label: 'Horas trabajadas', value: summary.value.worked_hours ?? '0h 00m', tone: 'slate' },
    { label: 'Cobertura', value: summary.value.coverage_label ?? '0.0%', tone: 'emerald' },
]);
</script>

<template>
    <Head title="Tarjeta de asistencia" />

    <AuthenticatedLayout content-overflow-visible>
        <template #header>
            <div class="min-w-0">
                <h1 class="text-app truncate text-base font-semibold leading-tight sm:text-xl">
                    Tarjeta de asistencia
                </h1>
                <p class="hidden truncate text-xs text-slate-500 sm:block sm:text-sm">
                    Consulta operativa y RH por empleado, periodo y unidad.
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

            <section class="card min-w-0 p-4">
                <form class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyFilters">
                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Empresa
                        <SearchableSelect
                            v-model="filterForm.company_id"
                            :options="companySelectOptions"
                            placeholder="Todas"
                            search-placeholder="Buscar empresa..."
                            :searchable="true"
                            input-class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app"
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
                            input-class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Departamento
                        <SearchableSelect
                            v-model="filterForm.department_id"
                            :options="departmentSelectOptions"
                            placeholder="Todos"
                            search-placeholder="Buscar departamento..."
                            input-class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Empleado
                        <SearchableSelect
                            v-model="filterForm.employee_id"
                            :options="employeeSelectOptions"
                            placeholder="Selecciona empleado"
                            search-placeholder="Busca por nombre, apellido, clave o Fortia ID"
                            :disabled="loading"
                            :loading="employeeSearchLoading"
                            input-class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app"
                            @search-change="queueEmployeeSearch"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Periodo
                        <select
                            v-model="filterForm.period"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app"
                        >
                            <option v-for="option in periodOptions" :key="option.key" :value="option.key">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Fecha inicio
                        <input
                            v-model="filterForm.from_date"
                            type="date"
                            :disabled="!isCustomPeriod"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app"
                        />
                    </label>

                    <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:tracking-[0.3em]">
                        Fecha fin
                        <input
                            v-model="filterForm.to_date"
                            type="date"
                            :disabled="!isCustomPeriod"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm text-app"
                        />
                    </label>

                    <div class="flex min-w-0 flex-col gap-2 sm:col-span-2 sm:flex-row sm:flex-wrap sm:items-end sm:justify-end xl:col-span-1 xl:self-end">
                        <button
                            type="submit"
                            class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-white sm:w-auto sm:tracking-[0.3em]"
                            :disabled="loading"
                        >
                            <span v-if="loading">Consultando...</span>
                            <span v-else>Aplicar filtros</span>
                        </button>
                        <button
                            type="button"
                            class="w-full rounded-2xl border border-app px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.15em] text-muted sm:w-auto sm:tracking-[0.3em]"
                            @click="clearFilters"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <div class="mt-4 flex min-w-0 flex-col gap-2 border-t border-slate-100 pt-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                    <p class="break-words">
                        Periodo operativo: <span class="font-semibold text-slate-700">{{ period.display }}</span>
                    </p>
                    <p class="break-words">
                        Horarios mostrados en {{ timezone.label?.toLowerCase?.() ?? 'hora centro de Mexico' }}.
                    </p>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-[1.7fr_1fr]">
                <article class="card-record bg-gradient-to-br from-indigo-50 via-white to-white p-5 dark:from-indigo-950/40 dark:via-slate-900 dark:to-slate-900">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-indigo-500">
                                Tarjeta activa
                            </p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-900 dark:text-slate-100">
                                {{ selectedEmployee?.name ?? 'Selecciona un empleado' }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                {{ selectedEmployee?.company ?? 'Filtra por empresa, unidad o departamento para ubicar al colaborador.' }}
                            </p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                {{ selectedEmployee?.location ?? 'La tarjeta se calcula con horario operativo de la zona centro.' }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a
                                v-if="canExport && selectedEmployee"
                                :href="exportUrl"
                                class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.3em] text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                            >
                                Exportar Excel
                            </a>
                            <div
                                v-else-if="canExport"
                                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.3em] text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                            >
                                Selecciona empleado para exportar
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 h-3 overflow-hidden rounded-full bg-slate-100">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-indigo-500"
                            :style="{ width: `${summary.coverage_percentage ?? 0}%` }"
                        />
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <article
                            v-for="cardItem in summaryCards"
                            :key="cardItem.label"
                            class="card-subtle px-4 py-3"
                        >
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400 dark:text-slate-400">
                                {{ cardItem.label }}
                            </p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-slate-100">
                                {{ cardItem.value }}
                            </p>
                        </article>
                    </div>
                </article>

                <article class="card-record p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400 dark:text-slate-400">
                        Resumen del periodo
                    </p>
                    <dl class="mt-4 space-y-4 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Ultima asistencia</dt>
                            <dd class="text-right font-semibold text-slate-800 dark:text-slate-100">
                                {{ summary.last_attendance_display ?? 'Sin registros' }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Cobertura real</dt>
                            <dd class="text-right font-semibold text-slate-800 dark:text-slate-100">
                                {{ summary.coverage_label ?? '0.0%' }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Dias de descanso</dt>
                            <dd class="text-right font-semibold text-slate-800 dark:text-slate-100">
                                {{ summary.rest_days ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Empleados en alcance</dt>
                            <dd class="text-right font-semibold text-slate-800 dark:text-slate-100">
                                {{ employeeScope.matching_count ?? 0 }}
                            </dd>
                        </div>
                    </dl>

                    <div class="card-subtle mt-5 px-4 py-3 text-sm text-slate-500 dark:text-slate-300">
                        <p class="font-semibold text-slate-700 dark:text-slate-100">Lectura operativa</p>
                        <p class="mt-1">
                            {{
                                selectedEmployee
                                    ? `${summary.days_attended ?? 0} dias con marca dentro del periodo ${period.label?.toLowerCase?.() ?? ''}.`
                                    : `${employeeScope.matching_count ?? 0} empleados activos coinciden con los filtros actuales.`
                            }}
                        </p>
                    </div>
                </article>
            </section>

            <EmptyState
                v-if="requiresEmployee"
                :title="card.empty_state?.title ?? 'Selecciona un empleado'"
                :message="card.empty_state?.message ?? 'Acota filtros y elige un colaborador para generar la tarjeta.'"
            />

            <section v-else class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Detalle diario</h3>
                        <p class="text-sm text-slate-500">
                            {{ rows.length }} dias calculados para {{ period.label?.toLowerCase?.() ?? 'el periodo seleccionado' }}.
                        </p>
                    </div>
                </div>

                <div class="space-y-3 lg:hidden">
                    <article
                        v-for="row in rows"
                        :key="`mobile-${row.date}`"
                        class="card-record p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ row.date_display }}</p>
                                <p class="text-xs uppercase tracking-[0.25em] text-slate-400">{{ row.day }}</p>
                            </div>
                            <span
                                class="rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="statusToneClass(row.status_tone)"
                            >
                                {{ row.status_label }}
                            </span>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <p class="text-slate-400">Entrada</p>
                                <p class="font-semibold text-slate-800">{{ row.entry_display }}</p>
                            </div>
                            <div>
                                <p class="text-slate-400">Salida</p>
                                <p class="font-semibold text-slate-800">{{ row.exit_display }}</p>
                            </div>
                            <div>
                                <p class="text-slate-400">Horas</p>
                                <p class="font-semibold text-slate-800">{{ row.worked_hours_display }}</p>
                            </div>
                            <div>
                                <p class="text-slate-400">Metodo</p>
                                <p class="font-semibold text-slate-800">{{ row.method_label }}</p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-2xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            <p>{{ row.incidence_label }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ row.observations }}</p>
                        </div>
                    </article>
                </div>

                <div class="card hidden overflow-x-auto lg:block">
                    <table class="w-full min-w-[72rem] divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-[0.3em] text-slate-400">
                            <tr>
                                <th class="px-4 py-3">Fecha</th>
                                <th class="px-4 py-3">Dia</th>
                                <th class="px-4 py-3">Entrada</th>
                                <th class="px-4 py-3">Salida</th>
                                <th class="px-4 py-3">Horas</th>
                                <th class="px-4 py-3">Retardo</th>
                                <th class="px-4 py-3">Metodo</th>
                                <th class="px-4 py-3">Estatus</th>
                                <th class="px-4 py-3">Observaciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in rows" :key="row.date" class="align-top hover:bg-slate-50">
                                <td class="px-4 py-3 font-semibold text-slate-900">{{ row.date_display }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ row.day }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ row.entry_display }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ row.exit_display }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ row.worked_hours_display }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ row.late_display }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-col gap-1">
                                        <span class="font-semibold text-slate-800">{{ row.method_label }}</span>
                                        <span class="text-xs text-slate-400">{{ row.source_label }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold"
                                        :class="statusToneClass(row.status_tone)"
                                    >
                                        {{ row.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-500">
                                    <p class="font-medium text-slate-700">{{ row.incidence_label }}</p>
                                    <p class="mt-1 max-w-[24rem] text-xs text-slate-500">{{ row.observations }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </AuthenticatedLayout>
</template>
