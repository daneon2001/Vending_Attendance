<script setup>
import { formatDateTime as formatOperationalDate } from '@/presentation/labels';
import { computed } from 'vue';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    detail: {
        type: Object,
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['close']);
useBodyScrollLock(() => props.open);

const monitoringStyles = {
    online: 'bg-emerald-50 text-emerald-700',
    warning: 'bg-amber-50 text-amber-700',
    offline: 'bg-rose-50 text-rose-700',
};

const unit = computed(() => props.detail?.unit ?? null);
const clocks = computed(() => props.detail?.clocks ?? []);

const formatDateTime = (value) => formatOperationalDate(value, 'Sin conexión registrada');
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-40 flex overflow-hidden">
        <div class="flex-1 bg-slate-900/50" @click="emit('close')" />
        <div class="w-full max-w-xl overflow-y-auto bg-white p-4 shadow-2xl sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        Detalle
                    </p>
                    <h3 class="text-2xl font-semibold text-slate-900">
                        {{ unit?.name ?? 'Unidad' }}
                    </h3>
                    <p class="text-sm text-slate-500">
                        Código {{ unit?.code }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900"
                    aria-label="Cerrar detalle de unidad"
                    @click="emit('close')"
                >
                    <span class="sr-only">Cerrar</span>
                    ✕
                </button>
            </div>

            <div v-if="loading" class="mt-6 rounded-2xl border border-slate-100 p-4 text-sm text-slate-500">
                Cargando información...
            </div>
            <div v-else>
                <div v-if="error" class="mt-6 rounded-2xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-600">
                    {{ error }}
                </div>

                <section v-if="unit" class="mt-6 space-y-3 text-sm text-slate-600">
                    <p><strong>Empresa:</strong> {{ unit.company?.name ?? '—' }}</p>
                    <p><strong>Dirección:</strong> {{ unit.address ?? 'Sin registrar' }}</p>
                    <p>
                        <strong>Ubicación:</strong> {{ unit.city ?? '—' }}, {{ unit.state ?? '—' }},
                        {{ unit.country ?? '—' }}
                    </p>
                    <p><strong>Zona horaria:</strong> {{ unit.timezone ?? 'No definida' }}</p>
                    <p><strong>Descripción:</strong> {{ unit.description ?? 'Sin descripción' }}</p>
                </section>

                <section class="mt-8">
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-400">
                            Relojes asignados
                        </h4>
                        <span class="text-xs text-slate-500">{{ clocks.length }} equipos</span>
                    </div>

                    <div class="mt-4 space-y-3">
                        <article
                            v-for="clock in clocks"
                            :key="clock.id"
                            class="rounded-2xl border border-slate-100 p-4 text-sm"
                        >
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-semibold text-slate-900">{{ clock.clock_name }}</p>
                                    <p class="text-xs text-slate-500">
                                        IP {{ clock.ip_address ?? 'sin asignar' }}
                                    </p>
                                </div>
                                <span
                                    class="rounded-full px-3 py-1 text-xs font-semibold capitalize"
                                    :class="monitoringStyles[clock.monitoring_status ?? 'offline']"
                                >
                                    {{ clock.monitoring_status }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">
                                Último latido: {{ formatDateTime(clock.last_heartbeat_at) }}
                            </p>
                        </article>
                        <p v-if="!clocks.length" class="rounded-2xl border border-slate-100 p-4 text-sm text-slate-500">
                            No hay relojes asignados todavía.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
