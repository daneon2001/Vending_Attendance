<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChartCard from '@/Components/ChartCard.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    initialFilters: {
        type: Object,
        default: () => ({}),
    },
    locations: {
        type: Array,
        default: () => [],
    },
    clocks: {
        type: Array,
        default: () => [],
    },
    timezone: {
        type: Object,
        default: () => ({
            name: 'America/Mexico_City',
            label: 'Hora centro de Mexico',
            note: 'Horarios mostrados en hora centro de Mexico.',
        }),
    },
});

const AUTO_REFRESH_MS = 60000;

const filters = reactive({
    range: props.initialFilters.range ?? 'today',
    from_date: props.initialFilters.from_date ?? '',
    to_date: props.initialFilters.to_date ?? '',
    unit_id: props.initialFilters.unit_id ?? '',
    clock_id: props.initialFilters.clock_id ?? '',
});

const rangeOptions = [
    { value: 'today', label: 'Hoy' },
    { value: 'yesterday', label: 'Ayer' },
    { value: 'current_week', label: 'Semana actual' },
    { value: 'fortnight', label: 'Quincena' },
    { value: 'custom', label: 'Rango personalizado' },
];

const payload = ref(null);
const loading = ref(false);
const error = ref('');
const refreshStamp = ref(null);
let refreshTimer = null;

const numberFormatter = new Intl.NumberFormat('es-MX');
const percentFormatter = new Intl.NumberFormat('es-MX', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
});
const relativeTimeFormatter = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });

const emptyPayload = {
    filters: {
        range: 'today',
        from_date: '',
        to_date: '',
        unit_id: null,
        clock_id: null,
    },
    locations: [],
    global: {
        active_employees: 0,
        attended: 0,
        pending: 0,
        coverage_percent: 0,
        total_checks: 0,
        entries: 0,
        exits: 0,
        unknown: 0,
        first_check_at: null,
        last_check_at: null,
        clocks_total: 0,
        clocks_active: 0,
        clocks_online: 0,
        clocks_offline: 0,
        clocks_stale: 0,
        last_heartbeat_at: null,
        operational_status: 'nodata',
        operational_label: 'Sin relojes',
    },
    hourly_activity: [],
    clock_ranking: [],
    alerts: [],
    charts: {
        attendance_by_location: { labels: [], datasets: [] },
        checks_by_hour: { labels: [], datasets: [] },
        clock_status: { labels: [], datasets: [] },
        entries_vs_exits: { labels: [], datasets: [] },
    },
    timezone: props.timezone,
    meta: {
        generated_at: null,
        latest_check_at: null,
    },
    empty: false,
    message: null,
};

const summary = computed(() => payload.value ?? emptyPayload);
const timezoneName = computed(() => summary.value.timezone?.name ?? props.timezone.name);
const timezoneNote = computed(() => summary.value.timezone?.note ?? props.timezone.note);
const globalSummary = computed(() => summary.value.global ?? emptyPayload.global);
const locationCards = computed(() => summary.value.locations ?? []);
const hourlyActivity = computed(() => summary.value.hourly_activity ?? []);
const clockRanking = computed(() => summary.value.clock_ranking ?? []);
const alerts = computed(() => summary.value.alerts ?? []);
const hasCustomRange = computed(() => filters.range === 'custom');

const unitOptions = computed(() => props.locations ?? []);
const filteredClockOptions = computed(() => {
    const currentUnitId = filters.unit_id ? Number(filters.unit_id) : null;

    if (!currentUnitId) {
        return props.clocks;
    }

    return props.clocks.filter((clock) => Number(clock.location_id) === currentUnitId);
});

watch(
    () => filters.unit_id,
    () => {
        if (!filters.clock_id) {
            return;
        }

        const isClockAvailable = filteredClockOptions.value.some((clock) => Number(clock.id) === Number(filters.clock_id));

        if (!isClockAvailable) {
            filters.clock_id = '';
        }
    },
);

const buildParams = () => ({
    range: filters.range,
    from_date: filters.from_date || undefined,
    to_date: filters.to_date || undefined,
    unit_id: filters.unit_id || undefined,
    clock_id: filters.clock_id || undefined,
});

const syncFiltersFromPayload = (incomingFilters = {}) => {
    filters.range = incomingFilters.range ?? filters.range;
    filters.from_date = incomingFilters.from_date ?? filters.from_date;
    filters.to_date = incomingFilters.to_date ?? filters.to_date;
    filters.unit_id = incomingFilters.unit_id ?? '';
    filters.clock_id = incomingFilters.clock_id ?? '';
};

const fetchDashboard = async ({ silent = false } = {}) => {
    if (!silent) {
        loading.value = true;
    }

    error.value = '';

    try {
        const { data } = await axios.get(route('dashboard.corporativo-reclutamiento.summary'), {
            params: buildParams(),
        });

        payload.value = data;
        syncFiltersFromPayload(data.filters ?? {});
        refreshStamp.value = new Date().toISOString();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message ?? 'No se pudo cargar el dashboard.';
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    fetchDashboard();
};

const clearAutoRefresh = () => {
    if (refreshTimer && typeof window !== 'undefined') {
        window.clearInterval(refreshTimer);
        refreshTimer = null;
    }
};

const initAutoRefresh = () => {
    clearAutoRefresh();

    if (typeof window === 'undefined') {
        return;
    }

    refreshTimer = window.setInterval(() => {
        fetchDashboard({ silent: true });
    }, AUTO_REFRESH_MS);
};

const formatNumber = (value) => numberFormatter.format(value ?? 0);
const formatPercent = (value) => `${percentFormatter.format(value ?? 0)}%`;

const formatDateTime = (value) => {
    if (!value) return 'Sin datos';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Sin datos';

    return date.toLocaleString('es-MX', {
        timeZone: timezoneName.value,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
};

const formatRelative = (value) => {
    if (!value) return 'Sin datos';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Sin datos';

    const diffMinutes = Math.round((date.getTime() - Date.now()) / 60000);

    if (Math.abs(diffMinutes) < 60) {
        return relativeTimeFormatter.format(diffMinutes, 'minute');
    }

    const diffHours = Math.round(diffMinutes / 60);

    if (Math.abs(diffHours) < 24) {
        return relativeTimeFormatter.format(diffHours, 'hour');
    }

    return relativeTimeFormatter.format(Math.round(diffHours / 24), 'day');
};

const statusBadgeClass = (status) => {
    switch (status) {
        case 'normal':
        case 'online':
            return 'bg-emerald-100 text-emerald-700';
        case 'warning':
        case 'stale':
            return 'bg-amber-100 text-amber-700';
        case 'critical':
        case 'offline':
            return 'bg-rose-100 text-rose-700';
        default:
            return 'bg-slate-100 text-slate-600';
    }
};

const alertCardClass = (level) => {
    switch (level) {
        case 'critical':
            return 'border-rose-200 bg-rose-50';
        case 'warning':
            return 'border-amber-200 bg-amber-50';
        default:
            return 'border-sky-200 bg-sky-50';
    }
};

const globalCards = computed(() => [
    {
        key: 'coverage',
        label: 'Cobertura asistencia',
        value: formatPercent(globalSummary.value.coverage_percent),
        hint: `${formatNumber(globalSummary.value.attended)} / ${formatNumber(globalSummary.value.active_employees)} colaboradores`,
    },
    {
        key: 'checks',
        label: 'Total checadas',
        value: formatNumber(globalSummary.value.total_checks),
        hint: `Entradas ${formatNumber(globalSummary.value.entries)} · Salidas ${formatNumber(globalSummary.value.exits)}`,
    },
    {
        key: 'pending',
        label: 'Empleados pendientes',
        value: formatNumber(globalSummary.value.pending),
        hint: globalSummary.value.last_check_at ? `Ultima checada ${formatRelative(globalSummary.value.last_check_at)}` : 'Sin ultima checada',
    },
    {
        key: 'clocks',
        label: 'Relojes en linea',
        value: formatNumber(globalSummary.value.clocks_online),
        hint: `${formatNumber(globalSummary.value.clocks_offline)} offline · ${formatNumber(globalSummary.value.clocks_stale)} con rezago`,
    },
]);

const attendanceByLocationChart = computed(() => summary.value.charts?.attendance_by_location ?? emptyPayload.charts.attendance_by_location);
const hourlyChart = computed(() => summary.value.charts?.checks_by_hour ?? emptyPayload.charts.checks_by_hour);
const clockStatusChart = computed(() => summary.value.charts?.clock_status ?? emptyPayload.charts.clock_status);
const entriesVsExitsChart = computed(() => summary.value.charts?.entries_vs_exits ?? emptyPayload.charts.entries_vs_exits);

const chartBarOptions = {
    responsive: true,
    plugins: {
        legend: { position: 'bottom' },
    },
    scales: {
        y: {
            beginAtZero: true,
        },
    },
};

const chartLineOptions = {
    responsive: true,
    plugins: {
        legend: { position: 'bottom' },
    },
    scales: {
        y: {
            beginAtZero: true,
        },
    },
};

const exportUrl = (format) => route('dashboard.corporativo-reclutamiento.export', {
    ...buildParams(),
    format,
});

onMounted(() => {
    fetchDashboard();
    initAutoRefresh();
});

onBeforeUnmount(() => {
    clearAutoRefresh();
});
</script>

<template>
    <Head title="Dashboard Corporativo y Reclutamiento" />

    <AuthenticatedLayout>
        <template #header>
            Dashboard Corporativo y Reclutamiento
        </template>

        <div class="space-y-6">
            <section class="overflow-hidden rounded-[2rem] border border-slate-200 bg-[radial-gradient(circle_at_top_left,_rgba(15,118,110,0.12),_transparent_36%),linear-gradient(135deg,_#fff8ef,_#ffffff_38%,_#f0fdf4)] px-6 py-6">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.32em] text-slate-500">
                            Direccion y seguimiento operativo
                        </p>
                        <h1 class="mt-2 text-3xl font-semibold text-slate-900">
                            Dashboard Corporativo y Reclutamiento
                        </h1>
                        <p class="mt-2 text-sm text-slate-600">
                            Seguimiento de checadas y operacion de relojes para unidades codigo 87 y 171.
                        </p>
                        <p class="mt-3 text-xs font-medium uppercase tracking-[0.22em] text-slate-500">
                            {{ timezoneNote }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:min-w-[26rem]">
                        <div class="rounded-3xl border border-white/70 bg-white/90 px-4 py-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">
                                Ultima actualizacion
                            </p>
                            <p class="mt-2 text-lg font-semibold text-slate-900">
                                {{ globalSummary.total_checks > 0 ? formatRelative(summary.meta?.generated_at) : 'Sin actividad' }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                Auto refresh cada 60 s
                            </p>
                        </div>
                        <div class="rounded-3xl border border-white/70 bg-white/90 px-4 py-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">
                                Estado operativo
                            </p>
                            <p class="mt-2 inline-flex rounded-full px-3 py-1 text-sm font-semibold" :class="statusBadgeClass(globalSummary.operational_status)">
                                {{ globalSummary.operational_label }}
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Ultimo heartbeat {{ formatRelative(globalSummary.last_heartbeat_at) }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                <div class="grid gap-4 xl:grid-cols-[1.2fr_1fr_1fr_1fr_auto]">
                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Rango</span>
                        <select v-model="filters.range" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-900 focus:border-teal-500 focus:outline-none focus:ring-0">
                            <option v-for="option in rangeOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>

                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Desde</span>
                        <input v-model="filters.from_date" :disabled="!hasCustomRange" type="date" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-900 disabled:bg-slate-50 disabled:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-0">
                    </label>

                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Hasta</span>
                        <input v-model="filters.to_date" :disabled="!hasCustomRange" type="date" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-900 disabled:bg-slate-50 disabled:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-0">
                    </label>

                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Unidad</span>
                        <select v-model="filters.unit_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-900 focus:border-teal-500 focus:outline-none focus:ring-0">
                            <option value="">Todas</option>
                            <option v-for="location in unitOptions" :key="location.id" :value="location.id">
                                {{ location.label }}
                            </option>
                        </select>
                    </label>

                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Reloj</span>
                        <select v-model="filters.clock_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-900 focus:border-teal-500 focus:outline-none focus:ring-0">
                            <option value="">Todos</option>
                            <option v-for="clock in filteredClockOptions" :key="clock.id" :value="clock.id">
                                {{ clock.label }}
                            </option>
                        </select>
                    </label>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" class="inline-flex items-center rounded-full bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700" @click="applyFilters">
                        Actualizar
                    </button>
                    <a :href="exportUrl('xlsx')" class="inline-flex items-center rounded-full border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700">
                        Exportar Excel
                    </a>
                    <a :href="exportUrl('csv')" class="inline-flex items-center rounded-full border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-amber-300 hover:bg-amber-50 hover:text-amber-700">
                        Exportar CSV
                    </a>
                </div>
            </section>

            <div v-if="error" class="rounded-3xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-700">
                {{ error }}
            </div>

            <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div v-for="index in 4" :key="index" class="h-32 animate-pulse rounded-[1.75rem] bg-slate-100" />
            </div>

            <section v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article v-for="card in globalCards" :key="card.key" class="rounded-[1.75rem] border border-slate-200 bg-white px-5 py-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">
                        {{ card.label }}
                    </p>
                    <p class="mt-3 text-3xl font-semibold text-slate-900">
                        {{ card.value }}
                    </p>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ card.hint }}
                    </p>
                </article>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <article v-for="location in locationCards" :key="location.id" class="rounded-[1.85rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                                Unidad
                            </p>
                            <h2 class="mt-1 text-2xl font-semibold text-slate-900">
                                {{ location.name }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                Codigo {{ location.code || location.fortia_location_id }}
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusBadgeClass(location.clocks.operational_status)">
                            {{ location.clocks.operational_label }}
                        </span>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-3xl bg-slate-50 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">Cobertura</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ formatPercent(location.summary.coverage_percent) }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ formatNumber(location.summary.attended) }} / {{ formatNumber(location.summary.active_employees) }}</p>
                        </div>
                        <div class="rounded-3xl bg-slate-50 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">Pendientes</p>
                            <p class="mt-2 text-2xl font-semibold text-amber-700">{{ formatNumber(location.summary.pending) }}</p>
                            <p class="mt-1 text-sm text-slate-500">Total checadas {{ formatNumber(location.summary.total_checks) }}</p>
                        </div>
                        <div class="rounded-3xl bg-slate-50 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">Online / Offline</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900">
                                {{ formatNumber(location.clocks.online) }} / {{ formatNumber(location.clocks.offline) }}
                            </p>
                            <p class="mt-1 text-sm text-slate-500">Rezago {{ formatNumber(location.clocks.stale) }}</p>
                        </div>
                        <div class="rounded-3xl bg-slate-50 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">Ultima checada</p>
                            <p class="mt-2 text-lg font-semibold text-slate-900">{{ formatRelative(location.summary.last_check_at) }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ formatDateTime(location.summary.last_check_at) }}</p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-slate-200 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Entradas</p>
                            <p class="mt-2 text-xl font-semibold text-teal-700">{{ formatNumber(location.summary.entries) }}</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Salidas</p>
                            <p class="mt-2 text-xl font-semibold text-rose-700">{{ formatNumber(location.summary.exits) }}</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Heartbeat</p>
                            <p class="mt-2 text-sm font-semibold text-slate-900">{{ formatRelative(location.clocks.last_heartbeat_at) }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ formatDateTime(location.clocks.last_heartbeat_at) }}</p>
                        </div>
                    </div>
                </article>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <ChartCard
                    title="Asistencia por unidad"
                    description="Comparativo ejecutivo"
                    type="bar"
                    :dataset="attendanceByLocationChart"
                    :options="chartBarOptions"
                    height-class="h-64"
                />
                <ChartCard
                    title="Checadas por hora"
                    description="Entradas, salidas y total"
                    :dataset="hourlyChart"
                    :options="chartLineOptions"
                    height-class="h-64"
                />
                <ChartCard
                    title="Estado de relojes"
                    description="Pulso de conectividad"
                    type="doughnut"
                    :dataset="clockStatusChart"
                    height-class="h-64"
                />
                <ChartCard
                    title="Entradas vs salidas"
                    description="Balance por unidad"
                    type="bar"
                    :dataset="entriesVsExitsChart"
                    :options="chartBarOptions"
                    height-class="h-64"
                />
            </section>

            <section class="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
                <article class="rounded-[1.85rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                                Ranking de checadores
                            </p>
                            <h2 class="mt-1 text-2xl font-semibold text-slate-900">
                                Actividad por reloj
                            </h2>
                        </div>
                        <span class="text-sm text-slate-500">
                            {{ formatNumber(clockRanking.length) }} reloj(es)
                        </span>
                    </div>

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Unidad</th>
                                    <th class="px-4 py-3">Reloj</th>
                                    <th class="px-4 py-3">Serie</th>
                                    <th class="px-4 py-3">Estado</th>
                                    <th class="px-4 py-3">Ultimo heartbeat</th>
                                    <th class="px-4 py-3 text-right">Checadas</th>
                                    <th class="px-4 py-3">Ultima checada</th>
                                    <th class="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="clock in clockRanking" :key="clock.id" class="border-t border-slate-100">
                                    <td class="px-4 py-3 text-slate-700">{{ clock.location_name }}</td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-900">{{ clock.clock_name }}</p>
                                        <p class="text-xs text-slate-500">{{ clock.last_status_message || 'Sin mensaje de estado' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">{{ clock.serial_number || 'Sin serie' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusBadgeClass(clock.status)">
                                            {{ clock.status_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">
                                        <p>{{ formatRelative(clock.last_heartbeat_at) }}</p>
                                        <p class="text-xs text-slate-500">{{ formatDateTime(clock.last_heartbeat_at) }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ formatNumber(clock.total_checks) }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ formatDateTime(clock.last_check_at) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a :href="clock.clock_catalog_url" class="inline-flex rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700">
                                            Ver catalogo
                                        </a>
                                    </td>
                                </tr>
                                <tr v-if="clockRanking.length === 0">
                                    <td colspan="8" class="px-4 py-6 text-center text-sm text-slate-500">
                                        No hay relojes dentro del filtro seleccionado.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="rounded-[1.85rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                                Alertas operativas
                            </p>
                            <h2 class="mt-1 text-2xl font-semibold text-slate-900">
                                Prioridad del dia
                            </h2>
                        </div>
                        <span class="text-sm text-slate-500">
                            {{ formatNumber(alerts.length) }} alerta(s)
                        </span>
                    </div>

                    <div class="mt-5 space-y-3">
                        <article v-for="alert in alerts" :key="alert.id" class="rounded-3xl border px-4 py-4" :class="alertCardClass(alert.level)">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">
                                        {{ alert.level }}
                                    </p>
                                    <h3 class="mt-1 text-base font-semibold text-slate-900">
                                        {{ alert.title }}
                                    </h3>
                                    <p class="mt-2 text-sm text-slate-700">
                                        {{ alert.message }}
                                    </p>
                                </div>
                            </div>
                        </article>

                        <div v-if="alerts.length === 0" class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                            Sin alertas operativas para el rango actual.
                        </div>
                    </div>
                </article>
            </section>

            <section v-if="summary.empty || summary.message" class="rounded-3xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-600">
                {{ summary.message || 'No hay datos operativos para el rango seleccionado.' }}
            </section>
        </div>
    </AuthenticatedLayout>
</template>
