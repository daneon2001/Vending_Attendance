<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
import Toast from '@/Components/Toast.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

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
    total: 0,
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

    loading.value = true;
    filters.page = pageNumber;

    try {
        const { data } = await axios.get('/api/audit-logs', {
            params: buildRequestParams(),
        });

        logs.value = data.data ?? [];
        Object.assign(meta, data.meta ?? {});
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Error al cargar bitacora',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
        });
    } finally {
        loading.value = false;
    }
};

const openDetail = async (log) => {
    detailModal.show = true;
    detailModal.loading = true;
    detailModal.log = log;
    detailModal.metadata = null;

    try {
        const { data } = await axios.get(`/api/audit-logs/${log.id}`);
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
        case 'custom':
            if (filters.from && filters.to) {
                return `${filters.from} al ${filters.to}`;
            }
            return 'Rango personalizado';
        default:
            return 'Hoy';
    }
});

watch(
    () => filters.range,
    () => {
        if (filters.range === 'custom') {
            ensureCustomRangeDefaults();
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
            <div class="card flex flex-wrap gap-4 px-4 py-4 text-sm">
                <label class="flex flex-col">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Rango</span>
                    <select
                        v-model="filters.range"
                        class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                    >
                        <option value="today">Hoy</option>
                        <option value="7d">Ultimos 7 dias</option>
                        <option value="30d">Ultimos 30 dias</option>
                        <option value="custom">Personalizado</option>
                    </select>
                </label>

                <label
                    v-if="filters.range === 'custom'"
                    class="flex flex-col"
                >
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Desde</span>
                    <input
                        v-model="filters.from"
                        type="text"
                        inputmode="numeric"
                        placeholder="dd/mm/aaaa"
                        class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                        @blur="normalizeCustomInput('from')"
                    />
                </label>

                <label
                    v-if="filters.range === 'custom'"
                    class="flex flex-col"
                >
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Hasta</span>
                    <input
                        v-model="filters.to"
                        type="text"
                        inputmode="numeric"
                        placeholder="dd/mm/aaaa"
                        class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                        @blur="normalizeCustomInput('to')"
                    />
                </label>

                <label class="flex flex-col min-w-[220px] flex-1">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Usuario</span>
                    <select
                        v-model="filters.user_id"
                        class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
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

                <label class="flex flex-col">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Buscar</span>
                    <input
                        v-model="filters.q"
                        type="text"
                        placeholder="Accion, descripcion..."
                        class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                        @keyup.enter="applyFilters"
                    />
                </label>

                <div class="flex items-end">
                    <button
                        class="rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted hover:text-app"
                        @click="applyFilters"
                    >
                        Aplicar filtro
                    </button>
                </div>
            </div>

            <PaginationBar
                v-if="meta.total"
                class="card"
                :meta="meta"
                :disabled="loading"
                @update:page="handlePageChange"
                @update:perPage="handlePerPageChange"
            />

            <div class="card overflow-hidden p-0">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
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
                        <tbody v-if="logs.length">
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
                                    <p class="text-xs text-muted">{{ log.user?.email ?? '' }}</p>
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
                        <tbody v-else>
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-muted">
                                    No hay eventos registrados para {{ rangeLabel }}.
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
            <div class="flex w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900">
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
                        @click="closeDetail"
                    >
                        <span class="sr-only">Cerrar</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>
                <section class="flex flex-1 flex-col gap-4 overflow-y-auto px-6 py-5 text-sm text-muted">
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
