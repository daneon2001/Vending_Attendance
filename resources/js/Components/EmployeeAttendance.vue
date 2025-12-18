<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    employeeId: {
        type: Number,
        required: true,
    },
});

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

    const pad = (num) => String(num).padStart(2, '0');
    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
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
    const pad = (num) => String(num).padStart(2, '0');

    allLogs.forEach((log) => {
        const date = new Date(log.log_date);
        if (Number.isNaN(date.getTime())) {
            return;
        }

        const key = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        const label = `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;

        if (!groups.has(key)) {
            groups.set(key, { dateKey: key, dateLabel: label, logs: [] });
        }

        groups.get(key).logs.push(log);
    });

    const ordered = Array.from(groups.values()).map((group) => {
        group.logs.sort((a, b) => new Date(a.log_date) - new Date(b.log_date));
        return {
            ...group,
            first: group.logs[0],
            last: group.logs[group.logs.length - 1],
        };
    });

    return ordered.sort((a, b) => new Date(b.first.log_date) - new Date(a.first.log_date));
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

const search = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        const params = {
            from: normalizeBoundary(from.value, true),
            to: normalizeBoundary(to.value, false),
        };

        const { data } = await axios.get(`/api/attendance/employee/${props.employeeId}`, {
            params,
        });
        logs.value = data.data ?? [];
        expandedDays.value = new Set();
    } catch (error) {
        errorMessage.value = 'No se pudieron cargar las asistencias.';
    } finally {
        loading.value = false;
    }
};

watch(
    () => logs.value,
    () => {
        expandedDays.value = new Set();
    },
);
</script>

<template>
    <section class="card space-y-4 p-4">
        <h2 class="text-app text-lg font-semibold">Asistencias</h2>
        <div class="flex flex-wrap gap-3 text-sm">
            <label class="flex flex-col">
                <span class="text-xs font-semibold uppercase text-soft">Desde</span>
                <input v-model="from" type="date" class="rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900" />
            </label>
            <label class="flex flex-col">
                <span class="text-xs font-semibold uppercase text-soft">Hasta</span>
                <input v-model="to" type="date" class="rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900" />
            </label>
            <button
                class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                @click="search"
            >
                Buscar
            </button>
        </div>

        <p v-if="errorMessage" class="text-sm text-rose-600">{{ errorMessage }}</p>

        <div class="overflow-hidden rounded-2xl border border-app">
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
                            <tr class="border-t border-app text-muted">
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
                                                    class="border-t border-app/50 text-muted"
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
                            <td colspan="4" class="px-3 py-4 text-center text-soft">Sin registros.</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>
</template>
