<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChartCard from '@/Components/ChartCard.vue';
import Toast from '@/Components/Toast.vue';
import UnitDetailDrawer from '@/Pages/Units/Partials/UnitDetailDrawer.vue';
import { hasChartData } from '@/utils/chart';
import { apiUrl } from '@/utils/url';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    companies: {
        type: Array,
        default: () => [],
    },
    locations: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const permissionMatrix = computed(() => page.props.auth?.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};
const canViewUnitDetails = computed(() => can('units', 'view'));
const canViewUnitsPage = computed(() => can('units', 'view'));

const rangeOptions = [
    { value: 'today', label: 'Hoy' },
    { value: '7d', label: 'Ultimos 7 dias' },
    { value: '30d', label: 'Ultimos 30 dias' },
    { value: 'custom', label: 'Personalizado' },
];

const dashboardTabDefinitions = [
    {
        key: 'resumen',
        label: 'Resumen ejecutivo',
        description: 'Como vamos hoy',
    },
    {
        key: 'relojes',
        label: 'Relojes y conectividad',
        description: 'Estado de la infraestructura biometrica',
    },
    {
        key: 'unidades',
        label: 'Unidades / Sucursales',
        description: 'Prioridades por unidad operativa',
    },
    {
        key: 'actividad',
        label: 'Actividad reciente',
        description: 'Pulso operativo en tiempo real',
    },
    {
        key: 'enrolamiento',
        label: 'Enrolamiento biometrico',
        description: 'Avance de captura biometrica',
    },
    {
        key: 'alertas',
        label: 'Alertas',
        description: 'Eventos que requieren accion',
    },
];

const DASHBOARD_ACTIVE_TAB_STORAGE_KEY = 'dashboard.activeTab';
const DASHBOARD_TAB_QUERY_KEY = 'dashboard_tab';
const DASHBOARD_AUTO_REFRESH_MS = 60000;
const defaultTabKey = 'resumen';
const dashboardTabKeys = new Set(dashboardTabDefinitions.map((tab) => tab.key));
const createEmptyTabState = () => Object.fromEntries(
    dashboardTabDefinitions.map((tab) => [tab.key, false]),
);

const filters = reactive({
    range: 'today',
    from_date: '',
    to_date: '',
    company_id: '',
    unit_id: '',
});

const summary = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const validationError = ref('');
const requestCounter = ref(0);
const chartVersion = ref(0);
const activeTab = ref(defaultTabKey);
const refreshingTab = ref('');
const isRefreshing = ref(false);
const loadingTabs = reactive(createEmptyTabState());
const loadedTabs = reactive(createEmptyTabState());
let queuedRefreshTab = null;
let refreshTimer = null;

const toast = reactive({
    show: false,
    type: 'info',
    title: '',
    message: '',
    duration: 5000,
});

const detailState = reactive({
    open: false,
    data: null,
    loading: false,
    error: '',
});

const numberFormatter = new Intl.NumberFormat('es-MX');
const percentFormatter = new Intl.NumberFormat('es-MX', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
});
const relativeTimeFormatter = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
const DASHBOARD_TIMEZONE_FALLBACK = 'America/Mexico_City';

const defaultSummary = {
    timezone: {
        name: DASHBOARD_TIMEZONE_FALLBACK,
        label: 'Hora centro de Mexico',
        offset: '-06:00',
    },
    meta: null,
    summary: {
        employees_active: 0,
        attendance_registered: 0,
        attendance_pending: 0,
        attendance_coverage: 0,
        entries_total: 0,
        exits_total: 0,
        latest_log_at: null,
        last_updated_at: null,
    },
    clocks: {
        total: 0,
        online: 0,
        offline: 0,
        warning: 0,
        heartbeat_recent: 0,
        heartbeat_stale: 0,
        never_connected: 0,
        last_reporting_clock: null,
        status: 'nodata',
        status_label: 'Sin datos',
        status_reason: 'No hay relojes configurados.',
        online_threshold_minutes: 5,
    },
    executive_status: {
        level: 'nodata',
        title: 'Sin datos operativos',
        message: 'No hay datos operativos suficientes para evaluar el periodo seleccionado.',
        bullets: [],
    },
    alerts: [],
    connectivity_alerts: [],
    locations: [],
    locations_meta: {
        total: 0,
        shown: 0,
        has_more: false,
        mode: 'priority',
        message: 'Mostrando unidades que requieren mayor atencion',
        limit: 6,
    },
    recent_activity: [],
    enrollment: {
        employees_active: 0,
        without_fingerprint: 0,
        without_face: 0,
        without_any_biometric: 0,
        with_any_biometric: 0,
        coverage_percentage: 0,
    },
    kpis: {
        checkins_total: 0,
        employees_active: 0,
        clocks_with_alerts: 0,
        clocks_offline: 0,
    },
    charts: {
        attendance_donut: {
            present: 0,
            pending: 0,
            percentage: 0,
        },
        clocks_donut: {
            online: 0,
            offline: 0,
            stale: 0,
        },
        hourly_activity: [],
        enrollment: {
            with_any_biometric: 0,
            without_any_biometric: 0,
            without_fingerprint: 0,
            without_face: 0,
            percentage: 0,
        },
        people_present_by_day: { labels: [], values: [] },
        employees_status: { labels: [], values: [] },
        clock_health: { labels: [], values: [] },
        top_branches: { labels: [], values: [] },
    },
    empty: false,
    message: null,
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

const locationOptions = computed(() => props.locations ?? []);
const companyOptions = computed(() => props.companies ?? []);
const hasCustomRange = computed(() => filters.range === 'custom');

const filteredLocations = computed(() => {
    if (!filters.company_id) {
        return locationOptions.value;
    }

    return locationOptions.value.filter((location) => String(location.company_id ?? '') === String(filters.company_id));
});

const normalizeTabKey = (value) => {
    const normalized = String(value ?? '')
        .trim()
        .toLowerCase();

    return dashboardTabKeys.has(normalized) ? normalized : defaultTabKey;
};

const readPersistedActiveTab = () => {
    if (typeof window === 'undefined') {
        return defaultTabKey;
    }

    const url = new URL(window.location.href);
    const urlTab = normalizeTabKey(url.searchParams.get(DASHBOARD_TAB_QUERY_KEY));
    const storedTab = normalizeTabKey(window.localStorage.getItem(DASHBOARD_ACTIVE_TAB_STORAGE_KEY));

    if (url.searchParams.has(DASHBOARD_TAB_QUERY_KEY)) {
        return urlTab;
    }

    return storedTab;
};

const persistActiveTab = (tabKey) => {
    if (typeof window === 'undefined') {
        return;
    }

    const normalized = normalizeTabKey(tabKey);
    const url = new URL(window.location.href);
    url.searchParams.set(DASHBOARD_TAB_QUERY_KEY, normalized);
    window.history.replaceState(window.history.state, '', url);
    window.localStorage.setItem(DASHBOARD_ACTIVE_TAB_STORAGE_KEY, normalized);
};

const mergeSummaryPayload = (current, incoming) => {
    const base = current ?? defaultSummary;

    return {
        ...base,
        ...incoming,
        charts: {
            ...(base.charts ?? {}),
            ...(incoming?.charts ?? {}),
        },
    };
};

const summaryData = computed(() => summary.value ?? defaultSummary);
const dashboardTimezone = computed(() => summaryData.value.timezone?.name ?? DASHBOARD_TIMEZONE_FALLBACK);
const dashboardTimezoneLabel = computed(() => summaryData.value.timezone?.label ?? 'Hora centro de Mexico');
const dashboardTimezoneOffset = computed(() => summaryData.value.timezone?.offset ?? '-06:00');
const dashboardTimezoneNote = computed(() => `Horarios mostrados en ${dashboardTimezoneLabel.value.toLowerCase()}.`);
const summaryMeta = computed(() => summaryData.value.meta ?? defaultSummary.meta);
const summaryEmpty = computed(() => summaryData.value.empty ?? false);
const summaryMessage = computed(() => summaryData.value.message ?? 'Sin datos operativos para el rango seleccionado.');
const summaryBlock = computed(() => summaryData.value.summary ?? defaultSummary.summary);
const clockBlock = computed(() => summaryData.value.clocks ?? defaultSummary.clocks);
const executiveStatus = computed(() => summaryData.value.executive_status ?? defaultSummary.executive_status);
const alertsList = computed(() => summaryData.value.alerts ?? []);
const connectivityAlerts = computed(() => summaryData.value.connectivity_alerts ?? []);
const locationsRanking = computed(() => summaryData.value.locations ?? []);
const locationsMeta = computed(() => summaryData.value.locations_meta ?? defaultSummary.locations_meta);
const recentActivity = computed(() => summaryData.value.recent_activity ?? []);
const enrollmentBlock = computed(() => summaryData.value.enrollment ?? defaultSummary.enrollment);
const activeTabDefinition = computed(() => (
    dashboardTabDefinitions.find((tab) => tab.key === activeTab.value)
    ?? dashboardTabDefinitions[0]
));
const activeTabLoading = computed(() => Boolean(loadingTabs[activeTab.value]));
const showActiveTabSkeleton = computed(() => !loadedTabs[activeTab.value] && (activeTabLoading.value || loading.value));
const recentActivityHeadline = computed(() => {
    const total = recentActivity.value.length;

    if (total <= 0) {
        return 'Sin registros recientes';
    }

    return total === 1 ? '1 registro reciente' : `${formatNumber(total)} registros recientes`;
});

const extractTimeZoneParts = (value, timeZone = dashboardTimezone.value) => {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });

    const parts = Object.fromEntries(
        formatter.formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return {
        year: Number(parts.year),
        month: Number(parts.month),
        day: Number(parts.day),
        hour: Number(parts.hour),
        minute: Number(parts.minute),
        second: Number(parts.second),
    };
};

const buildTimeZoneCalendarDate = (value = new Date(), timeZone = dashboardTimezone.value) => {
    const parts = extractTimeZoneParts(value, timeZone);

    if (!parts) {
        return new Date();
    }

    return new Date(Date.UTC(parts.year, parts.month - 1, parts.day, 12, 0, 0));
};

const formatTimeZoneInputValue = (value, timeZone = dashboardTimezone.value) => {
    const parts = extractTimeZoneParts(value, timeZone);

    if (!parts) {
        return '';
    }

    return `${String(parts.year).padStart(4, '0')}-${String(parts.month).padStart(2, '0')}-${String(parts.day).padStart(2, '0')}`;
};

const formatInputValue = (date) => {
    return formatTimeZoneInputValue(date, dashboardTimezone.value);
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
    const end = buildTimeZoneCalendarDate(new Date(), dashboardTimezone.value);
    const start = new Date(end);

    if (rangeValue === '7d') {
        start.setUTCDate(start.getUTCDate() - 6);
    } else if (rangeValue === '30d') {
        start.setUTCDate(start.getUTCDate() - 29);
    }

    filters.from_date = formatInputValue(start);
    filters.to_date = formatInputValue(end);
};

const buildParams = (tabKey = activeTab.value) => {
    validationError.value = '';
    const params = {
        range: filters.range,
        tab: normalizeTabKey(tabKey),
    };

    if (filters.company_id) {
        params.company_id = filters.company_id;
    }

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

const fetchSummary = async ({ tab = activeTab.value } = {}) => {
    const targetTab = normalizeTabKey(tab);

    if (isRefreshing.value) {
        queuedRefreshTab = targetTab;
        return;
    }

    let params;

    try {
        params = buildParams(targetTab);
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Rango invalido',
            message: error.message,
        });
        return;
    }

    const requestId = ++requestCounter.value;
    loading.value = true;
    isRefreshing.value = true;
    refreshingTab.value = targetTab;
    loadingTabs[targetTab] = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.get(apiUrl('/api/dashboard/summary'), { params });

        if (requestId !== requestCounter.value) {
            return;
        }

        summary.value = mergeSummaryPayload(summary.value, data);
        loadedTabs[targetTab] = true;
        chartVersion.value += 1;
    } catch (error) {
        if (requestId !== requestCounter.value) {
            return;
        }

        if (error.response?.status === 422) {
            const errors = error.response?.data?.errors ?? {};
            validationError.value = Object.values(errors)[0]?.[0] ?? 'Datos invalidos.';
            errorMessage.value = validationError.value;
        } else {
            validationError.value = '';
            errorMessage.value = error.response?.data?.message ?? 'Error al cargar la informacion.';
        }

        showToast({
            type: 'error',
            title: 'No se pudo actualizar',
            message: errorMessage.value,
        });
    } finally {
        if (requestId === requestCounter.value) {
            loading.value = false;
            isRefreshing.value = false;
            loadingTabs[targetTab] = false;
            refreshingTab.value = '';
        }

        if (requestId === requestCounter.value && queuedRefreshTab) {
            const nextTab = queuedRefreshTab;
            queuedRefreshTab = null;
            await fetchSummary({ tab: nextTab });
        }
    }
};

const onTabChange = async (tabKey) => {
    const normalized = normalizeTabKey(tabKey);

    if (activeTab.value === normalized) {
        persistActiveTab(normalized);

        if (!loadingTabs[normalized]) {
            await fetchSummary({ tab: normalized });
        }

        return;
    }

    activeTab.value = normalized;
    persistActiveTab(normalized);
    await fetchSummary({ tab: normalized });
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

    const fromDate = parseDateInput(filters.from_date);
    const toDate = parseDateInput(filters.to_date);

    if (!fromDate || !toDate || fromDate > toDate) {
        validationError.value = 'Revisa tus fechas. La inicial debe ser menor o igual a la final.';
        showToast({
            type: 'error',
            title: 'Rango invalido',
            message: validationError.value,
        });
        return;
    }

    fetchSummary({ tab: activeTab.value });
};

const viewLocationDetail = async (locationId) => {
    if (!locationId) return;

    detailState.open = true;
    detailState.loading = true;
    detailState.error = '';
    detailState.data = null;

    try {
        const { data } = await axios.get(route('units.show', locationId));
        detailState.data = data.data ?? null;
    } catch (error) {
        detailState.error = error.response?.data?.message ?? 'No se pudo cargar el detalle de la unidad.';
    } finally {
        detailState.loading = false;
    }
};

const setupAutoRefresh = () => {
    if (typeof window === 'undefined') return;

    clearAutoRefresh();

    refreshTimer = window.setInterval(() => {
        fetchSummary({ tab: activeTab.value });
    }, DASHBOARD_AUTO_REFRESH_MS);
};

const clearAutoRefresh = () => {
    if (refreshTimer && typeof window !== 'undefined') {
        window.clearInterval(refreshTimer);
        refreshTimer = null;
    }
};

const formatNumber = (value) => numberFormatter.format(value ?? 0);
const formatPercent = (value) => `${percentFormatter.format(value ?? 0)}%`;

const formatDateTime = (value) => {
    if (!value) return 'Sin datos';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Sin datos';

    return date.toLocaleString('es-MX', {
        timeZone: dashboardTimezone.value,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
};

const formatTime = (value) => {
    if (!value) return '--:--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--:--';

    return date.toLocaleTimeString('es-MX', {
        timeZone: dashboardTimezone.value,
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
};

const isToday = (value) => {
    const dateParts = extractTimeZoneParts(value, dashboardTimezone.value);
    const nowParts = extractTimeZoneParts(new Date(), dashboardTimezone.value);

    if (!dateParts || !nowParts) return false;

    return dateParts.year === nowParts.year
        && dateParts.month === nowParts.month
        && dateParts.day === nowParts.day;
};

const formatActivityDateLabel = (value) => {
    if (!value || isToday(value)) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    return date.toLocaleDateString('es-MX', {
        timeZone: dashboardTimezone.value,
        day: '2-digit',
        month: 'short',
    });
};

const formatRelative = (value) => {
    if (!value) return 'Sin datos';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Sin datos';

    const diffMs = date.getTime() - Date.now();
    const diffMinutes = Math.round(diffMs / 60000);

    if (Math.abs(diffMinutes) < 60) {
        return relativeTimeFormatter.format(diffMinutes, 'minute');
    }

    const diffHours = Math.round(diffMinutes / 60);
    if (Math.abs(diffHours) < 24) {
        return relativeTimeFormatter.format(diffHours, 'hour');
    }

    const diffDays = Math.round(diffHours / 24);
    return relativeTimeFormatter.format(diffDays, 'day');
};

const resolveAttendanceEventLabel = (value) => {
    const normalized = String(value ?? '')
        .trim()
        .toLowerCase();

    if (['1', 'entry', 'entrada', 'in', 'check_in'].includes(normalized)) {
        return 'Entrada';
    }

    if (['2', 'exit', 'salida', 'out', 'check_out'].includes(normalized)) {
        return 'Salida';
    }

    if (['3', 'break'].includes(normalized)) {
        return 'Break';
    }

    if (['4', 'return', 'regreso', 'back'].includes(normalized)) {
        return 'Regreso';
    }

    if (['', 'null', 'undefined', 'unknown', 'desconocido', 'no clasificado'].includes(normalized)) {
        return 'No clasificado';
    }

    return String(value ?? 'No clasificado').trim() || 'No clasificado';
};

const resolveAttendanceEventHint = (value) => (
    resolveAttendanceEventLabel(value) === 'No clasificado'
        ? 'No se pudo determinar si fue entrada o salida.'
        : ''
);

const resolveAttendanceMethodLabel = (value) => {
    const normalized = String(value ?? '').trim();

    return normalized === '' ? 'No especificado' : normalized;
};

const resolveAttendanceSourceLabel = (item) => {
    const source = String(item?.source_label ?? item?.source ?? '').trim();
    const method = resolveAttendanceMethodLabel(item?.method);

    if (source === '' || source === 'Sin fuente' || source === method) {
        return '';
    }

    return source;
};

const resolveCompanyName = (companyId) => {
    if (!companyId) return 'Todas las empresas';
    const company = companyOptions.value.find((item) => String(item.id) === String(companyId));
    if (!company) return 'Empresa seleccionada';
    return `${company.name}${company.code ? ` (${company.code})` : ''}`;
};

const currentLocationLabel = computed(() => {
    if (!filters.unit_id) {
        return 'Todas las sucursales';
    }

    const location = filteredLocations.value.find((item) => String(item.id) === String(filters.unit_id));
    if (!location) return 'Sucursal seleccionada';

    return `${location.name}${location.code ? ` (${location.code})` : ''}`;
});

const lastRangeLabel = computed(() => {
    const meta = summaryMeta.value;

    if (!meta?.from || !meta?.to) {
        return 'Rango seleccionado';
    }

    const from = new Date(meta.from);
    const to = new Date(meta.to);

    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) {
        return 'Rango seleccionado';
    }

    const format = (date) => {
        const parts = extractTimeZoneParts(date, dashboardTimezone.value);

        if (!parts) {
            return 'Rango seleccionado';
        }

        return `${String(parts.day).padStart(2, '0')}/${String(parts.month).padStart(2, '0')}/${parts.year}`;
    };

    return `${format(from)} al ${format(to)}`;
});

const criticalAlertsCount = computed(() => alertsList.value.filter((item) => item.level === 'critical').length);
const criticalLocationsCount = computed(() => locationsRanking.value.filter((item) => item.status === 'critical').length);

const dashboardTabs = computed(() => dashboardTabDefinitions.map((tab) => {
    switch (tab.key) {
        case 'resumen':
            return {
                ...tab,
                badge: formatPercent(summaryBlock.value.attendance_coverage ?? 0),
                badgeTone: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
            };
        case 'relojes':
            return {
                ...tab,
                badge: `${formatNumber(clockBlock.value.offline ?? 0)} offline`,
                badgeTone: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
            };
        case 'unidades':
            return {
                ...tab,
                badge: `${formatNumber(criticalLocationsCount.value)} criticas`,
                badgeTone: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
            };
        case 'actividad':
            return {
                ...tab,
                badge: `${formatNumber(recentActivity.value.length)} eventos`,
                badgeTone: 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
            };
        case 'enrolamiento':
            return {
                ...tab,
                badge: formatPercent(summaryData.value.charts?.enrollment?.percentage ?? 0),
                badgeTone: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300',
            };
        case 'alertas':
            return {
                ...tab,
                badge: `${formatNumber(criticalAlertsCount.value)} criticas`,
                badgeTone: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
            };
        default:
            return {
                ...tab,
                badge: '',
                badgeTone: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
            };
    }
}));

const summaryCards = computed(() => [
    {
        id: 'employees-active',
        title: 'Empleados activos',
        value: formatNumber(summaryBlock.value.employees_active ?? 0),
        hint: 'Base operativa actual',
        tone: 'from-slate-50 to-white dark:from-slate-800 dark:to-slate-900',
    },
    {
        id: 'attendance-registered',
        title: 'Asistencias',
        value: formatNumber(summaryBlock.value.attendance_registered ?? 0),
        hint: 'Personal con al menos una marca',
        tone: 'from-emerald-50 to-white dark:from-emerald-950/40 dark:to-slate-900',
    },
    {
        id: 'attendance-pending',
        title: 'Pendientes',
        value: formatNumber(summaryBlock.value.attendance_pending ?? 0),
        hint: 'Empleados activos sin registro',
        tone: 'from-amber-50 to-white dark:from-amber-950/40 dark:to-slate-900',
    },
    {
        id: 'attendance-coverage',
        title: 'Cobertura',
        value: formatPercent(summaryBlock.value.attendance_coverage ?? 0),
        hint: `${formatNumber(summaryBlock.value.attendance_registered ?? 0)} con registro hoy`,
        tone: 'from-sky-50 to-white dark:from-sky-950/40 dark:to-slate-900',
    },
    {
        id: 'latest-log-at',
        title: 'Ultima asistencia',
        value: formatRelative(summaryBlock.value.latest_log_at),
        hint: formatDateTime(summaryBlock.value.latest_log_at),
        tone: 'from-rose-50 to-white dark:from-rose-950/40 dark:to-slate-900',
    },
    {
        id: 'last-updated',
        title: 'Ultima actualizacion',
        value: formatRelative(summaryBlock.value.last_updated_at),
        hint: formatDateTime(summaryBlock.value.last_updated_at),
        tone: 'from-indigo-50 to-white dark:from-indigo-950/40 dark:to-slate-900',
    },
]);

const activityKpiCards = computed(() => [
    {
        id: 'entries-total',
        title: 'Entradas',
        value: formatNumber(summaryBlock.value.entries_total ?? 0),
        hint: 'Eventos tipo entrada en el periodo',
        tone: 'border-sky-100 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/50 dark:text-sky-300',
    },
    {
        id: 'exits-total',
        title: 'Salidas',
        value: formatNumber(summaryBlock.value.exits_total ?? 0),
        hint: 'Eventos tipo salida en el periodo',
        tone: 'border-rose-100 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300',
    },
    {
        id: 'latest-activity',
        title: 'Ultimo evento',
        value: formatRelative(summaryBlock.value.latest_log_at),
        hint: formatDateTime(summaryBlock.value.latest_log_at),
        tone: 'border-indigo-100 bg-indigo-50 text-indigo-700 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-300',
    },
]);

const executiveHeroClasses = computed(() => {
    switch (executiveStatus.value.level) {
        case 'critical':
            return {
                panel: 'border-rose-200 bg-gradient-to-br from-rose-50 via-white to-amber-50 dark:border-rose-800 dark:from-rose-950/40 dark:via-slate-900 dark:to-amber-950/30',
                badge: 'bg-rose-600 text-white',
                accent: 'text-rose-700 dark:text-rose-300',
                dot: 'bg-rose-500',
            };
        case 'warning':
            return {
                panel: 'border-amber-200 bg-gradient-to-br from-amber-50 via-white to-yellow-50 dark:border-amber-800 dark:from-amber-950/40 dark:via-slate-900 dark:to-yellow-950/20',
                badge: 'bg-amber-500 text-white',
                accent: 'text-amber-700 dark:text-amber-300',
                dot: 'bg-amber-500',
            };
        case 'normal':
            return {
                panel: 'border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-cyan-50 dark:border-emerald-800 dark:from-emerald-950/40 dark:via-slate-900 dark:to-cyan-950/30',
                badge: 'bg-emerald-600 text-white',
                accent: 'text-emerald-700 dark:text-emerald-300',
                dot: 'bg-emerald-500',
            };
        default:
            return {
                panel: 'border-slate-200 bg-gradient-to-br from-slate-50 via-white to-slate-100 dark:border-slate-700 dark:from-slate-800 dark:via-slate-900 dark:to-slate-950',
                badge: 'bg-slate-500 text-white',
                accent: 'text-slate-700 dark:text-slate-300',
                dot: 'bg-slate-400',
            };
    }
});

const heroIndicators = computed(() => [
    {
        id: 'coverage',
        label: 'Cobertura asistencia',
        value: formatPercent(summaryBlock.value.attendance_coverage ?? 0),
        hint: `${formatNumber(summaryBlock.value.attendance_registered ?? 0)} de ${formatNumber(summaryBlock.value.employees_active ?? 0)} empleados`,
    },
    {
        id: 'clocks-online',
        label: 'Relojes en linea',
        value: `${formatNumber(clockBlock.value.online ?? 0)} / ${formatNumber(clockBlock.value.total ?? 0)}`,
        hint: `${formatNumber(clockBlock.value.offline ?? 0)} sin conexion`,
    },
    {
        id: 'alerts-active',
        label: 'Alertas activas',
        value: formatNumber(alertsList.value.length),
        hint: `${formatNumber(alertsList.value.filter((item) => item.level === 'critical').length)} criticas`,
    },
]);

const attendanceDonutData = computed(() => ({
    labels: ['Asistieron', 'Pendientes'],
    datasets: [
        {
            label: 'Cobertura',
            data: [
                summaryData.value.charts?.attendance_donut?.present ?? 0,
                summaryData.value.charts?.attendance_donut?.pending ?? 0,
            ],
            backgroundColor: ['#0f766e', '#e2e8f0'],
            borderWidth: 0,
            hoverOffset: 6,
        },
    ],
}));

const clocksDonutData = computed(() => ({
    labels: ['En linea', 'Sin conexion', 'Sin actividad'],
    datasets: [
        {
            label: 'Conectividad',
            data: [
                summaryData.value.charts?.clocks_donut?.online ?? 0,
                summaryData.value.charts?.clocks_donut?.offline ?? 0,
                summaryData.value.charts?.clocks_donut?.stale ?? 0,
            ],
            backgroundColor: ['#16a34a', '#ef4444', '#f59e0b'],
            borderWidth: 0,
            hoverOffset: 6,
        },
    ],
}));

const hourlyActivityData = computed(() => ({
    labels: (summaryData.value.charts?.hourly_activity ?? []).map((item) => item.hour),
    datasets: [
        {
            label: 'Entradas',
            data: (summaryData.value.charts?.hourly_activity ?? []).map((item) => item.entries ?? 0),
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.15)',
            fill: true,
            tension: 0.35,
        },
        {
            label: 'Salidas',
            data: (summaryData.value.charts?.hourly_activity ?? []).map((item) => item.exits ?? 0),
            borderColor: '#e11d48',
            backgroundColor: 'rgba(225, 29, 72, 0.08)',
            fill: false,
            tension: 0.35,
        },
        {
            label: 'Total',
            data: (summaryData.value.charts?.hourly_activity ?? []).map((item) => item.total ?? 0),
            borderColor: '#0f766e',
            backgroundColor: 'rgba(15, 118, 110, 0.08)',
            borderDash: [5, 5],
            fill: false,
            tension: 0.25,
        },
    ],
}));

const peopleChartData = computed(() => ({
    labels: summaryData.value.charts?.people_present_by_day?.labels ?? [],
    datasets: [
        {
            label: 'Personas presentes',
            data: summaryData.value.charts?.people_present_by_day?.values ?? [],
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.14)',
            tension: 0.35,
            fill: true,
        },
    ],
}));

const employeeStatusData = computed(() => ({
    labels: summaryData.value.charts?.employees_status?.labels ?? [],
    datasets: [
        {
            label: 'Colaboradores',
            data: summaryData.value.charts?.employees_status?.values ?? [],
            backgroundColor: ['#16a34a', '#e11d48'],
            borderWidth: 0,
        },
    ],
}));

const topBranchesData = computed(() => ({
    labels: summaryData.value.charts?.top_branches?.labels ?? [],
    datasets: [
        {
            label: 'Asistencias por unidad',
            data: summaryData.value.charts?.top_branches?.values ?? [],
            backgroundColor: '#0f766e',
            borderRadius: 12,
            borderSkipped: false,
        },
    ],
}));

const attendanceHasData = computed(() => hasChartData(attendanceDonutData.value));
const clocksHasData = computed(() => hasChartData(clocksDonutData.value));
const hourlyHasData = computed(() => hasChartData(hourlyActivityData.value));
const presenceHasData = computed(() => hasChartData(peopleChartData.value));
const employeeStatusHasData = computed(() => hasChartData(employeeStatusData.value));
const topBranchesHasData = computed(() => hasChartData(topBranchesData.value));

const attendanceChartOptions = {
    cutout: '72%',
    plugins: {
        legend: { position: 'bottom' },
    },
};

const clocksChartOptions = {
    cutout: '70%',
    plugins: {
        legend: { position: 'bottom' },
    },
};

const hourlyChartOptions = {
    plugins: {
        legend: { position: 'bottom' },
    },
    scales: {
        y: {
            beginAtZero: true,
        },
    },
};

const locationStatusClasses = (status) => {
    switch (status) {
        case 'normal':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300';
        case 'warning':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300';
        case 'critical':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300';
        default:
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
    }
};

const buildAlertGroups = (alerts) => {
    const definitions = [
        {
            key: 'critical',
            title: 'Criticas',
            description: 'Requieren accion inmediata.',
            wrap: 'border-rose-200 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/40',
            badge: 'bg-rose-600 text-white',
            text: 'text-rose-700 dark:text-rose-300',
        },
        {
            key: 'warning',
            title: 'Atencion',
            description: 'Seguimiento preventivo durante el dia.',
            wrap: 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40',
            badge: 'bg-amber-500 text-white',
            text: 'text-amber-700 dark:text-amber-300',
        },
        {
            key: 'info',
            title: 'Informativas',
            description: 'Contexto operativo complementario.',
            wrap: 'border-sky-200 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/40',
            badge: 'bg-sky-600 text-white',
            text: 'text-sky-700 dark:text-sky-300',
        },
    ];

    return definitions.map((group) => ({
        ...group,
        items: alerts.filter((alert) => alert.level === group.key),
        count: alerts.filter((alert) => alert.level === group.key).length,
    }));
};

const alertGroups = computed(() => buildAlertGroups(alertsList.value));
const connectivityAlertGroups = computed(() => buildAlertGroups(connectivityAlerts.value));

const enrollmentRingStyle = computed(() => {
    const percentage = Math.max(0, Math.min(summaryData.value.charts?.enrollment?.percentage ?? 0, 100));

    return {
        background: `conic-gradient(#2563eb 0 ${percentage}%, #e2e8f0 ${percentage}% 100%)`,
    };
});

watch(
    () => filters.range,
    (value) => {
        if (value !== 'custom') {
            validationError.value = '';
            applyRangeDefaults(value);
            fetchSummary({ tab: activeTab.value });
        } else if (!filters.from_date || !filters.to_date) {
            applyRangeDefaults('today');
        }
    },
);

watch(
    () => filters.company_id,
    () => {
        const validLocationIds = new Set(filteredLocations.value.map((location) => String(location.id)));

        if (filters.unit_id && !validLocationIds.has(String(filters.unit_id))) {
            filters.unit_id = '';
            return;
        }

        fetchSummary({ tab: activeTab.value });
    },
);

watch(
    () => filters.unit_id,
    () => {
        fetchSummary({ tab: activeTab.value });
    },
);

onMounted(() => {
    activeTab.value = readPersistedActiveTab();
    persistActiveTab(activeTab.value);
    applyRangeDefaults(filters.range);
    fetchSummary({ tab: activeTab.value });
    setupAutoRefresh();
});

onBeforeUnmount(() => {
    clearAutoRefresh();
});
</script>

<template>
    <Head title="Dashboard ejecutivo" />

    <AuthenticatedLayout>
        <template #header>
            <div class="min-w-0">
                <h1 class="text-app truncate text-base font-semibold leading-tight sm:text-2xl">
                    Dashboard general
                </h1>
                <p class="hidden truncate text-xs text-muted sm:block sm:text-sm">
                    Centro de mando ejecutivo para asistencia, biometria y conectividad.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="card overflow-hidden px-4 py-4 sm:px-6">
                <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end">
                    <label class="flex w-full min-w-0 flex-col gap-2 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:w-auto sm:tracking-[0.3em]">
                        Rango
                        <select
                            v-model="filters.range"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold sm:w-auto"
                        >
                            <option v-for="option in rangeOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>

                    <div
                        v-if="hasCustomRange"
                        class="grid w-full min-w-0 gap-3 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:grid-cols-2 sm:tracking-[0.3em] xl:w-auto xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
                    >
                        <label class="flex min-w-0 flex-col gap-2">
                            Desde
                            <input
                                v-model="filters.from_date"
                                type="date"
                                class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold"
                            />
                        </label>
                        <label class="flex min-w-0 flex-col gap-2">
                            Hasta
                            <input
                                v-model="filters.to_date"
                                type="date"
                                class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold"
                            />
                        </label>
                        <button
                            type="button"
                            class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.15em] text-white shadow hover:bg-indigo-500 sm:tracking-[0.3em] xl:w-auto"
                            :disabled="loading"
                            @click="applyCustomRange"
                        >
                            Aplicar
                        </button>
                    </div>

                    <label class="flex w-full min-w-0 flex-col gap-2 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:w-auto sm:tracking-[0.3em]">
                        Empresa
                        <select
                            v-model="filters.company_id"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold sm:w-auto"
                        >
                            <option value="">Todas</option>
                            <option v-for="company in companyOptions" :key="company.id" :value="company.id">
                                {{ company.name }}{{ company.code ? ` (${company.code})` : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="flex w-full min-w-0 flex-col gap-2 text-xs font-semibold uppercase tracking-[0.15em] text-soft sm:w-auto sm:tracking-[0.3em]">
                        Sucursal
                        <select
                            v-model="filters.unit_id"
                            class="w-full min-w-0 max-w-full rounded-2xl border border-app bg-white px-3 py-2 text-sm font-semibold sm:w-auto"
                        >
                            <option value="">Todas</option>
                            <option v-for="location in filteredLocations" :key="location.id" :value="location.id">
                                {{ location.name }}{{ location.code ? ` (${location.code})` : '' }}
                            </option>
                        </select>
                    </label>

                    <button
                        type="button"
                        class="inline-flex w-full shrink-0 items-center justify-center gap-2 rounded-2xl border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 sm:w-auto"
                        :disabled="loading"
                        @click="fetchSummary({ tab: activeTab })"
                    >
                        <span v-if="activeTabLoading">Actualizando...</span>
                        <span v-else>Actualizar seccion</span>
                    </button>
                </div>

                <div class="mt-4 flex min-w-0 flex-wrap items-center gap-2 text-xs text-muted">
                    <span class="max-w-full truncate rounded-full bg-slate-100 px-3 py-1">
                        {{ resolveCompanyName(filters.company_id) }}
                    </span>
                    <span class="max-w-full truncate rounded-full bg-slate-100 px-3 py-1">
                        {{ currentLocationLabel }}
                    </span>
                    <span class="max-w-full truncate rounded-full bg-slate-100 px-3 py-1">
                        {{ lastRangeLabel }}
                    </span>
                    <span class="max-w-full truncate rounded-full bg-slate-100 px-3 py-1">
                        Auto refresh cada 60 s
                    </span>
                    <span class="max-w-full truncate rounded-full bg-slate-100 px-3 py-1">
                        {{ dashboardTimezoneLabel }} {{ dashboardTimezoneOffset }}
                    </span>
                </div>
            </div>

            <div class="card overflow-hidden p-2 sm:p-3">
                <div class="flex gap-2 overflow-x-auto whitespace-nowrap pb-1">
                    <button
                        v-for="tab in dashboardTabs"
                        :key="tab.key"
                        type="button"
                        class="min-w-[13rem] flex-none rounded-[1.75rem] border px-4 py-3 text-left transition sm:min-w-0 sm:flex-1"
                        :class="activeTab === tab.key
                            ? 'border-indigo-300 bg-white/90 shadow-sm ring-1 ring-indigo-200/80 dark:border-indigo-500/50 dark:bg-slate-900/80 dark:ring-indigo-500/30'
                            : 'border-slate-100 bg-white/90 hover:border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900/60 dark:hover:border-slate-700 dark:hover:bg-slate-900/80'"
                        @click="onTabChange(tab.key)"
                    >
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="whitespace-normal text-sm font-semibold text-app">
                                    {{ tab.label }}
                                </p>
                                <p class="mt-1 whitespace-normal text-xs text-muted">
                                    {{ tab.description }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="tab.badgeTone">
                                {{ tab.badge }}
                            </span>
                        </div>
                    </button>
                </div>
            </div>

            <div
                v-if="activeTabLoading"
                class="rounded-3xl border border-indigo-100 bg-indigo-50/70 px-4 py-3 text-sm text-indigo-700"
            >
                Actualizando {{ activeTabDefinition.label.toLowerCase() }} sin recargar toda la pagina.
            </div>

            <div v-if="showActiveTabSkeleton" class="grid gap-4 lg:grid-cols-3">
                <div class="card h-40 animate-pulse bg-slate-100/80" />
                <div class="card h-40 animate-pulse bg-slate-100/80" />
                <div class="card h-40 animate-pulse bg-slate-100/80" />
            </div>

            <article v-if="activeTab === 'resumen' && !showActiveTabSkeleton" class="card overflow-hidden border px-5 py-5 sm:px-6" :class="executiveHeroClasses.panel">
                <div class="grid gap-6 xl:grid-cols-[1.3fr_0.9fr]">
                    <div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em]" :class="executiveHeroClasses.badge">
                                <span class="h-2.5 w-2.5 rounded-full bg-white/90" />
                                {{ executiveStatus.title }}
                            </span>
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-muted">
                                Ultima actualizacion {{ formatRelative(summaryBlock.last_updated_at) }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-muted">
                            {{ dashboardTimezoneNote }}
                        </p>

                        <h2 class="mt-4 text-3xl font-semibold text-app sm:text-4xl">
                            Estado general del dia
                        </h2>
                        <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600">
                            {{ executiveStatus.message }}
                        </p>

                        <div class="mt-6 grid gap-3 sm:grid-cols-3">
                            <article
                                v-for="indicator in heroIndicators"
                                :key="indicator.id"
                                class="card-subtle bg-white/80 px-4 py-4 shadow-sm backdrop-blur"
                            >
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                    {{ indicator.label }}
                                </p>
                                <p class="mt-3 text-3xl font-semibold text-app">
                                    {{ indicator.value }}
                                </p>
                                <p class="mt-2 text-sm text-muted">
                                    {{ indicator.hint }}
                                </p>
                            </article>
                        </div>
                    </div>

                    <div class="card bg-white/80 p-5 backdrop-blur">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                    Lectura ejecutiva
                                </p>
                                <h3 class="mt-1 text-xl font-semibold text-app">
                                    Lo que importa ahora
                                </h3>
                            </div>
                            <span class="h-3.5 w-3.5 rounded-full" :class="executiveHeroClasses.dot" />
                        </div>

                        <ul class="mt-5 space-y-3 text-sm text-slate-600">
                            <li
                                v-for="(bullet, index) in executiveStatus.bullets"
                                :key="`${index}-${bullet}`"
                                class="card-subtle flex gap-3 px-3 py-3"
                            >
                                <span class="mt-1 h-2 w-2 rounded-full bg-slate-400" />
                                <span>{{ bullet }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </article>

            <div v-if="activeTab === 'resumen' && !showActiveTabSkeleton" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                <article
                    v-for="item in summaryCards"
                    :key="item.id"
                    class="card bg-gradient-to-br px-4 py-4"
                    :class="item.tone"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        {{ item.title }}
                    </p>
                    <p class="mt-3 text-3xl font-semibold text-app">
                        {{ item.value }}
                    </p>
                    <p class="mt-2 text-sm text-muted">
                        {{ item.hint }}
                    </p>
                </article>
            </div>

            <div v-if="activeTab === 'relojes' && !showActiveTabSkeleton" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="card-kpi bg-gradient-to-br from-emerald-50 to-white px-4 py-4 dark:from-emerald-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-700">Relojes en linea</p>
                    <p class="mt-3 text-3xl font-semibold text-emerald-700 dark:text-emerald-300">{{ formatNumber(clockBlock.online) }}</p>
                    <p class="mt-2 text-sm text-muted">{{ formatNumber(clockBlock.total) }} equipos monitoreados</p>
                </article>
                <article class="card-kpi bg-gradient-to-br from-rose-50 to-white px-4 py-4 dark:from-rose-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-rose-700">Relojes offline</p>
                    <p class="mt-3 text-3xl font-semibold text-rose-700 dark:text-rose-300">{{ formatNumber(clockBlock.offline) }}</p>
                    <p class="mt-2 text-sm text-muted">Sin conexion dentro del umbral operativo</p>
                </article>
                <article class="card-kpi bg-gradient-to-br from-amber-50 to-white px-4 py-4 dark:from-amber-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-amber-700">Heartbeat con rezago</p>
                    <p class="mt-3 text-3xl font-semibold text-amber-700 dark:text-amber-300">{{ formatNumber(clockBlock.heartbeat_stale) }}</p>
                    <p class="mt-2 text-sm text-muted">Mas de {{ formatNumber(clockBlock.online_threshold_minutes) }} minutos sin actividad</p>
                </article>
                <article class="card-kpi bg-gradient-to-br from-slate-50 to-white px-4 py-4 dark:from-slate-800 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Nunca conectados</p>
                    <p class="mt-3 text-3xl font-semibold text-app">{{ formatNumber(clockBlock.never_connected) }}</p>
                    <p class="mt-2 text-sm text-muted">Equipos registrados sin heartbeat previo</p>
                </article>
            </div>

            <div v-if="activeTab === 'actividad' && !showActiveTabSkeleton" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="item in activityKpiCards"
                    :key="item.id"
                    class="card border px-4 py-4"
                    :class="item.tone"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.3em]">
                        {{ item.title }}
                    </p>
                    <p class="mt-3 text-3xl font-semibold">
                        {{ item.value }}
                    </p>
                    <p class="mt-2 text-sm text-muted">
                        {{ item.hint }}
                    </p>
                </article>
            </div>

            <div
                v-if="activeTab === 'resumen' && !showActiveTabSkeleton"
                class="grid gap-6 lg:grid-cols-2 xl:grid-cols-3 xl:items-start"
            >
                <ChartCard
                    title="Asistencia del dia"
                    description="Asistieron vs pendientes"
                    type="doughnut"
                    :options="attendanceChartOptions"
                    :dataset="attendanceDonutData"
                    :has-data="attendanceHasData"
                    :chart-key="chartVersion"
                    height-class="h-52 sm:h-56 lg:h-60"
                    content-class="p-5"
                    empty-text="Sin registros de asistencia para el periodo"
                >
                    <template #footer>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-3 py-3">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-emerald-700">Asistieron</p>
                                <p class="mt-2 text-2xl font-semibold text-emerald-700">{{ formatNumber(summaryData.charts.attendance_donut.present) }}</p>
                            </div>
                            <div class="rounded-2xl border border-amber-100 bg-amber-50 px-3 py-3">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-amber-700">Pendientes</p>
                                <p class="mt-2 text-2xl font-semibold text-amber-700">{{ formatNumber(summaryData.charts.attendance_donut.pending) }}</p>
                            </div>
                        </div>
                    </template>
                </ChartCard>

                <ChartCard
                    title="Personas presentes"
                    description="Colaboradores con al menos una checada"
                    :dataset="peopleChartData"
                    :has-data="presenceHasData"
                    :chart-key="chartVersion + 3"
                    height-class="h-52 sm:h-56 lg:h-60"
                    content-class="p-5"
                    empty-text="Sin personas registradas en el periodo"
                />

                <ChartCard
                    title="Estado de empleados"
                    description="Activos vs bajas"
                    type="doughnut"
                    :options="{ plugins: { legend: { position: 'bottom' } }, cutout: '68%' }"
                    :dataset="employeeStatusData"
                    :has-data="employeeStatusHasData"
                    :chart-key="chartVersion + 4"
                    height-class="h-52 sm:h-56 lg:h-60"
                    content-class="p-5"
                    empty-text="Sin empleados para el filtro actual"
                />
            </div>

            <div
                v-if="activeTab === 'relojes' && !showActiveTabSkeleton"
                class="grid gap-6"
            >
                <ChartCard
                    title="Estado de relojes"
                    description="En linea, sin conexion y sin actividad"
                    type="doughnut"
                    :options="clocksChartOptions"
                    :dataset="clocksDonutData"
                    :has-data="clocksHasData"
                    :chart-key="chartVersion + 1"
                    height-class="h-52 sm:h-56 lg:h-60"
                    content-class="p-5"
                    empty-text="Sin relojes configurados"
                >
                    <template #footer>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-app">{{ clockBlock.status_label }}</span>
                                <span class="rounded-full px-3 py-1 text-[11px] font-semibold uppercase" :class="executiveHeroClasses.badge">
                                    {{ clockBlock.status }}
                                </span>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-3 text-sm">
                                <div class="rounded-2xl bg-emerald-50 px-3 py-3 text-center">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-emerald-700">En linea</p>
                                    <p class="mt-1 text-xl font-semibold text-emerald-700">{{ formatNumber(clockBlock.online) }}</p>
                                </div>
                                <div class="rounded-2xl bg-rose-50 px-3 py-3 text-center">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-rose-700">Offline</p>
                                    <p class="mt-1 text-xl font-semibold text-rose-700">{{ formatNumber(clockBlock.offline) }}</p>
                                </div>
                                <div class="rounded-2xl bg-amber-50 px-3 py-3 text-center">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-amber-700">Sin actividad</p>
                                    <p class="mt-1 text-xl font-semibold text-amber-700">{{ formatNumber(clockBlock.heartbeat_stale) }}</p>
                                </div>
                            </div>
                        </div>
                    </template>
                </ChartCard>
            </div>

            <div
                v-if="activeTab === 'relojes' && !showActiveTabSkeleton"
                class="grid gap-6 lg:grid-cols-2 xl:grid-cols-[1.1fr_0.9fr] xl:items-start"
            >
                <article class="card relative isolate overflow-hidden px-5 py-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Conectividad
                            </p>
                            <h2 class="mt-1 text-xl font-semibold text-app">
                                Riesgos de infraestructura
                            </h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            {{ connectivityAlerts.length ? `${connectivityAlerts.length} alertas` : 'Sin alertas' }}
                        </span>
                    </div>

                    <div class="mt-5 grid gap-4">
                        <article
                            v-for="group in connectivityAlertGroups"
                            :key="group.key"
                            class="relative overflow-hidden rounded-3xl border px-4 py-4"
                            :class="group.wrap"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-lg font-semibold" :class="group.text">
                                            {{ group.title }}
                                        </h3>
                                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="group.badge">
                                            {{ formatNumber(group.count) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm" :class="group.text">
                                        {{ group.description }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="group.items.length" class="mt-4 space-y-3">
                                <article
                                    v-for="item in group.items"
                                    :key="item.id"
                                    class="rounded-2xl bg-white/80 px-3 py-3 text-sm text-slate-700"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-semibold text-app">
                                                {{ item.title }}
                                            </p>
                                            <p class="mt-1 text-sm text-muted">
                                                {{ item.message }}
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white">
                                            {{ formatNumber(item.metric) }}
                                        </span>
                                    </div>
                                </article>
                            </div>
                            <div v-else class="mt-4 rounded-2xl bg-white/70 px-3 py-3 text-sm text-muted">
                                Sin alertas de conectividad en este grupo.
                            </div>
                        </article>
                    </div>
                </article>

                <article class="card relative isolate overflow-hidden px-5 py-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Nota operativa
                            </p>
                            <h2 class="mt-1 text-xl font-semibold text-app">
                                Estado de monitoreo
                            </h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            {{ clockBlock.status_label }}
                        </span>
                    </div>

                    <div class="card-subtle mt-5 px-4 py-4">
                        <p class="text-sm leading-7 text-slate-600">
                            {{ clockBlock.status_reason }}
                        </p>
                        <p class="mt-2 text-xs text-muted">
                            {{ dashboardTimezoneNote }} Umbral actual: {{ formatNumber(clockBlock.online_threshold_minutes) }} minutos.
                        </p>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <article class="card-subtle px-4 py-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Heartbeat reciente
                            </p>
                            <p class="mt-2 text-3xl font-semibold text-app">
                                {{ formatNumber(clockBlock.heartbeat_recent) }}
                            </p>
                        </article>
                        <article class="card-subtle px-4 py-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Ultimo reloj reportando
                            </p>
                            <p class="mt-2 text-lg font-semibold text-app">
                                {{ clockBlock.last_reporting_clock?.name ?? 'Sin registros recientes' }}
                            </p>
                        </article>
                    </div>
                </article>
            </div>

            <div
                v-if="activeTab === 'alertas' && !showActiveTabSkeleton"
                class="grid gap-6 lg:grid-cols-2 xl:grid-cols-[1.05fr_0.95fr] xl:items-start"
            >
                <article class="card relative isolate overflow-hidden px-5 py-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Alertas agrupadas
                            </p>
                            <h2 class="mt-1 text-xl font-semibold text-app">
                                Prioridades operativas
                            </h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            {{ alertsList.length ? `${alertsList.length} alertas` : 'Sin alertas' }}
                        </span>
                    </div>

                    <div class="mt-5 grid gap-4">
                        <article
                            v-for="group in alertGroups"
                            :key="group.key"
                            class="relative overflow-hidden rounded-3xl border px-4 py-4"
                            :class="group.wrap"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-lg font-semibold" :class="group.text">
                                            {{ group.title }}
                                        </h3>
                                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="group.badge">
                                            {{ formatNumber(group.count) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm" :class="group.text">
                                        {{ group.description }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="group.items.length" class="mt-4 space-y-3">
                                <article
                                    v-for="item in group.items"
                                    :key="item.id"
                                    class="rounded-2xl bg-white/80 px-3 py-3 text-sm text-slate-700"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-semibold text-app">
                                                {{ item.title }}
                                            </p>
                                            <p class="mt-1 text-sm text-muted">
                                                {{ item.message }}
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white">
                                            {{ formatNumber(item.metric) }}
                                        </span>
                                    </div>
                                </article>
                            </div>
                        <div v-else class="card-subtle mt-4 bg-white/70 px-3 py-3 text-sm text-muted">
                                Sin alertas en este grupo.
                            </div>
                        </article>
                    </div>
                </article>

                <article class="card relative isolate overflow-hidden px-5 py-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Lectura ejecutiva
                            </p>
                            <h2 class="mt-1 text-xl font-semibold text-app">
                                Resumen integrado
                            </h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            {{ executiveStatus.title }}
                        </span>
                    </div>

                        <div class="card-subtle mt-5 px-4 py-4">
                            <p class="text-sm leading-7 text-slate-600">
                                {{ executiveStatus.message }}
                            </p>
                            <p class="mt-2 text-xs text-muted">
                                {{ dashboardTimezoneNote }}
                            </p>
                        </div>

                    <div class="mt-5 space-y-3">
                        <article
                            v-for="(bullet, index) in executiveStatus.bullets"
                            :key="`${index}-${bullet}`"
                            class="card-subtle px-4 py-4 shadow-sm"
                        >
                            <div class="flex gap-3">
                                <span class="mt-1 h-2.5 w-2.5 rounded-full bg-indigo-500" />
                                <p class="text-sm text-slate-700">
                                    {{ bullet }}
                                </p>
                            </div>
                        </article>
                    </div>
                </article>
            </div>

            <article v-if="activeTab === 'unidades' && !showActiveTabSkeleton" class="card px-5 py-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                            Mapa operativo por unidad
                        </p>
                        <h2 class="mt-1 text-xl font-semibold text-app">
                            Cobertura y conectividad por sucursal
                        </h2>
                        <p class="mt-2 text-sm text-muted">
                            {{ locationsMeta.message }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm text-muted">
                        <span class="rounded-full bg-slate-100 px-3 py-1">
                            {{ locationsMeta.shown }} de {{ locationsMeta.total }} unidades mostradas
                        </span>
                        <Link
                            v-if="canViewUnitsPage && locationsMeta.has_more"
                            :href="route('units.index')"
                            class="inline-flex rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-app transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                        >
                            Ver todas las unidades
                        </Link>
                    </div>
                </div>

                <div v-if="locationsRanking.length" class="mt-5 grid gap-4 xl:grid-cols-2 2xl:grid-cols-3">
                    <article
                        v-for="location in locationsRanking"
                        :key="location.id"
                        class="card-record bg-gradient-to-br from-white via-slate-50 to-slate-100 px-4 py-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-base font-semibold text-app">
                                    {{ location.name }}
                                </p>
                                <p class="text-xs text-muted">
                                    {{ location.code || 'Sin codigo' }}
                                </p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="locationStatusClasses(location.status)">
                                {{ location.status_label }}
                            </span>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="card-subtle bg-white/80 px-3 py-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Asistencia</p>
                                <p class="mt-1 text-2xl font-semibold text-app">
                                    {{ formatPercent(location.attendance_coverage) }}
                                </p>
                            </div>
                            <div class="card-subtle bg-white/80 px-3 py-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Online</p>
                                <p class="mt-1 text-2xl font-semibold text-emerald-700">
                                    {{ formatNumber(location.clocks_online) }}
                                </p>
                            </div>
                            <div class="card-subtle bg-white/80 px-3 py-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Offline</p>
                                <p class="mt-1 text-2xl font-semibold text-rose-700">
                                    {{ formatNumber(location.clocks_offline) }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                <span>Avance de asistencia</span>
                                <span>{{ formatNumber(location.attendance_registered) }} / {{ formatNumber(location.employees_active) }}</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-500 transition-all"
                                    :style="{ width: `${Math.min(location.attendance_coverage ?? 0, 100)}%` }"
                                />
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
                            <span>
                                {{ location.employees_active > 0 ? `${formatNumber(location.employees_active)} empleados activos` : 'Sin empleados activos' }}
                            </span>
                            <button
                                v-if="canViewUnitDetails"
                                type="button"
                                class="inline-flex rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-app transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                                @click="viewLocationDetail(location.id)"
                            >
                                Ver detalle
                            </button>
                        </div>
                    </article>
                </div>

                <div v-else class="card mt-5 px-4 py-5 text-sm text-muted">
                    No hay unidades configuradas para los filtros seleccionados.
                </div>

                <div v-if="locationsRanking.length" class="card mt-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                            <tr>
                                <th class="px-5 py-3">Unidad</th>
                                <th class="px-4 py-3 text-right">Activos</th>
                                <th class="px-4 py-3 text-right">Asistencias</th>
                                <th class="px-4 py-3 text-right">% asistencia</th>
                                <th class="px-4 py-3 text-right">Online</th>
                                <th class="px-4 py-3 text-right">Offline</th>
                                <th class="px-4 py-3">Estado</th>
                                <th v-if="canViewUnitDetails" class="px-5 py-3 text-right">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in locationsRanking"
                                :key="`table-${row.id}`"
                                class="border-t border-slate-100 hover:bg-slate-50/80"
                            >
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-app">{{ row.name }}</p>
                                    <p class="text-xs text-muted">{{ row.code || 'Sin codigo' }}</p>
                                </td>
                                <td class="px-4 py-3 text-right text-app">{{ formatNumber(row.employees_active) }}</td>
                                <td class="px-4 py-3 text-right text-app">{{ formatNumber(row.attendance_registered) }}</td>
                                <td class="px-4 py-3 text-right text-app">{{ formatPercent(row.attendance_coverage) }}</td>
                                <td class="px-4 py-3 text-right text-emerald-700">{{ formatNumber(row.clocks_online) }}</td>
                                <td class="px-4 py-3 text-right text-rose-700">{{ formatNumber(row.clocks_offline) }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="locationStatusClasses(row.status)">
                                        {{ row.status_label }}
                                    </span>
                                </td>
                                <td v-if="canViewUnitDetails" class="px-5 py-3 text-right">
                                    <button
                                        type="button"
                                        class="inline-flex rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-app transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                                        @click="viewLocationDetail(row.id)"
                                    >
                                        Ver detalle
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <div
                v-if="activeTab === 'actividad' && !showActiveTabSkeleton"
                class="grid gap-6 xl:grid-cols-[1.05fr_0.95fr] xl:items-start"
            >
                <ChartCard
                    title="Linea de tiempo por hora"
                    description="Actividad real del dia"
                    :options="hourlyChartOptions"
                    :dataset="hourlyActivityData"
                    :has-data="hourlyHasData"
                    :chart-key="chartVersion + 2"
                    height-class="h-60 sm:h-64 lg:h-[21rem]"
                    content-class="p-5"
                    empty-text="No hay actividad horaria para el periodo"
                >
                    <template #footer>
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
                            <span>Heartbeat reciente: {{ formatNumber(clockBlock.heartbeat_recent) }}</span>
                            <span v-if="clockBlock.last_reporting_clock">
                                Ultimo reloj: {{ clockBlock.last_reporting_clock.name }}
                            </span>
                            <span v-else>Sin registros recientes</span>
                        </div>
                    </template>
                </ChartCard>

                <article class="card px-5 py-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Actividad reciente
                            </p>
                            <h2 class="mt-1 text-xl font-semibold text-app">
                                Timeline de registros
                            </h2>
                            <p class="mt-1 text-xs text-muted">
                                {{ dashboardTimezoneNote }}
                            </p>
                        </div>
                        <span class="text-sm text-muted">{{ recentActivityHeadline }}</span>
                    </div>

                    <div v-if="recentActivity.length" class="mt-4 space-y-2.5">
                        <article
                            v-for="item in recentActivity"
                            :key="item.id"
                            class="card-subtle px-4 py-3"
                        >
                            <div class="flex items-start gap-4">
                                <div class="w-20 shrink-0 rounded-2xl bg-white px-3 py-3 text-center shadow-sm">
                                    <p class="text-lg font-semibold leading-none text-app sm:text-xl">
                                        {{ formatTime(item.occurred_at) }}
                                    </p>
                                    <p
                                        v-if="formatActivityDateLabel(item.occurred_at)"
                                        class="mt-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-soft"
                                    >
                                        {{ formatActivityDateLabel(item.occurred_at) }}
                                    </p>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <p class="truncate text-sm font-semibold text-app sm:text-base" :title="item.employee_name">
                                            {{ item.employee_name }}
                                        </p>
                                        <span
                                            class="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-700"
                                            :title="resolveAttendanceEventHint(item.event_type ?? item.log_type)"
                                        >
                                            {{ resolveAttendanceEventLabel(item.event_type ?? item.log_type) }}
                                        </span>
                                    </div>

                                    <p class="mt-1 truncate text-xs text-muted sm:text-sm" :title="`${item.unit_name} - ${item.clock_name}`">
                                        {{ item.unit_name }} - {{ item.clock_name }}
                                    </p>

                                    <div class="mt-2 flex flex-wrap gap-2 text-[11px] font-semibold">
                                        <span class="rounded-full bg-slate-900 px-2.5 py-1 text-white">
                                            {{ resolveAttendanceMethodLabel(item.method) }}
                                        </span>
                                        <span
                                            v-if="resolveAttendanceSourceLabel(item)"
                                            class="rounded-full bg-indigo-100 px-2.5 py-1 text-indigo-700"
                                        >
                                            {{ resolveAttendanceSourceLabel(item) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div v-else class="card-subtle mt-4 px-4 py-4 text-sm text-muted">
                        Sin registros recientes en el periodo seleccionado.
                    </div>
                </article>
            </div>

            <article v-if="activeTab === 'enrolamiento' && !showActiveTabSkeleton" class="card px-5 py-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                Enrolamiento biometrico
                            </p>
                            <h2 class="mt-1 text-xl font-semibold text-app">
                                Cobertura de biometria
                            </h2>
                        </div>
                        <span class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">
                            {{ formatPercent(summaryData.charts.enrollment.percentage) }}
                        </span>
                    </div>

                    <div class="mt-5 grid gap-5 lg:grid-cols-[13rem_1fr]">
                        <div class="card-subtle flex flex-col items-center justify-center px-4 py-5">
                            <div class="flex h-36 w-36 items-center justify-center rounded-full p-3" :style="enrollmentRingStyle">
                                <div class="flex h-full w-full flex-col items-center justify-center rounded-full bg-white">
                                    <p class="text-3xl font-semibold text-app">
                                        {{ formatPercent(summaryData.charts.enrollment.percentage) }}
                                    </p>
                                    <p class="mt-1 text-xs uppercase tracking-[0.3em] text-soft">
                                        Cobertura
                                    </p>
                                </div>
                            </div>
                            <p class="mt-4 text-center text-sm text-muted">
                                {{ formatPercent(summaryData.charts.enrollment.percentage) }} del personal activo cuenta con al menos una biometria.
                            </p>
                        </div>

                        <div class="space-y-3">
                            <div class="card-subtle px-4 py-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                    Con al menos una biometria
                                </p>
                                <p class="mt-2 text-3xl font-semibold text-app">
                                    {{ formatNumber(enrollmentBlock.with_any_biometric) }}
                                </p>
                                <p class="text-sm text-muted">
                                    Sobre {{ formatNumber(enrollmentBlock.employees_active) }} empleados activos.
                                </p>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-amber-700">Sin huella</p>
                                    <p class="mt-2 text-3xl font-semibold text-amber-700">{{ formatNumber(enrollmentBlock.without_fingerprint) }}</p>
                                </div>
                                <div class="rounded-2xl border border-sky-100 bg-sky-50 px-4 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-sky-700">Sin Face ID</p>
                                    <p class="mt-2 text-3xl font-semibold text-sky-700">{{ formatNumber(enrollmentBlock.without_face) }}</p>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-rose-700">Sin ningun metodo</p>
                                <p class="mt-2 text-3xl font-semibold text-rose-700">{{ formatNumber(enrollmentBlock.without_any_biometric) }}</p>
                                <div class="mt-4 h-3 overflow-hidden rounded-full bg-white">
                                    <div
                                        class="h-full rounded-full bg-gradient-to-r from-indigo-600 to-cyan-500 transition-all"
                                        :style="{ width: `${Math.min(summaryData.charts.enrollment.percentage ?? 0, 100)}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
            </article>

            <div v-if="activeTab === 'unidades' && !showActiveTabSkeleton" class="grid gap-6">
                <ChartCard
                    title="Volumen por unidad"
                    description="Top sucursales con registros"
                    type="bar"
                    :dataset="topBranchesData"
                    :has-data="topBranchesHasData"
                    :chart-key="chartVersion + 5"
                    height-class="h-56 sm:h-60 lg:h-72"
                    content-class="p-5"
                    empty-text="Sin unidades con registros para el periodo"
                />
            </div>

            <div
                v-if="activeTab === 'resumen' && summaryEmpty && !errorMessage"
                class="card border border-slate-100 bg-white px-5 py-4 text-sm text-muted"
            >
                {{ summaryMessage }}
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

        <UnitDetailDrawer
            :open="detailState.open"
            :detail="detailState.data"
            :loading="detailState.loading"
            :error="detailState.error"
            @close="detailState.open = false"
        />
    </AuthenticatedLayout>
</template>
