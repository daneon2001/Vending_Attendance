<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChartCard from '@/Components/ChartCard.vue';
import Toast from '@/Components/Toast.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    locations: {
        type: Array,
        default: () => [],
    },
});

const padNumber = (value) => String(value).padStart(2, '0');
const formatInputDateValue = (date) => `${date.getFullYear()}-${padNumber(date.getMonth() + 1)}-${padNumber(date.getDate())}`;
const formatRequestDateValue = (date) => `${date.getFullYear()}-${padNumber(date.getMonth() + 1)}-${padNumber(date.getDate())} ${padNumber(date.getHours())}:${padNumber(date.getMinutes())}:${padNumber(date.getSeconds())}`;
const startOfDayDate = (date) => {
    const copy = new Date(date);
    copy.setHours(0, 0, 0, 0);
    return copy;
};
const endOfDayDate = (date) => {
    const copy = new Date(date);
    copy.setHours(23, 59, 59, 999);
    return copy;
};
const addDaysToDate = (date, days) => {
    const copy = new Date(date);
    copy.setDate(copy.getDate() + days);
    return copy;
};
const parseDateInputValue = (value) => {
    if (!value) {
        return null;
    }
    const parts = value.split('-').map(Number);
    if (parts.length !== 3 || parts.some((part) => Number.isNaN(part))) {
        return null;
    }
    const [year, month, day] = parts;
    return new Date(year, month - 1, day);
};
const normalizeRange = (rangeKey, fromInput = '', toInput = '') => {
    const today = new Date();
    let start;
    let end;

    if (rangeKey === 'custom') {
        const startCandidate = parseDateInputValue(fromInput) ?? parseDateInputValue(toInput) ?? today;
        const endCandidate = parseDateInputValue(toInput) ?? parseDateInputValue(fromInput) ?? today;
        start = startOfDayDate(startCandidate);
        end = endOfDayDate(endCandidate);

        if (start.getTime() > end.getTime()) {
            const fixedStart = startOfDayDate(endCandidate);
            const fixedEnd = endOfDayDate(startCandidate);
            start = fixedStart;
            end = fixedEnd;
        }
    } else if (rangeKey === '30d') {
        start = startOfDayDate(addDaysToDate(today, -29));
        end = endOfDayDate(today);
    } else if (rangeKey === 'today') {
        start = startOfDayDate(today);
        end = endOfDayDate(today);
    } else {
        start = startOfDayDate(addDaysToDate(today, -6));
        end = endOfDayDate(today);
    }

    return {
        from: formatRequestDateValue(start),
        to: formatRequestDateValue(end),
        startDate: start,
        endDate: end,
        startInput: formatInputDateValue(start),
        endInput: formatInputDateValue(end),
    };
};

const rangeOptions = [
    { value: 'today', label: 'Hoy' },
    { value: '7d', label: 'Últimos 7 días' },
    { value: '30d', label: 'Últimos 30 días' },
    { value: 'custom', label: 'Personalizado' },
];

const filters = reactive({
    range: 'today',
    from: '',
    to: '',
    location_id: null,
});

const ensureRangeInputs = (rangeKey) => {
    const normalized = normalizeRange(rangeKey, filters.from, filters.to);
    filters.from = normalized.startInput;
    filters.to = normalized.endInput;
    return normalized;
};

const summary = ref(null);
const loading = ref(false);
const detailHighlight = ref(null);
const summaryCache = new Map();
const isMounted = ref(false);

const toast = reactive({
    show: false,
    type: 'info',
    title: '',
    message: '',
    duration: 5000,
});

const locationOptions = computed(() => props.locations ?? []);

const hasCustomRange = computed(() => filters.range === 'custom');

const lastUpdatedLabel = computed(() => {
    if (!summary.value?.refreshed_at) return '--';
    return formatDateTime(summary.value.refreshed_at);
});

const normalizedLocationId = computed(() => {
    if (filters.location_id === null || filters.location_id === undefined || filters.location_id === '') {
        return null;
    }

    const parsed = Number(filters.location_id);

    if (Number.isNaN(parsed) || parsed <= 0) {
        return null;
    }

    return parsed;
});

const selectedLocationLabel = computed(() => {
    const currentId = normalizedLocationId.value;
    if (!currentId) return 'Todas las sucursales';
    const location = locationOptions.value.find((item) => item.id === currentId);
    return location ? `${location.name}${location.code ? ` · ${location.code}` : ''}` : 'Sucursal seleccionada';
});

const selectedRangeLabel = computed(() => {
    switch (filters.range) {
        case 'today':
            return 'Hoy';
        case '30d':
            return 'Últimos 30 días';
        case 'custom':
            if (filters.from && filters.to) {
                return `${formatDate(filters.from)} – ${formatDate(filters.to)}`;
            }
            return 'Rango personalizado';
        default:
            return 'Últimos 7 días';
    }
});

const showToast = ({ type = 'info', title = '', message = '', duration }) => {
    toast.type = type;
    toast.title = title;
    toast.message = message;
    toast.duration = duration ?? (type === 'error' ? 9000 : 5000);
    toast.show = true;
};

const closeToast = () => {
    toast.show = false;
};

const formatNumber = (value) => {
    if (value === null || value === undefined) {
        return '0';
    }
    return new Intl.NumberFormat('es-MX').format(value);
};

const formatDate = (value) => {
    if (!value) return '--';
    const date = new Date(value);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
};

const formatDateTime = (value) => {
    if (!value) return '--';
    const date = new Date(value);
    return date.toLocaleString('es-MX', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
};

const buildSummaryParams = () => {
    const normalized = normalizeRange(filters.range, filters.from, filters.to);
    const params = {
        range: filters.range,
        from: normalized.from,
        to: normalized.to,
    };

    if (normalizedLocationId.value) {
        params.location_id = normalizedLocationId.value;
    }

    return { params, normalized };
};

const fetchSummary = async ({ force = false } = {}) => {
    if (hasCustomRange.value && (!filters.from || !filters.to)) {
        showToast({
            type: 'error',
            title: 'Selecciona el rango',
            message: 'Debes elegir fecha inicio y fin para aplicar el filtro personalizado.',
        });
        return;
    }

    const { params, normalized } = buildSummaryParams();
    const cacheKey = JSON.stringify({
        range: filters.range,
        location_id: params.location_id ?? null,
        from: normalized.from,
        to: normalized.to,
    });

    if (!force && summaryCache.has(cacheKey)) {
        summary.value = summaryCache.get(cacheKey);
        detailHighlight.value = null;
        return;
    }

    loading.value = true;
    try {
        const { data } = await axios.get(route('dashboard.summary'), {
            params,
        });

        summaryCache.set(cacheKey, data);
        summary.value = data;
        detailHighlight.value = null;
    } catch (error) {
        showToast({
            type: 'error',
            title: 'No se pudo actualizar',
            message: error.response?.data?.message ?? 'Intenta de nuevo en unos minutos.',
        });
    } finally {
        loading.value = false;
    }
};

const refreshSummary = () => fetchSummary({ force: true });

onMounted(() => {
    ensureRangeInputs(filters.range);
    isMounted.value = true;
    fetchSummary();
});

watch(
    () => filters.range,
    (value, previous) => {
        if (value !== 'custom') {
            ensureRangeInputs(value);
        } else if (!filters.from || !filters.to) {
            const normalizedToday = normalizeRange('today');
            filters.from = normalizedToday.startInput;
            filters.to = normalizedToday.endInput;
        }

        if (isMounted.value && value !== 'custom') {
            fetchSummary();
        }

        if (previous === 'custom' && value !== 'custom') {
            detailHighlight.value = null;
        }
    },
);

watch(
    () => normalizedLocationId.value,
    () => {
        if (isMounted.value) {
            fetchSummary();
        }
    },
);

const kpiCards = computed(() => {
    const data = summary.value?.kpis ?? {};

    return [
        {
            id: 'attendance',
            title: 'Checadas registradas',
            value: formatNumber(data.attendance_total ?? 0),
            hint: `Promedio ${data.attendance_average ?? 0} por día`,
            accent: 'from-indigo-50 to-white dark:from-indigo-900/40 dark:to-slate-900',
        },
        {
            id: 'employees',
            title: 'Empleados activos',
            value: formatNumber(data.employees_active ?? 0),
            hint: `${formatNumber(data.employees_total ?? 0)} en el catálogo`,
            accent: 'from-emerald-50 to-white dark:from-emerald-900/40 dark:to-slate-900',
        },
        {
            id: 'warning',
            title: 'Relojes con alertas',
            value: formatNumber(data.clocks_warning ?? 0),
            hint: `${formatNumber(data.clocks_online ?? 0)} en línea`,
            accent: 'from-amber-50 to-white dark:from-amber-900/40 dark:to-slate-900',
        },
        {
            id: 'offline',
            title: 'Relojes sin conexión',
            value: formatNumber(data.clocks_offline ?? 0),
            hint: 'Prioriza seguimiento con soporte',
            accent: 'from-rose-50 to-white dark:from-rose-900/40 dark:to-slate-900',
        },
    ];
});

const presenceChartData = computed(() => ({
    labels: summary.value?.presence_series?.labels ?? [],
    datasets: [
        {
            label: 'Personas presentes',
            data: summary.value?.presence_series?.values ?? [],
            fill: true,
            tension: 0.35,
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99, 102, 241, 0.15)',
            pointBackgroundColor: '#312e81',
        },
    ],
}));

const donutColors = ['#22c55e', '#f97316', '#64748b', '#0ea5e9'];

const employeeStatusData = computed(() => ({
    labels: summary.value?.employee_status?.labels ?? [],
    datasets: [
        {
            label: 'Colaboradores',
            data: summary.value?.employee_status?.values ?? [],
            backgroundColor: donutColors,
            borderWidth: 0,
        },
    ],
}));

const clockStatusData = computed(() => ({
    labels: summary.value?.clock_status?.labels ?? [],
    datasets: [
        {
            label: 'Relojes',
            data: summary.value?.clock_status?.values ?? [],
            backgroundColor: ['#22c55e', '#f97316', '#ef4444'],
            borderWidth: 0,
        },
    ],
}));

const branchChartData = computed(() => ({
    labels: summary.value?.top_branches?.labels ?? [],
    datasets: [
        {
            label:
                summary.value?.top_branches?.mode === 'incidents'
                    ? 'Incidencias'
                    : 'Checadas',
            data: summary.value?.top_branches?.values ?? [],
            backgroundColor: 'rgba(13, 148, 136, 0.7)',
            borderRadius: 12,
        },
    ],
}));

const branchChartOptions = computed(() => ({
    indexAxis: 'y',
}));

const detailCard = computed(() => {
    if (detailHighlight.value) {
        return detailHighlight.value;
    }

    return {
        title: 'Resumen del periodo',
        subtitle: `${selectedRangeLabel.value} · ${selectedLocationLabel.value}`,
        value: formatNumber(summary.value?.kpis?.attendance_total ?? 0),
        context: 'Checadas totales',
    };
});

const setPresenceDetail = (point) => {
    if (!point?.label) return;
    const amount = Number(point.value ?? 0);
    detailHighlight.value = {
        title: 'Detalle del día',
        subtitle: point.label,
        value: formatNumber(amount),
        context: `${amount} persona(s) presentes`,
    };
};

const setStatusDetail = (point, title) => {
    if (!point?.label) return;
    const amount = Number(point.value ?? 0);
    detailHighlight.value = {
        title,
        subtitle: point.label,
        value: formatNumber(amount),
        context: `${amount} registro(s)`,
    };
};

const setEmployeeStatusDetail = (point) => setStatusDetail(point, 'Estado de colaboradores');
const setClockStatusDetail = (point) => setStatusDetail(point, 'Estado de relojes');

const setBranchDetail = (point) => {
    if (!point?.label) return;
    const mode = summary.value?.top_branches?.mode === 'incidents' ? 'incidencia(s)' : 'checada(s)';
    const amount = Number(point.value ?? 0);
    detailHighlight.value = {
        title: 'Detalle sucursal',
        subtitle: point.label,
        value: formatNumber(amount),
        context: `${amount} ${mode}`,
    };
};

const clearDetail = () => {
    detailHighlight.value = null;
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-2xl font-semibold leading-tight">
                    Panel general
                </h1>
                <p class="text-sm text-muted">
                    Seguimiento diario de checadas, dispositivos y catálogos.
                </p>
            </div>
        </template>

        <section class="bg-app py-10">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-4 rounded-3xl border-app bg-white/80 p-4 shadow-sm ring-1 ring-transparent dark:bg-slate-900/70">
                    <div class="flex flex-wrap items-center gap-4 lg:flex-nowrap">
                        <div class="flex flex-1 flex-wrap items-center gap-3 text-sm">
                            <label class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                Rango
                                <select
                                    v-model="filters.range"
                                    class="rounded-2xl border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900"
                                >
                                    <option
                                        v-for="option in rangeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                            <div
                                v-if="hasCustomRange"
                                class="flex flex-wrap gap-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400"
                            >
                                <label class="flex items-center gap-2">
                                    Desde
                                    <input
                                        v-model="filters.from"
                                        type="date"
                                        class="rounded-2xl border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900"
                                    />
                                </label>
                                <label class="flex items-center gap-2">
                                    Hasta
                                    <input
                                        v-model="filters.to"
                                        type="date"
                                        class="rounded-2xl border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900"
                                    />
                                </label>
                                <button
                                    type="button"
                                    class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                                    :disabled="loading"
                                    @click="fetchSummary"
                                >
                                    Aplicar filtros
                                </button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            <label class="flex items-center gap-2">
                                Sucursal
                                <select
                                    v-model="filters.location_id"
                                    class="rounded-2xl border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900"
                                >
                                    <option value="">Todas</option>
                                    <option
                                        v-for="location in locationOptions"
                                        :key="location.id"
                                        :value="location.id"
                                    >
                                        {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                    </option>
                                </select>
                            </label>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-2xl border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 dark:border-indigo-500/40 dark:text-indigo-200 dark:hover:bg-indigo-900/40"
                            :disabled="loading"
                            @click="refreshSummary"
                        >
                            <span v-if="loading">Actualizando…</span>
                            <span v-else>Actualizar</span>
                            <span aria-hidden="true">↻</span>
                        </button>
                        <div class="min-w-[200px] flex-1 space-y-1 text-right lg:text-left">
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                Última actualización
                            </p>
                            <p class="text-app text-base font-semibold">
                                {{ lastUpdatedLabel }}
                            </p>
                            <p class="text-xs text-muted">
                                {{ selectedLocationLabel }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <article
                        v-for="card in kpiCards"
                        :key="card.id"
                        class="rounded-3xl border border-white/50 bg-gradient-to-br p-4 shadow-sm ring-1 ring-transparent dark:border-slate-800 dark:text-slate-100"
                        :class="card.accent"
                    >
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 dark:text-slate-300">
                            {{ card.title }}
                        </p>
                        <p class="mt-2 text-3xl font-semibold text-app">
                            <span v-if="!loading">
                                {{ card.value }}
                            </span>
                            <span v-else class="inline-block h-8 w-24 animate-pulse rounded-full bg-white/40 dark:bg-slate-800/80" />
                        </p>
                        <p class="text-sm text-muted">
                            {{ card.hint }}
                        </p>
                    </article>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <ChartCard
                        title="Asistencias por día"
                        description="Colaboradores con al menos una checada"
                        :dataset="presenceChartData"
                        :loading="loading && !summary"
                        @point-click="setPresenceDetail"
                    />
                    <ChartCard
                        title="Estado de empleados"
                        description="Activos vs bajas"
                        type="doughnut"
                        :dataset="employeeStatusData"
                        :options="{ plugins: { legend: { position: 'bottom' } } }"
                        :loading="loading && !summary"
                        @point-click="setEmployeeStatusDetail"
                    />
                    <ChartCard
                        title="Estado de relojes"
                        description="Monitoreo en tiempo real"
                        type="doughnut"
                        :dataset="clockStatusData"
                        :options="{ plugins: { legend: { position: 'bottom' } } }"
                        :loading="loading && !summary"
                        @point-click="setClockStatusDetail"
                    />
                    <ChartCard
                        title="Top sucursales"
                        :description="summary?.top_branches?.mode === 'incidents' ? 'Incidencias detectadas' : 'Volumen de checadas'"
                        type="bar"
                        :dataset="branchChartData"
                        :options="branchChartOptions"
                        :loading="loading && !summary"
                        empty-text="Sin registros para el rango"
                        @point-click="setBranchDetail"
                    />
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <article class="card flex items-center justify-between gap-6 px-6 py-5 lg:col-span-2">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                {{ detailCard.title }}
                            </p>
                            <h3 class="text-2xl font-semibold text-app">
                                {{ detailCard.value }}
                            </h3>
                            <p class="text-sm text-muted">
                                {{ detailCard.subtitle }}
                            </p>
                            <p class="text-xs text-muted">
                                {{ detailCard.context }}
                            </p>
                        </div>
                        <button
                            v-if="detailHighlight"
                            type="button"
                            class="rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800/70"
                            @click="clearDetail"
                        >
                            Limpiar selección
                        </button>
                    </article>
                    <article class="card px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            Contexto del periodo
                        </p>
                        <ul class="mt-4 space-y-3 text-sm text-muted">
                            <li class="flex items-center justify-between">
                                <span>Rango aplicado</span>
                                <strong class="text-app">{{ selectedRangeLabel }}</strong>
                            </li>
                            <li class="flex items-center justify-between">
                                <span>Sucursal</span>
                                <strong class="text-app">{{ selectedLocationLabel }}</strong>
                            </li>
                            <li class="flex items-center justify-between">
                                <span>Última actualización</span>
                                <strong class="text-app">{{ lastUpdatedLabel }}</strong>
                            </li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <Toast
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            :duration="toast.duration"
            @close="closeToast"
        />
    </AuthenticatedLayout>
</template>
