<script setup>
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
});

const emit = defineEmits(['view', 'edit', 'toggle', 'collapse-toggle']);

const statusStyles = computed(() =>
    props.unit.status
        ? 'bg-emerald-50 text-emerald-700 border border-emerald-100'
        : 'bg-rose-50 text-rose-700 border border-rose-100',
);

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
</script>

<template>
    <article
        class="rounded-3xl border border-slate-100 bg-white/90 p-5 shadow-sm ring-1 ring-transparent transition hover:border-indigo-100 hover:ring-indigo-50 dark:bg-slate-900/60"
    >
        <div v-if="collapsed" class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <div class="flex flex-wrap items-center gap-3">
                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                    {{ unit.company?.name ?? 'Sin compañía' }}
                </p>
                <span class="text-base font-semibold text-app dark:text-slate-100">{{ unit.name }}</span>
                <span class="text-xs text-slate-500">#{{ unit.code }}</span>
                <span class="text-xs text-slate-500">
                    {{ unit.city ?? 'Sin ciudad' }}{{ unit.state ? `, ${unit.state}` : '' }}
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em]" :class="statusStyles">
                    {{ unit.status ? 'Activa' : 'Inactiva' }}
                </span>
                <span class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-500">
                    {{ unit.clocks_count }} relojes
                </span>
                <button
                    type="button"
                    class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300"
                    aria-label="Desplegar sucursal"
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
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        {{ unit.company?.name ?? 'Sin compañía' }}
                    </p>
                    <h3 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ unit.name }}
                    </h3>
                    <p class="text-sm text-slate-500">
                        Código {{ unit.code }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em]" :class="statusStyles">
                        {{ unit.status ? 'Activa' : 'Inactiva' }}
                    </span>
                    <span class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-500">
                        {{ unit.clocks_count }} relojes
                    </span>
                    <button
                        type="button"
                        class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300"
                        aria-label="Colapsar sucursal"
                        @click="emit('collapse-toggle', unit)"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor">
                            <path d="M6 8l4 4 4-4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </header>

            <div class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                <dl class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4 dark:border-slate-800 dark:bg-slate-900/60">
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
                <dl class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4 dark:border-slate-800 dark:bg-slate-900/60">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">
                        Dirección
                    </dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                        {{ unit.address ?? 'Sin registrar' }}
                    </dd>
                </dl>
                <dl class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4 dark:border-slate-800 dark:bg-slate-900/60">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">
                        Zona horaria
                    </dt>
                    <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                        {{ unit.timezone ?? 'No definida' }}
                    </dd>
                    <dd class="text-xs text-slate-500">
                        Última actualización {{ formatDate(unit.created_at) }}
                    </dd>
                </dl>
            </div>

            <div class="mt-5 flex flex-wrap gap-2 text-sm font-medium text-slate-600">
                <button class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900" @click="emit('view', unit)">
                    Ver detalle
                </button>
                <button class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900" @click="emit('edit', unit)">
                    Editar
                </button>
                <button
                    class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900"
                    @click="emit('toggle', unit)"
                >
                    {{ unit.status ? 'Desactivar' : 'Activar' }}
                </button>
            </div>
        </template>
    </article>
</template>
