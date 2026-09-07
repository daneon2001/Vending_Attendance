<script setup>
import { formatDateTime as formatOperationalDate } from '@/presentation/labels';
import { computed } from 'vue';

const props = defineProps({
    unit: {
        type: Object,
        required: true,
    },
    collapsed: {
        type: Boolean,
        default: false,
    },
    canUpdate: {
        type: Boolean,
        default: false,
    },
    canDisable: {
        type: Boolean,
        default: false,
    },
    visibleColumns: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['view', 'edit', 'toggle', 'collapse-toggle']);

const statusStyles = computed(() =>
    props.unit.status
        ? 'bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-800'
        : 'bg-rose-50 text-rose-700 border border-rose-100 dark:bg-rose-950/50 dark:text-rose-300 dark:border-rose-800',
);

const formatDate = (value) => formatOperationalDate(value, 'Sin fecha');
const showColumn = (key) => props.visibleColumns.includes(key);
const hasExtraDetails = computed(() =>
    ['id', 'fortia_location_id', 'description', 'state', 'country', 'timezone', 'address', 'company_id', 'active_clocks_count', 'offline_clocks_count', 'created_at', 'updated_at']
        .some((key) => showColumn(key)),
);
</script>

<template>
    <article
        class="card-record p-5 hover:border-indigo-100 hover:ring-indigo-50 dark:hover:border-indigo-900/60 dark:hover:ring-indigo-950/40"
    >
        <div v-if="collapsed" class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <div class="flex flex-wrap items-center gap-3">
                <p v-if="showColumn('company_name')" class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                    {{ unit.company?.name ?? 'Sin compañía' }}
                </p>
                <span class="text-base font-semibold text-app dark:text-slate-100">{{ unit.name }}</span>
                <span v-if="showColumn('code')" class="text-xs text-slate-500">#{{ unit.code || 'Sin código' }}</span>
                <span v-if="showColumn('city')" class="text-xs text-slate-500">
                    {{ unit.city ?? 'Sin ciudad' }}{{ unit.state ? `, ${unit.state}` : '' }}
                </span>
                <span v-if="showColumn('last_heartbeat_at')" class="text-xs text-slate-500">
                    {{ unit.last_heartbeat_at_display ? `Conexión ${unit.last_heartbeat_at_display}` : 'Sin conexión registrada' }}
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    v-if="showColumn('status_label')"
                    class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em]"
                    :class="statusStyles"
                >
                    {{ unit.status ? 'Activa' : 'Inactiva' }}
                </span>
                <span
                    v-if="showColumn('clocks_count')"
                    class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-500 dark:border-slate-700 dark:text-slate-300"
                >
                    {{ unit.clocks_count }} relojes
                </span>
                <button
                    type="button"
                    class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300"
                    aria-label="Desplegar unidad"
                    @click="emit('collapse-toggle', unit)"
                >
                    <svg class="h-4 w-4 rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor">
                        <path d="M6 8l4 4 4-4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </div>
        </div>

        <template v-else>
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p v-if="showColumn('company_name')" class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        {{ unit.company?.name ?? 'Sin compañía' }}
                    </p>
                    <h3 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ unit.name }}
                    </h3>
                    <p v-if="showColumn('code')" class="text-sm text-slate-500 dark:text-slate-400">
                        Código {{ unit.code || 'Sin código' }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-if="showColumn('status_label')"
                        class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em]"
                        :class="statusStyles"
                    >
                        {{ unit.status ? 'Activa' : 'Inactiva' }}
                    </span>
                    <span
                        v-if="showColumn('clocks_count')"
                        class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-500 dark:border-slate-700 dark:text-slate-300"
                    >
                        {{ unit.clocks_count }} relojes
                    </span>
                    <button
                        type="button"
                        class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300"
                        aria-label="Colapsar unidad"
                        @click="emit('collapse-toggle', unit)"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor">
                            <path d="M6 8l4 4 4-4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </header>

            <div class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                <dl v-if="showColumn('city')" class="card-subtle p-4">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">
                        Ubicación
                    </dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                        {{ unit.city ?? 'Sin ciudad' }}
                    </dd>
                    <dd class="text-xs text-slate-500">
                        {{ unit.state ?? 'Sin estado' }} {{ unit.country ? `· ${unit.country}` : '' }}
                    </dd>
                </dl>
                <dl v-if="showColumn('address')" class="card-subtle p-4">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">
                        Dirección
                    </dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                        {{ unit.address ?? 'Sin registrar' }}
                    </dd>
                </dl>
                <dl v-if="showColumn('timezone') || showColumn('last_heartbeat_at') || showColumn('created_at')" class="card-subtle p-4">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">
                        Operación
                    </dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                        {{ showColumn('timezone') ? (unit.timezone ?? 'No definida') : (unit.last_heartbeat_at_display ?? 'Sin conexión registrada') }}
                    </dd>
                    <dd v-if="showColumn('last_heartbeat_at')" class="text-xs text-slate-500">
                        {{ unit.last_heartbeat_at_display ? `Conexión ${unit.last_heartbeat_at_display}` : 'Sin conexión registrada' }}
                    </dd>
                    <dd v-else-if="showColumn('created_at')" class="text-xs text-slate-500">
                        Última actualización {{ formatDate(unit.created_at) }}
                    </dd>
                </dl>
            </div>

            <div v-if="hasExtraDetails" class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <dl v-if="showColumn('id')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">ID</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.id }}</dd>
                </dl>
                <dl v-if="showColumn('fortia_location_id')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Fortia location ID</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.fortia_location_id ?? '—' }}</dd>
                </dl>
                <dl v-if="showColumn('description')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Descripción</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.description ?? 'Sin descripción' }}</dd>
                </dl>
                <dl v-if="showColumn('state') && !showColumn('city')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Estado geográfico</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.state ?? 'Sin estado' }}</dd>
                </dl>
                <dl v-if="showColumn('country') && !showColumn('city')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">País</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.country ?? 'Sin país' }}</dd>
                </dl>
                <dl v-if="showColumn('company_id')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Company ID</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.company?.id ?? '—' }}</dd>
                </dl>
                <dl v-if="showColumn('active_clocks_count')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Relojes activos</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.active_clocks_count ?? 0 }}</dd>
                </dl>
                <dl v-if="showColumn('offline_clocks_count')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Relojes offline</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.offline_clocks_count ?? 0 }}</dd>
                </dl>
                <dl v-if="showColumn('updated_at')" class="card-subtle p-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Actualizado</dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ unit.updated_at_display ?? formatDate(unit.updated_at) }}</dd>
                </dl>
            </div>

            <div class="mt-5 flex flex-col gap-2 text-sm font-medium text-slate-600 sm:flex-row sm:flex-wrap">
                <button class="inline-flex w-full items-center justify-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900 sm:w-auto" @click="emit('view', unit)">
                    Ver detalle
                </button>
                <button v-if="canUpdate" class="inline-flex w-full items-center justify-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900 sm:w-auto" @click="emit('edit', unit)">
                    Editar
                </button>
                <button
                    v-if="canDisable"
                    class="inline-flex w-full items-center justify-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900 sm:w-auto"
                    @click="emit('toggle', unit)"
                >
                    {{ unit.status ? 'Desactivar' : 'Activar' }}
                </button>
            </div>
        </template>
    </article>
</template>
