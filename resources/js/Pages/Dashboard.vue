<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChartCard from '@/Components/ChartCard.vue';
import Toast from '@/Components/Toast.vue';
import { hasChartData } from '@/utils/chart';
import { apiUrl } from '@/utils/url';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    locations: {
        type: Array,
        default: () => [],
    },
});

const rangeOptions = [
    { value: 'today', label: 'Hoy' },
    { value: '7d', label: 'Últimos 7 días' },
    { value: '30d', label: 'Últimos 30 días' },
    { value: 'custom', label: 'Personalizado' },
];

const filters = reactive({
    range: 'today',
    from_date: '',
    to_date: '',
    unit_id: '',
});

const summary = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const validationError = ref('');
const lastUpdated = ref('--');
const requestCounter = ref(0);
const isDev = import.meta.env.DEV;

const toast = reactive({
    show: false,
    type: 'info',
    title: '',
    message: '',
    duration: 5000,
});

const devLog = (...args) => {
    if (isDev) {
        // eslint-disable-next-line no-console
        console.debug('[Dashboard]', ...args);
    }
};

const locationOptions = computed(() => props.locations ?? []);
const hasCustomRange = computed(() => filters.range === 'custom');

const formatInputValue = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const parseDateInput = (value) => {
    if (!value) return null;
    const parsed = new Date(`${value}T00:00:00`);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const formatRequestDate = (date) => {
    if (!date) return '';
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
};

const applyRangeDefaults = (rangeValue) => {
    const today = new Date();
    let start = new Date(today);
    let end = new Date(today);

    if (rangeValue === '7d') {
        start.setDate(start.getDate() - 6);
    } else if (rangeValue === '30d') {
        start.setDate(start.getDate() - 29);
    }

    start.setHours(0, 0, 0, 0);
    end.setHours(23, 59, 59, 999);

    filters.from_date = formatInputValue(start);
    filters.to_date = formatInputValue(end);
};

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

const buildParams = () => {
    validationError.value = '';
    const params = { range: filters.range };

    if (filters.unit_id) {
        params.unit_id = filters.unit_id;
    }

    if (filters.range === 'custom') {
        const fromDate = parseDateInput(filters.from_date);
        const toDate = parseDateInput(filters.to_date);

        if (!fromDate || !toDate) {
            validationError.value = 'Debes capturar fecha inicial y final.';
            throw new Error(validationError.value);
        }

        if (fromDate > toDate) {
            validationError.value = 'La fecha inicial no puede ser mayor a la final.';
            throw new Error(validationError.value);
        }

        params.from_date = formatRequestDate(fromDate);
        params.to_date = formatRequestDate(toDate);
    }

    return params;
};

const fetchSummary = async () => {
    let params;
    try {
        params = buildParams();
        devLog('Parámetros', params);
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Rango inválido',
            message: error.message,
        });
        return;
    }

    const requestId = ++requestCounter.value;
    loading.value = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.get(apiUrl('/api/dashboard/summary'), { params });
        if (requestId !== requestCounter.value) {
            return;
        }
        summary.value = data;
        lastUpdated.value = data.meta?.generated_at_local ?? '--';
        errorMessage.value = '';

        if (data.empty && data.message) {
            showToast({
                type: 'info',
                title: 'Sin datos',
                message: data.message,
            });
        }

        devLog('Respuesta', data);
    } catch (error) {
        if (requestId !== requestCounter.value) {
            return;
        }

        if (error.response?.status === 422) {
            const errors = error.response?.data?.errors ?? {};
            validationError.value = Object.values(errors)[0]?.[0] ?? 'Datos inválidos.';
            errorMessage.value = validationError.value;
        } else {
            validationError.value = '';
            errorMessage.value = error.response?.data?.message ?? 'Error al cargar la información.';
        }

        showToast({
            type: 'error',
            title: 'No se pudo actualizar',
            message: errorMessage.value,
        });
    } finally {
        if (requestId === requestCounter.value) {
            loading.value = false;
        }
    }
};

const applyCustomRange = () => {
    if (!filters.from_date || !filters.to_date) {
        validationError.value = 'Debes capturar fecha inicial y final.';
        showToast({
            type: 'error',
            title: 'Selecciona el rango',
            message: validationError.value,
        });
        return;
    }

    const from = parseDateInput(filters.from_date);
    const to = parseDateInput(filters.to_date);
    if (!from || !to || from > to) {
        validationError.value = 'Revisa tus fechas. La inicial debe ser menor o igual a la final.';
        showToast({
            type: 'error',
            title: 'Rango inválido',
            message: validationError.value,
        });
        return;
    }

    fetchSummary();
};

watch(
    () => filters.range,
    (value) => {
        if (value !== 'custom') {
            validationError.value = '';
            applyRangeDefaults(value);
            fetchSummary();
        } else if (!filters.from_date || !filters.to_date) {
            applyRangeDefaults('today');
        }
    },
);

watch(
    () => filters.unit_id,
    () => {
        fetchSummary();
    },
);

onMounted(() => {
    applyRangeDefaults(filters.range);
    fetchSummary();
});

const summaryData = computed(() => summary.value ?? { meta: null, kpis: {}, charts: {} });
const summaryEmpty = computed(() => summary.value?.empty ?? false);
const emptyMessage = computed(() => summary.value?.message ?? 'Sin datos para el rango seleccionado.');
const chartsLoading = computed(() => loading.value && !summary.value);
const chartError = computed(() => (errorMessage.value ? errorMessage.value : null));

const kpiCards = computed(() => {
    const kpis = summary.value?.kpis ?? {};
    return [
        {
            id: 'checkins',
            title: 'Checadas registradas',
            value: kpis.checkins_total ?? 0,
            hint: 'Movimientos en el rango',
            accent: 'from-indigo-50 to-white dark:from-indigo-900/30 dark:to-slate-900',
        },
        {
            id: 'employees-active',
            title: 'Empleados activos',
            value: kpis.employees_active ?? 0,
            hint: 'Catálogo vivo',
            accent: 'from-emerald-50 to-white dark:from-emerald-900/30 dark:to-slate-900',
        },
        {
            id: 'clocks-warning',
            title: 'Relojes con alertas',
            value: kpis.clocks_with_alerts ?? 0,
            hint: 'Necesitan seguimiento',
            accent: 'from-amber-50 to-white dark:from-amber-900/30 dark:to-slate-900',
        },
        {
            id: 'clocks-offline',
            title: 'Relojes sin conexión',
            value: kpis.clocks_offline ?? 0,
            hint: 'Prioriza soporte',
            accent: 'from-rose-50 to-white dark:from-rose-900/30 dark:to-slate-900',
        },
    ];
});

const peopleChartData = computed(() => ({
    labels: summary.value?.charts?.people_present_by_day?.labels ?? [],
    datasets: [
        {
            label: 'Personas presentes',
            data: summary.value?.charts?.people_present_by_day?.values ?? [],
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99, 102, 241, 0.15)',
            tension: 0.35,
            fill: true,
        },
    ],
}));

const employeeStatusData = computed(() => ({
    labels: summary.value?.charts?.employees_status?.labels ?? [],
    datasets: [
        {
            label: 'Colaboradores',
            data: summary.value?.charts?.employees_status?.values ?? [],
            backgroundColor: ['#22c55e', '#e11d48'],
            borderWidth: 0,
        },
    ],
}));

const clockHealthData = computed(() => ({
    labels: summary.value?.charts?.clock_health?.labels ?? [],
    datasets: [
        {
            label: 'Relojes',
            data: summary.value?.charts?.clock_health?.values ?? [],
            backgroundColor: ['#22c55e', '#f97316', '#ef4444'],
            borderWidth: 0,
        },
    ],
}));

const chartKeys = reactive({
    people: 0,
    employeeStatus: 0,
    clockHealth: 0,
});

watch(peopleChartData, () => {
    chartKeys.people += 1;
    devLog('Dataset personas', peopleChartData.value);
}, { deep: true });

watch(employeeStatusData, () => {
    chartKeys.employeeStatus += 1;
    devLog('Dataset empleados', employeeStatusData.value);
}, { deep: true });

watch(clockHealthData, () => {
    chartKeys.clockHealth += 1;
    devLog('Dataset relojes', clockHealthData.value);
}, { deep: true });

const presenceHasData = computed(() => hasChartData(peopleChartData.value));
const employeeStatusHasData = computed(() => hasChartData(employeeStatusData.value));
const clockHealthHasData = computed(() => hasChartData(clockHealthData.value));

const lastRangeLabel = computed(() => {
    if (!summary.value?.meta) {
        return 'Rango seleccionado';
    }

    const meta = summary.value.meta;
    const from = meta.from ? new Date(meta.from) : null;
    const to = meta.to ? new Date(meta.to) : null;

    if (!from || !to) {
        return 'Rango seleccionado';
    }

    const format = (date) =>
        `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`;

    return `${format(from)} al ${format(to)}`;
});

const currentLocationLabel = computed(() => {
    if (!filters.unit_id) {
        return 'Todas las sucursales';
    }
    const location = locationOptions.value.find((loc) => String(loc.id) === String(filters.unit_id));
    if (!location) return 'Sucursal seleccionada';
    return `${location.name}${location.code ? ` (${location.code})` : ''}`;
});

const formatNumber = (value) => new Intl.NumberFormat('es-MX').format(value ?? 0);
</script>

<template>
    <Head title="Panel general" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-2xl font-semibold leading-tight">
                    Panel general
                </h1>
                <p class="text-sm text-muted">
                    Seguimiento consolidado de asistencias y dispositivos.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="card flex flex-col gap-4 px-4 py-4 sm:px-6">
                <div class="flex flex-wrap items-end gap-4">
                    <label class="flex w-full flex-col gap-2 text-xs font-semibold uppercase tracking-[0.3em] text-soft sm:w-auto">
                        Rango
                        <select
                            v-model="filters.range"
                            class="w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900 sm:w-auto"
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
                        class="grid w-full gap-3 text-xs font-semibold uppercase tracking-[0.3em] text-soft sm:grid-cols-2 lg:w-auto lg:grid-cols-[1fr_1fr_auto]"
                    >
                        <label class="flex flex-col gap-2 sm:flex-col">
                            Desde
                            <input
                                v-model="filters.from_date"
                                type="date"
                                class="w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900"
                            />
                        </label>
                        <label class="flex flex-col gap-2 sm:flex-col">
                            Hasta
                            <input
                                v-model="filters.to_date"
                                type="date"
                                class="w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900"
                            />
                        </label>
                        <button
                            type="button"
                            class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white shadow hover:bg-indigo-500 lg:w-auto"
                            :disabled="loading"
                            @click="applyCustomRange"
                        >
                            Aplicar
                        </button>
                    </div>

                    <label class="flex w-full flex-col gap-2 text-xs font-semibold uppercase tracking-[0.3em] text-soft sm:w-auto">
                        Sucursal
                        <select
                            v-model="filters.unit_id"
                            class="w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold dark:bg-slate-900 sm:w-auto"
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

                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 dark:border-indigo-500/40 dark:text-indigo-200 dark:hover:bg-indigo-900/40 sm:w-auto"
                        :disabled="loading"
                        @click="fetchSummary"
                    >
                        <span v-if="loading">Actualizando…</span>
                        <span v-else>Actualizar</span>
                        <span aria-hidden="true">↻</span>
                    </button>

                    <div class="min-w-0 w-full space-y-1 text-left sm:ml-auto sm:w-auto sm:min-w-[200px] sm:text-right">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                            Última actualización
                        </p>
                        <p class="text-app text-base font-semibold">
                            {{ lastUpdated }}
                        </p>
                        <p class="text-xs text-muted">
                            {{ currentLocationLabel }}
                        </p>
                    </div>
                </div>
                <p class="text-xs text-muted">
                    Intervalo aplicado: {{ lastRangeLabel }}
                </p>
                <p v-if="validationError" class="text-xs font-semibold text-rose-600">
                    {{ validationError }}
                </p>
            </div>

            <div
                v-if="errorMessage"
                class="card flex flex-wrap items-center justify-between gap-3 border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-100"
            >
                <span>{{ errorMessage }}</span>
                <button
                    type="button"
                    class="w-full rounded-2xl border border-rose-200 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-rose-600 hover:bg-rose-100 dark:border-rose-500/60 dark:hover:bg-rose-900/30 sm:w-auto"
                    @click="fetchSummary"
                >
                    Reintentar
                </button>
            </div>
            <div
                v-else-if="summaryEmpty"
                class="card border border-slate-100 bg-white/80 px-4 py-3 text-sm text-muted dark:border-slate-800 dark:bg-slate-900/40"
            >
                {{ emptyMessage }}
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="card in kpiCards"
                    :key="card.id"
                    class="rounded-3xl border border-white/50 bg-gradient-to-br p-4 shadow-sm ring-1 ring-transparent dark:border-slate-800"
                    :class="card.accent"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 dark:text-slate-300">
                        {{ card.title }}
                    </p>
                    <p class="mt-3 text-3xl font-semibold text-app">
                        <span v-if="!loading">{{ formatNumber(card.value) }}</span>
                        <span v-else class="inline-block h-8 w-24 animate-pulse rounded-full bg-white/40 dark:bg-slate-800/80" />
                    </p>
                    <p class="text-sm text-muted">
                        {{ card.hint }}
                    </p>
                </article>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <ChartCard
                    title="Personas presentes"
                    description="Colaboradores con al menos una checada"
                    :dataset="peopleChartData"
                    :loading="chartsLoading"
                    :error="chartError"
                    :has-data="presenceHasData"
                    :chart-key="chartKeys.people"
                    empty-text="Sin datos para el rango seleccionado"
                />
                <ChartCard
                    title="Estado de empleados"
                    description="Activos vs bajas"
                    type="doughnut"
                    :options="{ plugins: { legend: { position: 'bottom' } } }"
                    :dataset="employeeStatusData"
                    :loading="chartsLoading"
                    :error="chartError"
                    :has-data="employeeStatusHasData"
                    :chart-key="chartKeys.employeeStatus"
                    empty-text="Sin datos para el rango seleccionado"
                />
                <ChartCard
                    title="Salud de relojes"
                    description="Monitoreo general"
                    type="doughnut"
                    :options="{ plugins: { legend: { position: 'bottom' } } }"
                    :dataset="clockHealthData"
                    :loading="chartsLoading"
                    :error="chartError"
                    :has-data="clockHealthHasData"
                    :chart-key="chartKeys.clockHealth"
                    empty-text="Sin datos para el rango seleccionado"
                />
                <article class="card flex flex-col justify-between px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                            Contexto del periodo
                        </p>
                        <p class="mt-2 text-lg font-semibold text-app">
                            {{ lastRangeLabel }}
                        </p>
                        <p class="text-sm text-muted">
                            {{ currentLocationLabel }}
                        </p>
                    </div>
                    <div class="mt-4 space-y-2 text-sm text-muted">
                        <p>
                            <span class="font-semibold text-app">Checadas:</span>
                            {{ formatNumber(summaryData.kpis?.checkins_total ?? 0) }}
                        </p>
                        <p>
                            <span class="font-semibold text-app">Última actualización:</span>
                            {{ lastUpdated }}
                        </p>
                    </div>
                </article>
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
