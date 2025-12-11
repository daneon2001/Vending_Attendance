<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    clocks: {
        type: Array,
        default: () => [],
    },
    locations: {
        type: Array,
        default: () => [],
    },
});

const clocks = computed(() => props.clocks ?? []);

const totalLocations = computed(() => props.locations?.length ?? 0);

const monitoringStyles = {
    online: {
        label: 'En línea',
        badge: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
        dot: 'bg-emerald-500',
    },
    warning: {
        label: 'Con alertas',
        badge: 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
        dot: 'bg-amber-400',
    },
    offline: {
        label: 'Sin conexión',
        badge: 'bg-rose-50 text-rose-700 ring-1 ring-rose-100',
        dot: 'bg-rose-400',
    },
};

const totalOnline = computed(
    () => clocks.value.filter((clock) => clock.monitoring_status === 'online').length,
);
const totalWarning = computed(
    () => clocks.value.filter((clock) => clock.monitoring_status === 'warning').length,
);
const totalOffline = computed(
    () =>
        clocks.value.filter(
            (clock) => clock.monitoring_status === 'offline' || !clock.is_online,
        ).length,
);

const formatRelative = (timestamp) => {
    if (!timestamp) return 'Sin latido registrado';
    const diffMs = Date.now() - Date.parse(timestamp);
    const diffMinutes = Math.round(diffMs / 60000);
    if (diffMinutes <= 1) return 'Hace instantes';
    if (diffMinutes < 60) return `Hace ${diffMinutes} min`;
    const diffHours = Math.round(diffMinutes / 60);
    if (diffHours < 24) return `Hace ${diffHours} h`;
    const diffDays = Math.round(diffHours / 24);
    return `Hace ${diffDays} días`;
};

const triggerAction = (action, clock) => {
    window.dispatchEvent(
        new CustomEvent('clock-action', {
            detail: { action, clockId: clock.id },
        }),
    );
};
</script>

<template>
    <Head title="Relojes biométricos" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="mt-1 text-2xl font-semibold text-slate-900">
                    Relojes biométricos
                </h1>
                <p class="text-sm text-slate-500">
                    Controla estado, asignaciones y monitoreo de cada checador.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-emerald-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-500">
                        En línea
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalOnline }}
                    </p>
                    <p class="text-sm text-slate-500">Operando y sincronizando</p>
                </article>
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-amber-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-amber-500">
                        Alertas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalWarning }}
                    </p>
                    <p class="text-sm text-slate-500">Latidos tardíos o firmas pendientes</p>
                </article>
                <article class="rounded-3xl border border-slate-100 bg-gradient-to-br from-rose-50 to-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-rose-500">
                        Sin conexión
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">
                        {{ totalOffline }}
                    </p>
                    <p class="text-sm text-slate-500">Programas on-prem fuera de línea</p>
                </article>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">
                        Catálogo
                        <span class="text-sm font-medium text-slate-400">({{ clocks.length }} checadores)</span>
                    </h2>
                    <p class="text-sm text-slate-500">
                        Asigna cada equipo a unidades y mantén visibilidad.
                    </p>
                    <p class="text-xs uppercase tracking-[0.3em] text-slate-400">
                        {{ totalLocations }} unidades monitoreadas
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm hover:text-slate-900"
                        @click="triggerAction('import', { id: null })"
                    >
                        <span>Importar</span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-600/30 hover:bg-indigo-500"
                        @click="triggerAction('create', { id: null })"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                fill-rule="evenodd"
                                d="M10 4a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 011-1z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        <span>Nuevo reloj</span>
                    </button>
                </div>
            </div>

            <div class="space-y-4">
                <article
                    v-for="clock in clocks"
                    :key="clock.id"
                    class="rounded-3xl border border-slate-100 bg-white/90 p-5 shadow-sm ring-1 ring-transparent transition hover:border-indigo-100 hover:ring-indigo-50"
                >
                    <header class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                {{ clock.company?.name ?? 'Compañía' }}
                            </p>
                            <h3 class="text-xl font-semibold text-slate-900">
                                {{ clock.clock_name }}
                            </h3>
                            <p class="text-sm text-slate-500">
                                Serie {{ clock.serial_number ?? 'sin registrar' }} • Firmware {{ clock.firmware_version ?? 'pendiente' }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                                :class="monitoringStyles[clock.monitoring_status || 'offline']?.badge"
                            >
                                <span
                                    class="h-2 w-2 rounded-full"
                                    :class="monitoringStyles[clock.monitoring_status || 'offline']?.dot"
                                />
                                {{ monitoringStyles[clock.monitoring_status || 'offline']?.label }}
                            </span>
                            <span
                                class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium uppercase tracking-[0.3em]"
                                :class="clock.status ? 'text-emerald-600 border-emerald-100 bg-emerald-50' : 'text-rose-600 border-rose-100 bg-rose-50'"
                            >
                                {{ clock.status ? 'Activo' : 'Inactivo' }}
                            </span>
                        </div>
                    </header>

                    <div class="mt-4 grid gap-4 md:grid-cols-4">
                        <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">
                                IP local
                            </dt>
                            <dd class="mt-1 font-semibold text-slate-900">
                                {{ clock.ip_address ?? 'No asignada' }}
                            </dd>
                            <dd class="text-xs text-slate-500">{{ clock.type_inout ?? 'Modo no definido' }}</dd>
                        </dl>

                        <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">
                                Último latido
                            </dt>
                            <dd class="mt-1 font-semibold text-slate-900">
                                {{ formatRelative(clock.last_heartbeat_at) }}
                            </dd>
                            <dd class="text-xs text-slate-500">
                                {{ clock.monitoring_message ?? 'Sin bitácora' }}
                            </dd>
                        </dl>

                        <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">
                                Unidad asignada
                            </dt>
                            <dd class="mt-1 font-semibold text-slate-900">
                                {{ clock.location?.name ?? 'Sin asignar' }}
                            </dd>
                            <dd class="text-xs text-slate-500">
                                Código {{ clock.location?.code ?? 'N/A' }}
                            </dd>
                        </dl>

                        <dl class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-sm">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">
                                Programa on-prem
                            </dt>
                            <dd class="mt-1 font-semibold text-slate-900">
                                {{ clock.is_online ? 'Encendido' : 'Apagado' }}
                            </dd>
                            <dd class="text-xs text-slate-500">
                                {{ clock.is_online ? 'Reportando en tiempo real' : 'Esperando reconexión' }}
                            </dd>
                        </dl>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2 text-sm font-medium text-slate-600">
                        <button
                            class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900"
                            @click="triggerAction('view', clock)"
                        >
                            Consultar bitácora
                        </button>
                        <button
                            class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900"
                            @click="triggerAction('assign', clock)"
                        >
                            Asignar a unidad
                        </button>
                        <button
                            class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900"
                            @click="triggerAction('edit', clock)"
                        >
                            Editar configuración
                        </button>
                    </div>
                </article>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
