<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
import Toast from '@/Components/Toast.vue';
import LoadingState from '@/Components/LoadingState.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ErrorState from '@/Components/ErrorState.vue';
import { apiUrl } from '@/utils/url';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const AUDIT_LOGS_ENDPOINT = '/settings/audit-logs';
const AUDIT_PURGE_ENDPOINT = '/settings/audit-logs/purge';
const REQUEST_TIMEOUT_MS = 15000;
const DETAIL_TIMEOUT_MS = 10000;

const props = defineProps({
    users: {
        type: Array,
        default: () => [],
    },
});

const logs = ref([]);
const meta = reactive({
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0,
    total: null,
});

const filters = reactive({
    range: 'today',
    from: '',
    to: '',
    user_id: '',
    event: '',
    q: '',
    perPage: 15,
    page: 1,
});

const loading = ref(false);
const loadError = ref('');
const loadDurationMs = ref(null);

let activeLoadController = null;
let activeLoadTimeout = null;
let loadSequence = 0;

const toast = reactive({
    show: false,
    type: 'info',
    title: '',
    message: '',
    duration: 5000,
});

const detailModal = reactive({
    show: false,
    loading: false,
    log: null,
    metadata: null,
});

const purgeForm = reactive({
    mode: 'keep_last_days',
    before_date: '',
    keep_days: 90,
    date_from: '',
    date_to: '',
    dry_run: true,
    optimize: false,
});

const purgeBusy = reactive({
    submit: false,
});

const purgeResult = ref(null);

useBodyScrollLock(() => detailModal.show);

const userOptions = computed(() => props.users ?? []);

const showToast = ({ type = 'info', title = '', message = '', duration }) => {
    toast.show = false;
    toast.type = type;
    toast.title = title;
    toast.message = message;
    toast.duration = duration ?? (type === 'error' ? 9000 : 5000);
    requestAnimationFrame(() => {
        toast.show = true;
    });
};

const pad = (value) => String(value).padStart(2, '0');

const formatDisplayDate = (date) => `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;

const parseDisplayDate = (value) => {
    if (!value) return null;
    const [day, month, year] = value.split('/');
    if (!day || !month || !year) {
        return null;
    }
    const parsed = new Date(Number(year), Number(month) - 1, Number(day));
    return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const ensureCustomRangeDefaults = () => {
    const today = new Date();
    if (!filters.from) {
        filters.from = formatDisplayDate(today);
    }
    if (!filters.to) {
        filters.to = formatDisplayDate(today);
    }
};

const normalizeCustomInput = (field) => {
    const parsed = parseDisplayDate(filters[field]);
    filters[field] = parsed ? formatDisplayDate(parsed) : '';
};

const buildRequestParams = () => {
    const params = {
        range: filters.range,
        event: filters.event || undefined,
        user_id: filters.user_id || undefined,
        q: filters.q || undefined,
        page: filters.page,
        per_page: filters.perPage,
    };

    if (filters.range === 'custom') {
        const fromDate = parseDisplayDate(filters.from);
        const toDate = parseDisplayDate(filters.to);

        if (fromDate && toDate) {
            const [start, end] = fromDate <= toDate ? [fromDate, toDate] : [toDate, fromDate];
            params.from = formatDisplayDate(start);
            params.to = formatDisplayDate(end);
        }
    }

    return params;
};

const clearLoadRequest = () => {
    if (activeLoadTimeout) {
        clearTimeout(activeLoadTimeout);
        activeLoadTimeout = null;
    }

    if (activeLoadController) {
        activeLoadController.abort('replaced');
        activeLoadController = null;
    }
};

const isAbortError = (error) =>
    error?.code === 'ERR_CANCELED'
    || error?.name === 'CanceledError'
    || error?.name === 'AbortError';

const loadLogs = async (pageNumber = filters.page) => {
    if (filters.range === 'custom') {
        const fromDate = parseDisplayDate(filters.from);
        const toDate = parseDisplayDate(filters.to);

        if (!fromDate || !toDate) {
            showToast({
                type: 'error',
                title: 'Selecciona el rango',
                message: 'Debes capturar fechas validas (dd/mm/aaaa) al usar Rango personalizado.',
            });
            return;
        }

        if (fromDate > toDate) {
            filters.from = formatDisplayDate(toDate);
            filters.to = formatDisplayDate(fromDate);
        } else {
            filters.from = formatDisplayDate(fromDate);
            filters.to = formatDisplayDate(toDate);
        }
    }

    clearLoadRequest();

    loading.value = true;
    loadError.value = '';
    loadDurationMs.value = null;
    filters.page = pageNumber;
    const requestId = ++loadSequence;
    activeLoadController = new AbortController();
    activeLoadTimeout = setTimeout(() => {
        activeLoadController?.abort('timeout');
    }, REQUEST_TIMEOUT_MS);

    try {
        const { data } = await axios.get(apiUrl(AUDIT_LOGS_ENDPOINT), {
            params: buildRequestParams(),
            signal: activeLoadController.signal,
        });

        if (requestId !== loadSequence) {
            return;
        }

        logs.value = data.data ?? [];
        Object.assign(meta, data.meta ?? {});
        loadDurationMs.value = data.meta?.duration_ms ?? null;
    } catch (error) {
        if (requestId !== loadSequence) {
            return;
        }

        if (isAbortError(error)) {
            if (error?.message === 'canceled' || error?.config?.signal?.reason === 'replaced') {
                return;
            }

            loadError.value = 'La consulta de auditoria tardo demasiado. Ajusta los filtros e intenta nuevamente.';
        } else {
            loadError.value = error.response?.data?.message ?? 'Intenta nuevamente.';
        }

        showToast({
            type: 'error',
            title: 'Error al cargar bitacora',
            message: loadError.value,
        });
    } finally {
        if (requestId === loadSequence) {
            loading.value = false;
        }
        if (activeLoadTimeout) {
            clearTimeout(activeLoadTimeout);
            activeLoadTimeout = null;
        }
        activeLoadController = null;
    }
};

const openDetail = async (log) => {
    detailModal.show = true;
    detailModal.loading = true;
    detailModal.log = log;
    detailModal.metadata = null;

    try {
        const { data } = await axios.get(apiUrl(`${AUDIT_LOGS_ENDPOINT}/${log.id}`), {
            timeout: DETAIL_TIMEOUT_MS,
        });
        detailModal.metadata = data.data ?? null;
    } catch (error) {
        detailModal.metadata = null;
        showToast({
            type: 'error',
            title: 'No se pudo obtener el detalle',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
        });
    } finally {
        detailModal.loading = false;
    }
};

const closeDetail = () => {
    detailModal.show = false;
    detailModal.log = null;
    detailModal.metadata = null;
};

const applyFilters = () => loadLogs(1);

const purgeSummary = computed(() => {
    switch (purgeForm.mode) {
        case 'before_date':
            return purgeForm.before_date
                ? `Se evaluaran registros anteriores a ${purgeForm.before_date}.`
                : 'Selecciona una fecha de corte.';
        case 'delete_by_range':
            return purgeForm.date_from && purgeForm.date_to
                ? `Se evaluara el rango ${purgeForm.date_from} a ${purgeForm.date_to}.`
                : 'Captura fecha inicial y final.';
        default:
            return `Se conservaran al menos ${purgeForm.keep_days} dias recientes.`;
    }
});

const handlePageChange = (pageNumber) => {
    if (loading.value) return;
    loadLogs(pageNumber);
};

const handlePerPageChange = (perPage) => {
    if (filters.perPage === perPage) return;
    filters.perPage = perPage;
    loadLogs(1);
};

const formatDateTime = (value) => {
    if (!value) return '--';
    const date = new Date(value);
    const day = pad(date.getDate());
    const month = pad(date.getMonth() + 1);
    const year = date.getFullYear();
    const hours = pad(date.getHours());
    const minutes = pad(date.getMinutes());
    const seconds = pad(date.getSeconds());
    return `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
};

const formatAuditDate = (entry) => {
    if (!entry) return '--';
    if (entry.created_at_local) {
        return entry.created_at_local;
    }
    return formatDateTime(entry.created_at);
};

const rangeLabel = computed(() => {
    switch (filters.range) {
        case '7d':
            return 'Ultimos 7 dias';
        case '30d':
            return 'Ultimos 30 dias';
        case 'all':
            return 'Todo el historial';
        case 'custom':
            if (filters.from && filters.to) {
                return `${filters.from} al ${filters.to}`;
            }
            return 'Rango personalizado';
        default:
            return 'Hoy';
    }
});

const runPurge = async () => {
    if (purgeBusy.submit) {
        return;
    }

    if (!purgeForm.dry_run) {
        const confirmed = window.confirm('Se ejecutara una limpieza real de audit_logs. Confirma que deseas continuar.');
        if (!confirmed) {
            return;
        }
    }

    purgeBusy.submit = true;

    try {
        const { data } = await axios.post(
            apiUrl(AUDIT_PURGE_ENDPOINT),
            {
                mode: purgeForm.mode,
                before_date: purgeForm.before_date || null,
                keep_days: Number(purgeForm.keep_days || 0),
                date_from: purgeForm.date_from || null,
                date_to: purgeForm.date_to || null,
                dry_run: Boolean(purgeForm.dry_run),
                optimize: Boolean(purgeForm.optimize),
            },
            {
                timeout: REQUEST_TIMEOUT_MS,
            },
        );

        purgeResult.value = data.data ?? null;

        if (!purgeForm.dry_run) {
            await loadLogs(1);
        }

        showToast({
            type: 'success',
            title: purgeForm.dry_run ? 'Simulacion completada' : 'Limpieza completada',
            message: data.message ?? 'Proceso terminado.',
            duration: 7000,
        });
    } catch (error) {
        const message = error.response?.data?.message
            ?? (error?.code === 'ECONNABORTED' ? 'La limpieza tardo demasiado. Intenta con un rango mas acotado.' : 'Intenta nuevamente.');

        showToast({
            type: 'error',
            title: 'No se pudo ejecutar la limpieza',
            message,
            duration: 9000,
        });
    } finally {
        purgeBusy.submit = false;
    }
};

watch(
    () => filters.range,
    () => {
        if (filters.range === 'custom') {
            ensureCustomRangeDefaults();
        } else if (filters.range === 'all') {
            loadLogs(1);
        } else {
            loadLogs(1);
        }
    },
);

onMounted(() => {
    ensureCustomRangeDefaults();
    loadLogs();
});
</script>

<template>
    <Head title="Bitacora de auditoria" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-2xl font-semibold">Configuracion  Bitacora</h1>
                <p class="text-sm text-muted">
                    Registra cada accion relevante realizada por los usuarios.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
                <div class="card flex flex-wrap gap-4 px-4 py-4 text-sm">
                    <label class="flex w-full flex-col sm:w-auto">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Rango</span>
                        <select
                            v-model="filters.range"
                        class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900 sm:w-auto"
                    >
                        <option value="today">Hoy</option>
                        <option value="7d">Ultimos 7 dias</option>
                        <option value="30d">Ultimos 30 dias</option>
                        <option value="all">Todo</option>
                        <option value="custom">Personalizado</option>
                    </select>
                </label>

                <label
                    v-if="filters.range === 'custom'"
                    class="flex w-full flex-col sm:w-auto"
                >
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Desde</span>
                    <input
                        v-model="filters.from"
                        type="text"
                        inputmode="numeric"
                        placeholder="dd/mm/aaaa"
                        class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900 sm:w-auto"
                        @blur="normalizeCustomInput('from')"
                    />
                </label>

                <label
                    v-if="filters.range === 'custom'"
                    class="flex w-full flex-col sm:w-auto"
                >
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Hasta</span>
                    <input
                        v-model="filters.to"
                        type="text"
                        inputmode="numeric"
                        placeholder="dd/mm/aaaa"
                        class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900 sm:w-auto"
                        @blur="normalizeCustomInput('to')"
                    />
                </label>

                <label class="flex w-full flex-col flex-1 sm:min-w-[16rem]">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Usuario</span>
                    <select
                        v-model="filters.user_id"
                        class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="user in userOptions"
                            :key="user.id"
                            :value="user.id"
                        >
                            {{ user.name }}  {{ user.email }}
                        </option>
                    </select>
                </label>

                <label class="flex w-full flex-col sm:w-auto sm:min-w-[16rem]">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Buscar</span>
                    <input
                        v-model="filters.q"
                        type="text"
                        placeholder="Accion, descripcion..."
                        class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                        @keyup.enter="applyFilters"
                    />
                </label>

                <div class="flex w-full items-end sm:w-auto">
                    <button
                        class="w-full rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted hover:text-app sm:w-auto"
                        :disabled="loading"
                        @click="applyFilters"
                    >
                        Aplicar filtro
                    </button>
                </div>

                    <div class="w-full text-xs text-soft">
                        <span v-if="loadDurationMs !== null">Ultima consulta: {{ loadDurationMs }} ms</span>
                    </div>
                </div>

                <div class="card p-4 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Limpieza segura</p>
                            <h2 class="text-lg font-semibold text-app">Purgar auditoria</h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-widest text-soft dark:bg-slate-800">
                            Dry-run disponible
                        </span>
                    </div>

                    <div class="mt-4 grid gap-3">
                        <label class="flex flex-col">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Modo</span>
                            <select
                                v-model="purgeForm.mode"
                                class="mt-1 w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                            >
                                <option value="keep_last_days">Conservar ultimos dias</option>
                                <option value="before_date">Borrar antes de fecha</option>
                                <option value="delete_by_range">Borrar por rango</option>
                            </select>
                        </label>

                        <label v-if="purgeForm.mode === 'keep_last_days'" class="flex flex-col">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Conservar dias</span>
                            <input
                                v-model.number="purgeForm.keep_days"
                                type="number"
                                min="90"
                                max="3650"
                                class="mt-1 w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                            />
                        </label>

                        <label v-if="purgeForm.mode === 'before_date'" class="flex flex-col">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Fecha corte</span>
                            <input
                                v-model="purgeForm.before_date"
                                type="date"
                                class="mt-1 w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                            />
                        </label>

                        <div v-if="purgeForm.mode === 'delete_by_range'" class="grid gap-3 sm:grid-cols-2">
                            <label class="flex flex-col">
                                <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Desde</span>
                                <input
                                    v-model="purgeForm.date_from"
                                    type="date"
                                    class="mt-1 w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                                />
                            </label>
                            <label class="flex flex-col">
                                <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Hasta</span>
                                <input
                                    v-model="purgeForm.date_to"
                                    type="date"
                                    class="mt-1 w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                                />
                            </label>
                        </div>

                        <label class="flex items-center gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="purgeForm.dry_run" type="checkbox" class="rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span class="text-sm text-app">Ejecutar primero como simulacion</span>
                        </label>

                        <label class="flex items-center gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="purgeForm.optimize" type="checkbox" class="rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span class="text-sm text-app">Optimizar tabla si aplica</span>
                        </label>
                    </div>

                    <div class="mt-4 rounded-2xl border border-sky-100 bg-sky-50 px-4 py-3 text-xs text-sky-800 dark:border-sky-500/30 dark:bg-sky-900/20 dark:text-sky-100">
                        {{ purgeSummary }}
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="rounded-2xl bg-slate-900 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white disabled:cursor-not-allowed disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900"
                            :disabled="purgeBusy.submit"
                            @click="runPurge"
                        >
                            {{ purgeBusy.submit ? 'Procesando...' : (purgeForm.dry_run ? 'Simular limpieza' : 'Ejecutar limpieza') }}
                        </button>
                    </div>

                    <div v-if="purgeResult" class="mt-4 rounded-2xl border border-app px-4 py-3 text-xs text-muted">
                        <p><strong class="text-app">Detectados:</strong> {{ purgeResult.total_detected }}</p>
                        <p><strong class="text-app">Eliminados:</strong> {{ purgeResult.total_deleted }}</p>
                        <p><strong class="text-app">Espacio estimado:</strong> {{ purgeResult.estimated_bytes_human }}</p>
                        <p><strong class="text-app">Duracion:</strong> {{ purgeResult.duration_ms }} ms</p>
                    </div>
                </div>
            </div>

            <PaginationBar
                v-if="meta.current_page > 1 || meta.to || loading"
                class="card"
                :meta="meta"
                :per-page-options="[10, 15, 25]"
                :disabled="loading"
                @update:page="handlePageChange"
                @update:perPage="handlePerPageChange"
            />

            <ErrorState
                v-if="loadError && !loading"
                title="No se pudo cargar la bitacora"
                :message="loadError"
                @retry="loadLogs(filters.page)"
            />

            <LoadingState v-else-if="loading" title="Cargando bitacora..." :rows="5" />

            <EmptyState
                v-else-if="!logs.length"
                title="Sin eventos"
                :message="`No hay eventos registrados para ${rangeLabel}.`"
            />

            <div v-else class="card overflow-hidden p-0">
                <div class="space-y-3 p-3 sm:hidden">
                    <article
                        v-for="log in logs"
                        :key="`mobile-${log.id}`"
                        class="rounded-2xl border border-app bg-white p-3"
                    >
                        <p class="text-xs text-soft">{{ formatAuditDate(log) }}</p>
                        <p class="mt-1 text-sm font-semibold text-app">{{ log.user?.name ?? 'Sistema' }}</p>
                        <p class="truncate text-xs text-muted" :title="log.user?.email ?? ''">{{ log.user?.email ?? '' }}</p>
                        <span class="mt-2 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-soft dark:bg-slate-800">
                            {{ log.event }}
                        </span>
                        <p class="mt-2 line-clamp-3 text-sm text-muted">{{ log.description ?? '' }}</p>
                        <button
                            type="button"
                            class="mt-3 w-full rounded-2xl border border-app px-3 py-2 text-xs font-semibold text-indigo-600"
                            @click="openDetail(log)"
                        >
                            Ver detalles
                        </button>
                    </article>
                </div>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full min-w-[72rem] text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-[0.3em] text-soft">
                                <th class="px-4 py-3">Fecha</th>
                                <th class="px-4 py-3">Usuario</th>
                                <th class="px-4 py-3">Accion</th>
                                <th class="px-4 py-3">Entidad</th>
                                <th class="px-4 py-3">Descripcion</th>
                                <th class="px-4 py-3 text-right">Detalles</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="log in logs"
                                :key="log.id"
                                class="border-b border-slate-100 last:border-b-0 dark:border-slate-800"
                            >
                                <td class="px-4 py-3">
                                    {{ formatAuditDate(log) }}
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-app">{{ log.user?.name ?? 'Sistema' }}</p>
                                    <p class="max-w-[14rem] truncate text-xs text-muted" :title="log.user?.email ?? ''">{{ log.user?.email ?? '' }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-soft dark:bg-slate-800">
                                        {{ log.event }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs text-muted">
                                        {{ log.auditable_type ?? '' }}
                                        <template v-if="log.auditable_id">#{{ log.auditable_id }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="line-clamp-2 text-sm text-muted">{{ log.description ?? '' }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-indigo-600 hover:text-indigo-500"
                                        @click="openDetail(log)"
                                    >
                                        Ver detalles
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </section>

        <div
            v-if="detailModal.show"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/70 px-4 py-8"
        >
            <div class="flex w-full max-w-3xl max-h-[90vh] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900">
                <header class="flex items-center justify-between border-b border-slate-100 px-6 py-4 dark:border-slate-800">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            Detalle del evento
                        </p>
                        <h3 class="text-xl font-semibold text-app">
                            {{ detailModal.log?.event }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-app p-2 text-soft hover:text-app dark:hover:text-white"
                        aria-label="Cerrar detalle de auditoria"
                        @click="closeDetail"
                    >
                        <span class="sr-only">Cerrar</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>
                <section class="flex flex-1 flex-col gap-4 overflow-y-auto px-4 py-5 text-sm text-muted sm:px-6">
                    <div v-if="detailModal.loading">
                        Cargando detalle...
                    </div>
                    <template v-else-if="detailModal.metadata">
                        <p>
                            <strong class="text-app">Fecha:</strong>
                            {{ formatAuditDate(detailModal.metadata) }}
                        </p>
                        <p>
                            <strong class="text-app">Usuario:</strong>
                            {{ detailModal.metadata.user?.name ?? 'Sistema' }}  {{ detailModal.metadata.user?.email ?? '' }}
                        </p>
                        <p>
                            <strong class="text-app">Descripcion:</strong>
                            {{ detailModal.metadata.description ?? '' }}
                        </p>
                        <p>
                            <strong class="text-app">IP:</strong> {{ detailModal.metadata.ip_address ?? '' }}
                        </p>
                        <p>
                            <strong class="text-app">User Agent:</strong>
                            <span class="break-words">{{ detailModal.metadata.user_agent ?? '' }}</span>
                        </p>
                        <div>
                            <strong class="text-app">Metadata:</strong>
                            <pre class="mt-2 max-h-64 overflow-auto rounded-2xl bg-slate-100 px-4 py-3 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-100">
{{ JSON.stringify(detailModal.metadata.metadata ?? {}, null, 2) }}
                            </pre>
                        </div>
                    </template>
                    <div v-else>
                        No hay informacion adicional para este evento.
                    </div>
                </section>
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
