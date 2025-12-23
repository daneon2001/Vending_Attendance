<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    employee: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const from = ref('');
const to = ref('');
const logs = ref([]);
const loading = ref(false);
const errorMessage = ref('');
const expandedDays = ref(new Set());

const LOG_TYPE_LABELS = {
    1: 'Entrada',
    2: 'Salida',
    3: 'Break',
    4: 'Regreso',
};

const pad = (num) => String(num).padStart(2, '0');
const formatDateInput = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const formatDisplayDate = (date) => `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;

const setDefaultRange = () => {
    const today = new Date();
    const formatted = formatDateInput(today);
    from.value = formatted;
    to.value = formatted;
};

const normalizeBoundary = (value, isStart) => {
    if (!value) {
        return undefined;
    }

    if (value.includes('T') || value.includes(':')) {
        return value;
    }

    return `${value} ${isStart ? '00:00:00' : '23:59:59'}`;
};

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(
        date.getMinutes(),
    )}:${pad(date.getSeconds())}`;
};

const extractTime = (value) => {
    const formatted = formatDateTime(value);
    const parts = formatted.split(' ');
    return parts.length > 1 ? parts[1] : formatted;
};

const formatLogType = (code) => {
    if (code === null || code === undefined) {
        return 'Desconocido (-)';
    }

    const label = LOG_TYPE_LABELS[code] ?? 'Desconocido';
    return `${label} (${code})`;
};

const groupLogs = (allLogs) => {
    const groups = new Map();

    allLogs.forEach((log) => {
        const date = new Date(log.log_date);
        if (Number.isNaN(date.getTime())) {
            return;
        }

        const key = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        const label = formatDisplayDate(date);

        if (!groups.has(key)) {
            groups.set(key, { dateKey: key, dateLabel: label, logs: [] });
        }

        groups.get(key).logs.push(log);
    });

    return Array.from(groups.values())
        .map((group) => {
            group.logs.sort((a, b) => new Date(a.log_date) - new Date(b.log_date));
            return {
                ...group,
                first: group.logs[0],
                last: group.logs[group.logs.length - 1],
            };
        })
        .sort((a, b) => new Date(b.first.log_date) - new Date(a.first.log_date));
};

const groupedLogs = computed(() => groupLogs(logs.value));

const toggleDetails = (dateKey) => {
    const current = new Set(expandedDays.value);
    if (current.has(dateKey)) {
        current.delete(dateKey);
    } else {
        current.add(dateKey);
    }
    expandedDays.value = current;
};

const resetPanel = () => {
    setDefaultRange();
    logs.value = [];
    errorMessage.value = '';
    expandedDays.value = new Set();
};

const search = async () => {
    if (!props.employee?.id) {
        return;
    }

    loading.value = true;
    errorMessage.value = '';
    try {
        const params = {
            from: normalizeBoundary(from.value, true),
            to: normalizeBoundary(to.value, false),
        };

        const { data } = await axios.get(`/api/attendance/employee/${props.employee.id}`, { params });
        logs.value = data.data ?? [];
        expandedDays.value = new Set();
    } catch (error) {
        errorMessage.value = 'No se pudieron cargar las asistencias.';
    } finally {
        loading.value = false;
    }
};

const handleKeyDown = (event) => {
    if (event.key === 'Escape') {
        emit('close');
    }
};

const toggleBodyScroll = (lock) => {
    if (typeof document === 'undefined') {
        return;
    }
    document.body.classList.toggle('overflow-hidden', lock);
};

watch(
    () => [props.open, props.employee?.id],
    async ([open, employeeId], [prevOpen, prevEmployeeId]) => {
        if (open && employeeId && (!prevOpen || prevEmployeeId !== employeeId)) {
            resetPanel();
            await search();
        }

        if (!open && prevOpen) {
            logs.value = [];
            expandedDays.value = new Set();
        }
    },
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            toggleBodyScroll(true);
            window.addEventListener('keydown', handleKeyDown);
        } else {
            toggleBodyScroll(false);
            window.removeEventListener('keydown', handleKeyDown);
        }
    },
);

onBeforeUnmount(() => {
    toggleBodyScroll(false);
    window.removeEventListener('keydown', handleKeyDown);
});

const drawerTitle = computed(
    () => props.employee?.full_name ?? props.employee?.name ?? `Empleado #${props.employee?.id ?? ''}`,
);
</script>

<template>
    <div v-if="open && employee" class="fixed inset-0 z-40 flex">
        <div class="flex-1 bg-slate-900/60" @click="emit('close')" />
        <aside
            class="relative flex w-full max-w-xl flex-col bg-white/95 px-6 py-6 shadow-2xl transition dark:bg-slate-950/95"
        >
            <header class="flex items-start justify-between gap-4 border-b border-slate-100 pb-4 dark:border-slate-800">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Asistencias</p>
                    <h2 class="text-2xl font-semibold text-app">
                        {{ drawerTitle }}
                    </h2>
                    <p class="text-sm text-muted">
                        {{ employee.company_name ?? 'Compañía no asignada' }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-full border border-app/40 p-2 text-soft hover:text-app"
                    @click="emit('close')"
                >
                    <span class="sr-only">Cerrar</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <section class="mt-5 space-y-4 overflow-y-auto pb-10 text-sm text-muted">
                <div class="flex flex-wrap gap-3">
                    <label class="flex flex-col">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Desde</span>
                        <input
                            v-model="from"
                            type="date"
                            class="rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900"
                        />
                    </label>
                    <label class="flex flex-col">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Hasta</span>
                        <input
                            v-model="to"
                            type="date"
                            class="rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900"
                        />
                    </label>
                    <button
                        class="self-end rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white shadow hover:bg-indigo-500"
                        @click="search"
                    >
                        Buscar
                    </button>
                </div>

                <p v-if="errorMessage" class="text-sm text-rose-600">
                    {{ errorMessage }}
                </p>

                <div class="rounded-2xl border border-app">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft dark:bg-slate-900/40">
                            <tr>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Primer registro</th>
                                <th class="px-3 py-2">Último registro</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="4" class="px-3 py-4 text-center text-soft">Cargando...</td>
                            </tr>
                            <template v-else>
                                <template v-for="group in groupedLogs" :key="group.dateKey">
                                    <tr class="border-t border-app/40 text-muted">
                                        <td class="px-3 py-2 font-semibold text-app">{{ group.dateLabel }}</td>
                                        <td class="px-3 py-2">
                                            <div class="text-sm text-app">
                                                {{ extractTime(group.first.log_date) }}
                                                <span class="text-xs text-soft">· {{ formatLogType(group.first.log_type) }}</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="text-sm text-app">
                                                {{ extractTime(group.last.log_date) }}
                                                <span class="text-xs text-soft">· {{ formatLogType(group.last.log_type) }}</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button
                                                class="rounded-2xl border border-app px-4 py-1 text-xs font-semibold hover:text-app"
                                                @click="toggleDetails(group.dateKey)"
                                            >
                                                {{ expandedDays.has(group.dateKey) ? 'Ocultar' : 'Detalles' }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="expandedDays.has(group.dateKey)">
                                        <td colspan="4" class="bg-slate-50/70 px-3 py-3 dark:bg-slate-900/40">
                                            <div class="rounded-2xl border border-app bg-white p-3 shadow-sm dark:bg-slate-900">
                                                <table class="w-full text-sm">
                                                    <thead class="text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                                        <tr>
                                                            <th class="px-2 py-1">Fecha/Hora</th>
                                                            <th class="px-2 py-1">Reloj</th>
                                                            <th class="px-2 py-1">Tipo</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr
                                                            v-for="log in group.logs"
                                                            :key="log.id"
                                                            class="border-t border-app/40 text-muted"
                                                        >
                                                            <td class="px-2 py-1">{{ formatDateTime(log.log_date) }}</td>
                                                            <td class="px-2 py-1">{{ log.device_id ?? '-' }}</td>
                                                            <td class="px-2 py-1">{{ formatLogType(log.log_type) }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!groupedLogs.length">
                                    <td colspan="4" class="px-3 py-6 text-center text-soft">
                                        Sin asistencias en el rango seleccionado.
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>
        </aside>
    </div>
</template>
