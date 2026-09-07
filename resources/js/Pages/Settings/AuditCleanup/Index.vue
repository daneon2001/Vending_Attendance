<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Toast from '@/Components/Toast.vue';
import { apiUrl } from '@/utils/url';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    settings: {
        type: Object,
        default: () => ({}),
    },
    dashboard: {
        type: Object,
        default: () => ({}),
    },
});

const toast = reactive({
    show: false,
    type: 'info',
    title: '',
    message: '',
    duration: 5000,
});

const busy = reactive({
    save: false,
    preview: false,
    execute: false,
    refresh: false,
});

const settingsForm = reactive({
    critical_retention_days: Number(props.settings?.critical_retention_days ?? 3650),
    important_retention_days: Number(props.settings?.important_retention_days ?? 180),
    noise_retention_days: Number(props.settings?.noise_retention_days ?? 7),
    batch_size: Number(props.settings?.batch_size ?? 5000),
    heartbeat_log_interval_minutes: Number(props.settings?.heartbeat_log_interval_minutes ?? 30),
    optimize_min_deleted_mb: Number(props.settings?.optimize_min_deleted_mb ?? 512),
});

const selectionForm = reactive({
    delete_heartbeats: false,
    delete_sync: false,
    delete_old_logins: false,
    delete_noise: false,
    delete_except_critical: false,
    before_date: '',
    target_free_mb: 0,
    optimize: false,
    simulate: true,
});

const dashboardState = ref(props.dashboard ?? {});
const previewResult = ref(null);
const executionResult = ref(null);

const stats = computed(() => dashboardState.value?.stats ?? {});
const topEvents = computed(() => dashboardState.value?.top_events ?? []);
const latestCleanup = computed(() => stats.value?.latest_cleanup ?? null);

const showToast = ({ type = 'info', title = '', message = '', duration = 5000 }) => {
    toast.show = false;
    toast.type = type;
    toast.title = title;
    toast.message = message;
    toast.duration = duration;

    requestAnimationFrame(() => {
        toast.show = true;
    });
};

const formatNumber = (value) => {
    const numeric = Number(value ?? 0);
    return Number.isFinite(numeric) ? numeric.toLocaleString('es-MX') : '0';
};

const formatDateTime = (value) => {
    if (!value) return '--';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'medium',
    }).format(date);
};

const categoryClass = (category) => {
    switch (category) {
        case 'critical':
            return 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/15 dark:text-rose-100 dark:ring-rose-500/30';
        case 'noise':
            return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-100 dark:ring-amber-500/30';
        default:
            return 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-100 dark:ring-sky-500/30';
    }
};

const buildPayload = () => ({
    settings: {
        critical_retention_days: Number(settingsForm.critical_retention_days),
        important_retention_days: Number(settingsForm.important_retention_days),
        noise_retention_days: Number(settingsForm.noise_retention_days),
        batch_size: Number(settingsForm.batch_size),
        heartbeat_log_interval_minutes: Number(settingsForm.heartbeat_log_interval_minutes),
        optimize_min_deleted_mb: Number(settingsForm.optimize_min_deleted_mb),
    },
    selection: {
        delete_heartbeats: Boolean(selectionForm.delete_heartbeats),
        delete_sync: Boolean(selectionForm.delete_sync),
        delete_old_logins: Boolean(selectionForm.delete_old_logins),
        delete_noise: Boolean(selectionForm.delete_noise),
        delete_except_critical: Boolean(selectionForm.delete_except_critical),
        before_date: selectionForm.before_date || null,
        target_free_mb: Number(selectionForm.target_free_mb || 0),
        optimize: Boolean(selectionForm.optimize),
        simulate: Boolean(selectionForm.simulate),
    },
});

const refreshDashboard = async () => {
    busy.refresh = true;

    try {
        const { data } = await axios.get(apiUrl('/api/audit-cleanup/dashboard'));
        dashboardState.value = data.data ?? {};
    } catch (error) {
        showToast({
            type: 'error',
            title: 'No se pudo actualizar el tablero',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
            duration: 7000,
        });
    } finally {
        busy.refresh = false;
    }
};

const saveSettings = async () => {
    busy.save = true;

    try {
        const { data } = await axios.put(apiUrl('/api/audit-cleanup/settings'), {
            critical_retention_days: Number(settingsForm.critical_retention_days),
            important_retention_days: Number(settingsForm.important_retention_days),
            noise_retention_days: Number(settingsForm.noise_retention_days),
            batch_size: Number(settingsForm.batch_size),
            heartbeat_log_interval_minutes: Number(settingsForm.heartbeat_log_interval_minutes),
            optimize_min_deleted_mb: Number(settingsForm.optimize_min_deleted_mb),
        });

        Object.assign(settingsForm, data.data ?? {});
        await refreshDashboard();

        showToast({
            type: 'success',
            title: 'Configuracion guardada',
            message: data.message ?? 'La politica de limpieza quedo actualizada.',
        });
    } catch (error) {
        showToast({
            type: 'error',
            title: 'No se pudo guardar la configuracion',
            message: error.response?.data?.message ?? 'Revisa los campos e intenta nuevamente.',
            duration: 7000,
        });
    } finally {
        busy.save = false;
    }
};

const previewCleanup = async () => {
    busy.preview = true;

    try {
        const { data } = await axios.post(apiUrl('/api/audit-cleanup/preview'), buildPayload());
        previewResult.value = data.data ?? null;

        showToast({
            type: 'success',
            title: 'Simulacion completada',
            message: 'Ya tienes una estimacion de registros y espacio a liberar.',
        });
    } catch (error) {
        showToast({
            type: 'error',
            title: 'No se pudo simular la limpieza',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
            duration: 7000,
        });
    } finally {
        busy.preview = false;
    }
};

const executeCleanup = async () => {
    const confirmed = window.confirm('Se eliminara la bitacora elegible segun la configuracion actual. Deseas continuar?');
    if (!confirmed) {
        return;
    }

    busy.execute = true;

    try {
        const { data } = await axios.post(apiUrl('/api/audit-cleanup/execute'), buildPayload());
        executionResult.value = data.data ?? null;
        previewResult.value = data.data ?? null;
        await refreshDashboard();

        showToast({
            type: 'success',
            title: 'Limpieza completada',
            message: data.message ?? 'La purga segura termino correctamente.',
            duration: 7000,
        });
    } catch (error) {
        showToast({
            type: 'error',
            title: 'No se pudo ejecutar la limpieza',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
            duration: 7000,
        });
    } finally {
        busy.execute = false;
    }
};

const selectionSummary = computed(() => {
    const enabled = [
        selectionForm.delete_heartbeats ? 'heartbeats' : null,
        selectionForm.delete_sync ? 'sync' : null,
        selectionForm.delete_old_logins ? 'logins' : null,
        selectionForm.delete_noise ? 'ruido' : null,
        selectionForm.delete_except_critical ? 'todo excepto criticos' : null,
    ].filter(Boolean);

    if (enabled.length === 0 && !selectionForm.before_date) {
        return 'Sin filtros manuales: se aplicara la retencion configurada.';
    }

    return enabled.length > 0
        ? `Filtros activos: ${enabled.join(', ')}.`
        : 'Filtro activo: por fecha.';
});
</script>

<template>
    <Head title="Limpieza de Bitacora" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-2xl font-semibold">Limpieza de Bitacora</h1>
                <p class="text-sm text-muted">Reduce el crecimiento de `audit_logs` sin afectar los eventos criticos ni el flujo funcional del heartbeat.</p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Tamano actual</p>
                    <p class="mt-3 text-3xl font-semibold text-app">{{ stats.size_human ?? '0 B' }}</p>
                    <p class="mt-2 text-sm text-muted">Promedio por registro: {{ stats.avg_row_bytes ? formatNumber(stats.avg_row_bytes) + ' bytes' : '--' }}</p>
                </article>

                <article class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Registros</p>
                    <p class="mt-3 text-3xl font-semibold text-app">{{ formatNumber(stats.row_count) }}</p>
                    <p class="mt-2 text-sm text-muted">Total actual de filas en `audit_logs`.</p>
                </article>

                <article class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Borrados acumulados</p>
                    <p class="mt-3 text-3xl font-semibold text-app">{{ formatNumber(stats.deleted_records_total) }}</p>
                    <p class="mt-2 text-sm text-muted">Espacio estimado liberado: {{ stats.freed_bytes_total_human ?? '0 B' }}</p>
                </article>

                <article class="card p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Ultima ejecucion</p>
                    <p class="mt-3 text-lg font-semibold text-app">
                        {{ latestCleanup ? formatDateTime(latestCleanup.finished_at ?? latestCleanup.created_at) : '--' }}
                    </p>
                    <p class="mt-2 text-sm text-muted">
                        {{ latestCleanup ? `Estado: ${latestCleanup.status}. Registros: ${formatNumber(latestCleanup.deleted_records)}` : 'Aun no hay ejecuciones registradas.' }}
                    </p>
                </article>
            </div>

            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                <article class="card p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Politica base</p>
                            <h2 class="text-xl font-semibold text-app">Retencion y muestreo</h2>
                        </div>
                        <button
                            type="button"
                            class="rounded-2xl border border-app px-4 py-2 text-sm font-semibold text-app transition hover:bg-slate-50 dark:hover:bg-slate-900"
                            :disabled="busy.refresh"
                            @click="refreshDashboard"
                        >
                            {{ busy.refresh ? 'Actualizando...' : 'Actualizar tablero' }}
                        </button>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Retencion criticos (dias)</span>
                            <input v-model.number="settingsForm.critical_retention_days" type="number" min="1" max="3650" class="input w-full" />
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Retencion importantes (dias)</span>
                            <input v-model.number="settingsForm.important_retention_days" type="number" min="1" max="3650" class="input w-full" />
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Retencion ruido (dias)</span>
                            <input v-model.number="settingsForm.noise_retention_days" type="number" min="1" max="3650" class="input w-full" />
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Batch delete</span>
                            <input v-model.number="settingsForm.batch_size" type="number" min="100" max="50000" class="input w-full" />
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Registrar conexión en auditoría cada (min)</span>
                            <input v-model.number="settingsForm.heartbeat_log_interval_minutes" type="number" min="1" max="1440" class="input w-full" />
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Optimizar desde (MB)</span>
                            <input v-model.number="settingsForm.optimize_min_deleted_mb" type="number" min="1" max="102400" class="input w-full" />
                        </label>
                    </div>

                    <div class="mt-5 rounded-3xl border border-sky-100 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                        El heartbeat de auditoria ahora conserva cambios de estado y muestreo controlado. El heartbeat funcional no se altera.
                    </div>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                            :disabled="busy.save"
                            @click="saveSettings"
                        >
                            {{ busy.save ? 'Guardando...' : 'Guardar configuracion' }}
                        </button>
                    </div>
                </article>

                <article class="card p-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Resumen de seleccion</p>
                    <h2 class="mt-2 text-xl font-semibold text-app">Limpieza manual segura</h2>
                    <p class="mt-3 text-sm text-muted">{{ selectionSummary }}</p>

                    <div class="mt-6 space-y-3">
                        <label class="flex items-start gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="selectionForm.delete_heartbeats" type="checkbox" class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span>
                                <span class="block text-sm font-medium text-app">Eliminar heartbeats</span>
                                <span class="block text-xs text-muted">Incluye `received`, `sampled` y cambios de estado segun la clasificacion.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="selectionForm.delete_sync" type="checkbox" class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span>
                                <span class="block text-sm font-medium text-app">Eliminar sync/import</span>
                                <span class="block text-xs text-muted">Aplica a eventos operativos de sincronizacion y carga.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="selectionForm.delete_old_logins" type="checkbox" class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span>
                                <span class="block text-sm font-medium text-app">Eliminar logins antiguos</span>
                                <span class="block text-xs text-muted">Usa en conjunto con fecha para evitar borrados masivos no deseados.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="selectionForm.delete_noise" type="checkbox" class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span>
                                <span class="block text-sm font-medium text-app">Eliminar todo el ruido</span>
                                <span class="block text-xs text-muted">Purgara la categoria `noise` respetando la fecha si se indica.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="selectionForm.delete_except_critical" type="checkbox" class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span>
                                <span class="block text-sm font-medium text-app">Eliminar todo excepto criticos</span>
                                <span class="block text-xs text-muted">Conserva la bitacora critica y actua sobre importantes + ruido.</span>
                            </span>
                        </label>
                    </div>

                    <div class="mt-6 grid gap-4">
                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Antes de la fecha</span>
                            <input v-model="selectionForm.before_date" type="date" class="input w-full" />
                        </label>

                        <label class="space-y-2">
                            <span class="text-sm font-medium text-app">Objetivo de liberacion (MB)</span>
                            <select v-model.number="selectionForm.target_free_mb" class="input w-full">
                                <option :value="0">Sin limite</option>
                                <option :value="128">128 MB</option>
                                <option :value="256">256 MB</option>
                                <option :value="512">512 MB</option>
                                <option :value="1024">1 GB</option>
                                <option :value="2048">2 GB</option>
                            </select>
                        </label>

                        <label class="flex items-center gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="selectionForm.optimize" type="checkbox" class="rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span class="text-sm text-app">Optimizar tabla despues de una purga grande</span>
                        </label>

                        <label class="flex items-center gap-3 rounded-2xl border border-app px-4 py-3">
                            <input v-model="selectionForm.simulate" type="checkbox" class="rounded border-slate-300 text-slate-900 focus:ring-slate-400" />
                            <span class="text-sm text-app">Marcar esta ejecucion como flujo de simulacion previo</span>
                        </label>
                    </div>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="rounded-2xl border border-app px-4 py-2.5 text-sm font-semibold text-app transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 dark:hover:bg-slate-900"
                            :disabled="busy.preview || busy.execute"
                            @click="previewCleanup"
                        >
                            {{ busy.preview ? 'Simulando...' : 'Vista previa de limpieza' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-2xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busy.execute || busy.preview"
                            @click="executeCleanup"
                        >
                            {{ busy.execute ? 'Ejecutando...' : 'Ejecutar limpieza' }}
                        </button>
                    </div>
                </article>
            </div>

            <div class="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
                <article class="card p-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Vista previa / resultado</p>
                    <h2 class="mt-2 text-xl font-semibold text-app">Impacto estimado</h2>

                    <div v-if="previewResult" class="mt-6 grid gap-4 md:grid-cols-2">
                        <div class="rounded-3xl border border-app px-4 py-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Modo</p>
                            <p class="mt-2 text-lg font-semibold text-app">{{ previewResult.mode ?? '--' }}</p>
                        </div>
                        <div class="rounded-3xl border border-app px-4 py-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Registros elegibles</p>
                            <p class="mt-2 text-lg font-semibold text-app">{{ formatNumber(previewResult.records_to_delete) }}</p>
                        </div>
                        <div class="rounded-3xl border border-app px-4 py-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Espacio estimado</p>
                            <p class="mt-2 text-lg font-semibold text-app">{{ previewResult.estimated_bytes_human ?? previewResult.estimated_bytes_freed_human ?? '0 B' }}</p>
                        </div>
                        <div class="rounded-3xl border border-app px-4 py-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Objetivo</p>
                            <p class="mt-2 text-lg font-semibold text-app">{{ previewResult.target_free_human ?? '0 B' }}</p>
                        </div>
                        <div class="rounded-3xl border border-app px-4 py-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Primer registro</p>
                            <p class="mt-2 text-sm font-medium text-app">{{ formatDateTime(previewResult.first_record_at) }}</p>
                        </div>
                        <div class="rounded-3xl border border-app px-4 py-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Ultimo registro</p>
                            <p class="mt-2 text-sm font-medium text-app">{{ formatDateTime(previewResult.last_record_at) }}</p>
                        </div>
                    </div>
                    <div v-else class="mt-6 rounded-3xl border border-dashed border-app px-4 py-8 text-sm text-muted">
                        Ejecuta una simulacion para ver cuantos registros serian eliminados y el espacio aproximado a recuperar.
                    </div>

                    <div v-if="executionResult" class="mt-6 rounded-3xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
                        Ultima ejecucion manual: {{ formatNumber(executionResult.deleted_records) }} registros borrados, {{ executionResult.estimated_bytes_freed_human ?? '0 B' }} recuperados.
                    </div>
                </article>

                <article class="card p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Eventos dominantes</p>
                            <h2 class="text-xl font-semibold text-app">Top eventos en audit_logs</h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-soft dark:bg-slate-800 dark:text-slate-300">
                            {{ topEvents.length }} filas
                        </span>
                    </div>

                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-[0.2em] text-slate-400">
                                <tr>
                                    <th class="pb-3 pr-4">Evento</th>
                                    <th class="pb-3 pr-4">Categoria</th>
                                    <th class="pb-3 pr-4">Total</th>
                                    <th class="pb-3 pr-4">Ultima vez</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="eventRow in topEvents" :key="eventRow.event" class="border-t border-app">
                                    <td class="py-3 pr-4 font-medium text-app">{{ eventRow.event || '--' }}</td>
                                    <td class="py-3 pr-4">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase ring-1" :class="categoryClass(eventRow.category)">
                                            {{ eventRow.category }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-app">{{ formatNumber(eventRow.total) }}</td>
                                    <td class="py-3 pr-4 text-muted">{{ formatDateTime(eventRow.last_seen_at) }}</td>
                                </tr>
                                <tr v-if="topEvents.length === 0">
                                    <td colspan="4" class="py-6 text-center text-sm text-muted">No hay eventos disponibles para mostrar.</td>
                                </tr>
                            </tbody>
                        </table>
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
            @close="toast.show = false"
        />
    </AuthenticatedLayout>
</template>
