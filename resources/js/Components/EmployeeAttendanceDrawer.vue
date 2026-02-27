<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';
import LoadingState from '@/Components/LoadingState.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ErrorState from '@/Components/ErrorState.vue';

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

const resolveLogDate = (log) => log?.log_date_local ?? log?.log_date ?? null;

const extractTime = (log) => {
    const formatted = log?.log_date_local_display ?? formatDateTime(resolveLogDate(log));
    const parts = formatted.split(' ');
    return parts.length > 1 ? parts[1] : formatted;
};

const groupLogs = (allLogs) => {
    const groups = new Map();

    allLogs.forEach((log) => {
        const date = new Date(resolveLogDate(log));
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
            group.logs.sort((a, b) => new Date(resolveLogDate(a)) - new Date(resolveLogDate(b)));
            return {
                ...group,
                first: group.logs[0],
                last: group.logs[group.logs.length - 1],
            };
        })
        .sort((a, b) => new Date(resolveLogDate(b.first)) - new Date(resolveLogDate(a.first)));
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
            window.addEventListener('keydown', handleKeyDown);
        } else {
            window.removeEventListener('keydown', handleKeyDown);
        }
    },
);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeyDown);
});

useBodyScrollLock(() => props.open);

const drawerTitle = computed(
    () => props.employee?.full_name ?? props.employee?.name ?? `Empleado #${props.employee?.id ?? ''}`,
);
</script>

<template>
    <div v-if="open && employee" class="fixed inset-0 z-40 flex">
        <div class="flex-1 bg-slate-900/60" @click="emit('close')" />
        <aside
            class="relative flex min-w-0 w-full max-w-xl flex-col bg-white/95 px-4 py-6 shadow-2xl transition dark:bg-slate-950/95 sm:px-6"
        >
            <header class="flex items-start justify-between gap-4 border-b border-slate-100 pb-4 dark:border-slate-800">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Asistencias</p>
                    <h2 class="text-2xl font-semibold text-app">
                        {{ drawerTitle }}
                    </h2>
                    <p class="truncate text-sm text-muted" :title="employee.company_name ?? 'Compania no asignada'">
                        {{ employee.company_name ?? 'Compañía no asignada' }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-full border border-app/40 p-2 text-soft hover:text-app"
                    aria-label="Cerrar panel de asistencias"
                    @click="emit('close')"
                >
                    <span class="sr-only">Cerrar</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <section class="mt-5 min-w-0 space-y-4 overflow-y-auto pb-10 text-sm text-muted">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Desde</span>
                        <input
                            v-model="from"
                            type="date"
                            class="w-full rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900"
                        />
                    </label>
                    <label class="flex flex-col">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Hasta</span>
                        <input
                            v-model="to"
                            type="date"
                            class="w-full rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900"
                        />
                    </label>
                    <button
                        type="button"
                        class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-white shadow hover:bg-indigo-500 sm:col-span-2 sm:w-auto sm:justify-self-end"
                        @click="search"
                    >
                        Buscar
                    </button>
                </div>

                <ErrorState
                    v-if="errorMessage && !loading"
                    title="No se pudieron cargar las asistencias"
                    :message="errorMessage"
                    @retry="search"
                />

                <LoadingState v-else-if="loading" title="Cargando asistencias..." :rows="3" />

                <EmptyState
                    v-else-if="!groupedLogs.length"
                    title="Sin asistencias"
                    message="No hay asistencias en el rango seleccionado."
                />

                <div v-else class="rounded-2xl border border-app">
                    <div class="space-y-3 p-3 sm:hidden">
                        <article
                            v-for="group in groupedLogs"
                            :key="`mobile-${group.dateKey}`"
                            class="rounded-2xl border border-app bg-white p-3 dark:bg-slate-900"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-semibold text-app">{{ group.dateLabel }}</p>
                                <button
                                    type="button"
                                    class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold"
                                    :aria-label="expandedDays.has(group.dateKey) ? 'Ocultar detalles del dia' : 'Ver detalles del dia'"
                                    @click="toggleDetails(group.dateKey)"
                                >
                                    {{ expandedDays.has(group.dateKey) ? 'Ocultar' : 'Detalles' }}
                                </button>
                            </div>
                            <dl class="mt-3 space-y-2 text-xs">
                                <div>
                                    <dt class="text-soft">Primer registro</dt>
                                    <dd class="text-app">
                                        {{ extractTime(group.first) }}
                                        <span class="block truncate text-soft" :title="group.first.clock?.name ?? 'Sin reloj'">
                                            {{ group.first.clock?.name ?? 'Sin reloj' }}
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-soft">Ultimo registro</dt>
                                    <dd class="text-app">
                                        {{ extractTime(group.last) }}
                                        <span class="block truncate text-soft" :title="group.last.location?.name ?? 'Sin unidad'">
                                            {{ group.last.location?.name ?? 'Sin unidad' }}
                                        </span>
                                    </dd>
                                </div>
                            </dl>

                            <div v-if="expandedDays.has(group.dateKey)" class="mt-3 space-y-2">
                                <article
                                    v-for="log in group.logs"
                                    :key="`mobile-log-${log.id}`"
                                    class="rounded-xl border border-app bg-slate-50/70 px-3 py-2 text-xs dark:bg-slate-900/60"
                                >
                                    <p class="font-semibold text-app">{{ log.log_date_local_display ?? formatDateTime(resolveLogDate(log)) }}</p>
                                    <p class="mt-1 truncate text-muted" :title="log.clock?.name ?? `#${log.device_id ?? '-'}`">Reloj: {{ log.clock?.name ?? `#${log.device_id ?? '-'}` }}</p>
                                    <p class="truncate text-muted" :title="log.location?.name ?? 'Sin unidad'">Unidad: {{ log.location?.name ?? 'Sin unidad' }}</p>
                                </article>
                            </div>
                        </article>
                    </div>

                    <div class="hidden overflow-x-auto sm:block">
                        <table class="w-full min-w-[42rem] text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft dark:bg-slate-900/40">
                                <tr>
                                    <th class="px-3 py-2">Fecha</th>
                                    <th class="px-3 py-2">Primer registro</th>
                                    <th class="px-3 py-2">Ultimo registro</th>
                                    <th class="px-3 py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="group in groupedLogs" :key="group.dateKey">
                                    <tr class="border-t border-app/40 text-muted">
                                        <td class="px-3 py-2 font-semibold text-app">{{ group.dateLabel }}</td>
                                        <td class="px-3 py-2">
                                            <div class="text-sm text-app">
                                                {{ extractTime(group.first) }}
                                                <span class="block max-w-[10rem] truncate text-xs text-soft" :title="group.first.clock?.name ?? 'Sin reloj'">
                                                    {{ group.first.clock?.name ?? 'Sin reloj' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="text-sm text-app">
                                                {{ extractTime(group.last) }}
                                                <span class="block max-w-[10rem] truncate text-xs text-soft" :title="group.last.location?.name ?? 'Sin unidad'">
                                                    {{ group.last.location?.name ?? 'Sin unidad' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button
                                                type="button"
                                                class="w-full rounded-2xl border border-app px-4 py-2 text-xs font-semibold hover:text-app sm:w-auto"
                                                :aria-label="expandedDays.has(group.dateKey) ? 'Ocultar detalles del dia' : 'Ver detalles del dia'"
                                                @click="toggleDetails(group.dateKey)"
                                            >
                                                {{ expandedDays.has(group.dateKey) ? 'Ocultar' : 'Detalles' }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="expandedDays.has(group.dateKey)">
                                        <td colspan="4" class="bg-slate-50/70 px-3 py-3 dark:bg-slate-900/40">
                                            <div class="rounded-2xl border border-app bg-white p-3 shadow-sm dark:bg-slate-900">
                                                <div class="overflow-x-auto">
                                                    <table class="w-full min-w-[36rem] text-sm">
                                                        <thead class="text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                                                            <tr>
                                                                <th class="px-2 py-1">Fecha/Hora</th>
                                                                <th class="px-2 py-1">Reloj</th>
                                                                <th class="px-2 py-1">Unidad</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr
                                                                v-for="log in group.logs"
                                                                :key="log.id"
                                                                class="border-t border-app/40 text-muted"
                                                            >
                                                                <td class="px-2 py-1">{{ log.log_date_local_display ?? formatDateTime(resolveLogDate(log)) }}</td>
                                                                <td class="px-2 py-1">
                                                                    <span class="block max-w-[12rem] truncate" :title="log.clock?.name ?? `#${log.device_id ?? '-'}`">{{ log.clock?.name ?? `#${log.device_id ?? '-'}` }}</span>
                                                                </td>
                                                                <td class="px-2 py-1">
                                                                    <span class="block max-w-[12rem] truncate" :title="log.location?.name ?? 'Sin unidad'">{{ log.location?.name ?? 'Sin unidad' }}</span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</template>

