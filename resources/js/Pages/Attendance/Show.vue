<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    record: {
        type: Object,
        required: true,
    },
    flash: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};

const canEdit = computed(
    () => can('asistencias', 'edit') || can('asistencias', 'admin') || can('settings', 'manage'),
);

const statusBadgeClass = computed(() => {
    if (props.record.attendance_status === 'anulada') {
        return 'bg-rose-50 text-rose-700';
    }

    if (props.record.attendance_status === 'corregida') {
        return 'bg-amber-50 text-amber-700';
    }

    return 'bg-emerald-50 text-emerald-700';
});

const flashStatus = computed(() => props.flash?.status ?? null);
const flashWarning = computed(() => props.flash?.warning ?? null);

const prettyJson = (payload) => {
    if (payload === null || payload === undefined) {
        return 'null';
    }

    try {
        return JSON.stringify(payload, null, 2);
    } catch {
        return String(payload);
    }
};

const annulForm = useForm({
    reason: '',
});

const submitAnnulment = () => {
    const reason = window.prompt('Motivo de anulacion del registro:');

    if (!reason) {
        return;
    }

    annulForm.reason = reason;
    annulForm.patch(route('admin.asistencias.annul', props.record.id), {
        preserveScroll: true,
        onFinish: () => annulForm.reset('reason'),
    });
};
</script>

<template>
    <Head title="Detalle de asistencia" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-xl font-semibold leading-tight">
                    Detalle de registro de asistencia
                </h1>
                <p class="text-sm text-slate-500">
                    Auditoria de cambios y datos crudos del registro.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div
                v-if="flashStatus"
                class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
            >
                {{ flashStatus }}
            </div>
            <div
                v-if="flashWarning"
                class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                {{ flashWarning }}
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <Link
                    :href="route('admin.asistencias.index')"
                    class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted"
                >
                    Volver al listado
                </Link>

                <button
                    v-if="canEdit && record.attendance_status !== 'anulada'"
                    type="button"
                    class="rounded-2xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-rose-700"
                    :disabled="annulForm.processing"
                    @click="submitAnnulment"
                >
                    Anular registro
                </button>
            </div>

            <section class="grid gap-4 lg:grid-cols-3">
                <article class="card space-y-3 p-4 lg:col-span-2">
                    <div class="flex items-center justify-between gap-3 border-b border-app pb-3">
                        <h3 class="text-base font-semibold text-app">Datos principales</h3>
                        <span
                            class="rounded-full px-3 py-1 text-xs font-semibold"
                            :class="statusBadgeClass"
                        >
                            {{ record.status_label }}
                        </span>
                    </div>

                    <dl class="grid gap-3 md:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Registro ID</dt>
                            <dd class="text-sm font-semibold text-app">#{{ record.id }} / log_id {{ record.log_id }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Fecha/hora</dt>
                            <dd class="text-sm text-app">{{ record.log_date_display ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Empleado</dt>
                            <dd class="text-sm text-app">
                                {{ record.employee?.name ?? 'N/A' }}
                                <span class="text-soft">({{ record.employee?.code ?? 'N/A' }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Unidad</dt>
                            <dd class="text-sm text-app">{{ record.location?.name ?? 'Sin unidad' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Reloj</dt>
                            <dd class="text-sm text-app">{{ record.clock?.name ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Tipo</dt>
                            <dd class="text-sm text-app">{{ record.log_type_label }} ({{ record.log_type }})</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Fuente</dt>
                            <dd class="text-sm text-app">{{ record.source_label }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Motivo de ajuste/anulacion</dt>
                            <dd class="text-sm text-app">{{ record.adjustment_reason || 'N/A' }}</dd>
                        </div>
                    </dl>
                </article>

                <article class="card space-y-3 p-4">
                    <h3 class="text-base font-semibold text-app">Estado de anulacion</h3>
                    <dl class="space-y-2 text-sm">
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Anulado en</dt>
                            <dd>{{ record.annulled_at_display ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Usuario anulacion</dt>
                            <dd>{{ record.annulled_by?.name ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Fortia status</dt>
                            <dd>{{ record.fortia_status ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.3em] text-soft">Sent to Fortia at</dt>
                            <dd>{{ record.sent_to_fortia_at_display ?? 'N/A' }}</dd>
                        </div>
                    </dl>
                </article>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <article class="card p-4">
                    <h3 class="mb-3 text-base font-semibold text-app">Raw payload</h3>
                    <pre class="overflow-x-auto rounded-2xl bg-slate-950 p-3 text-xs text-slate-100">{{ prettyJson(record.raw_payload) }}</pre>
                </article>
                <article class="card p-4">
                    <h3 class="mb-3 text-base font-semibold text-app">Fortia response payload</h3>
                    <pre class="overflow-x-auto rounded-2xl bg-slate-950 p-3 text-xs text-slate-100">{{ prettyJson(record.fortia_response_payload) }}</pre>
                </article>
            </section>

            <section class="card p-4">
                <h3 class="mb-3 text-base font-semibold text-app">Bitacora de cambios</h3>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-[0.3em] text-soft">
                            <tr>
                                <th class="px-3 py-3">Fecha</th>
                                <th class="px-3 py-3">Accion</th>
                                <th class="px-3 py-3">Usuario</th>
                                <th class="px-3 py-3">Motivo</th>
                                <th class="px-3 py-3">Antes / Despues</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="!record.audits?.length">
                                <td colspan="5" class="px-3 py-6 text-center text-sm text-soft">
                                    Sin auditoria para este registro.
                                </td>
                            </tr>
                            <tr v-for="audit in record.audits" :key="audit.id">
                                <td class="px-3 py-3 text-muted">{{ audit.created_at_display ?? 'N/A' }}</td>
                                <td class="px-3 py-3 text-app">{{ audit.action }}</td>
                                <td class="px-3 py-3 text-app">{{ audit.changed_by_name ?? 'N/A' }}</td>
                                <td class="px-3 py-3 text-app">{{ audit.reason ?? 'N/A' }}</td>
                                <td class="px-3 py-3">
                                    <details>
                                        <summary class="cursor-pointer text-xs text-indigo-600">Ver diff</summary>
                                        <div class="mt-2 grid gap-2 lg:grid-cols-2">
                                            <pre class="overflow-x-auto rounded-xl bg-slate-900 p-2 text-[11px] text-slate-100">{{ prettyJson(audit.before_data) }}</pre>
                                            <pre class="overflow-x-auto rounded-xl bg-slate-900 p-2 text-[11px] text-slate-100">{{ prettyJson(audit.after_data) }}</pre>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </AuthenticatedLayout>
</template>
