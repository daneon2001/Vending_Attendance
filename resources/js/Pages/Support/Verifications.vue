<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RecordPagination from '@/Components/RecordPagination.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import { formatDateTime, friendlyError, statusLabel } from '@/presentation/labels';
import { supportBadgeClass, supportCheckLabel, supportLabel } from '@/presentation/support';

const props = defineProps({ verifications: Object });
const rows = computed(() => props.verifications?.data ?? []);
const expanded = ref(null);
const origin = (source) => source === 'CLIENT_REPORTED' ? 'Reportado por el equipo' : source === 'SERVER_SNAPSHOT' ? 'Comprobado por el servidor' : 'Origen no disponible';
</script>

<template>
    <Head title="Verificaciones de soporte" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-xl font-semibold text-app">Verificaciones</h1><p class="mt-1 text-sm text-soft">Consulta el resultado registrado al revisar cada equipo.</p></div></template>
        <div class="space-y-5">
            <Link :href="route('support.tickets.index')" class="inline-flex min-h-11 items-center text-sm font-semibold text-indigo-700 dark:text-indigo-300">Ver tickets de soporte</Link>
            <section v-if="rows.length" class="card overflow-x-auto" aria-label="Verificaciones registradas">
                <table class="w-full text-left text-sm"><caption class="sr-only">Verificaciones técnicas de los equipos que puedes consultar</caption><thead class="bg-slate-50 text-soft dark:bg-slate-800"><tr><th scope="col" class="p-3">Máquina</th><th scope="col" class="p-3">Equipo</th><th scope="col" class="p-3">Resultado</th><th scope="col" class="p-3">Fecha</th><th scope="col" class="p-3">Acción</th></tr></thead>
                    <tbody><template v-for="verification in rows" :key="verification.uuid"><tr class="border-t border-app align-top"><td class="p-3 font-semibold">{{ verification.machine?.machine_code || 'Sin información' }}<p v-if="verification.machine?.name" class="mt-1 max-w-xs break-words text-xs font-normal text-soft">{{ verification.machine.name }}</p></td><td class="p-3">{{ verification.device?.device_name || 'Equipo de la máquina' }}</td><td class="p-3"><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="supportBadgeClass(verification.summary)">{{ supportLabel(verification.summary) }}</span></td><td class="whitespace-nowrap p-3">{{ formatDateTime(verification.completed_at) }}</td><td class="p-3"><button class="min-h-11 font-semibold text-indigo-700 dark:text-indigo-300" type="button" :aria-expanded="expanded === verification.uuid" :aria-controls="'verification-' + verification.uuid" @click="expanded = expanded === verification.uuid ? null : verification.uuid">{{ expanded === verification.uuid ? 'Cerrar revisión' : 'Ver revisión' }}</button></td></tr>
                        <tr v-if="expanded === verification.uuid" class="border-t border-app"><td colspan="5" class="p-4"><section :id="'verification-' + verification.uuid" :aria-label="'Comprobaciones de ' + (verification.machine?.machine_code || 'la máquina')"><h2 class="font-semibold">Comprobaciones realizadas</h2><p class="mt-1 text-xs text-soft">Los resultados corresponden al momento de la revisión.</p><ul class="mt-4 grid gap-3 md:grid-cols-2"><li v-for="check in verification.checks ?? []" :key="check.code" class="rounded-xl border border-app p-3"><div class="flex flex-wrap items-start justify-between gap-2"><h3 class="text-sm font-medium">{{ supportCheckLabel(check.code) }}</h3><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="supportBadgeClass(check.result)">{{ supportLabel(check.result) }}</span></div><p class="mt-2 text-xs text-soft">{{ origin(check.source) }}</p><p v-if="check.details?.error_code" class="mt-2 text-sm">{{ friendlyError(check.details.error_code) }}</p><TechnicalDetails><p>Observado: {{ formatDateTime(check.observed_at) }}</p><p v-if="check.details?.pending_count != null">Registros pendientes: {{ check.details.pending_count }}</p><p v-if="check.details?.free_mb != null">Espacio disponible: {{ check.details.free_mb }} MB</p><p v-if="check.details?.seconds != null">Diferencia de hora: {{ check.details.seconds }} segundos</p><p v-if="check.details?.state">Conexión o sincronización: {{ statusLabel(check.details.state) }}</p><p v-if="check.details?.version != null">Versión aplicada: {{ check.details.version }}</p></TechnicalDetails></li></ul><p v-if="!verification.checks?.length" class="mt-3 text-sm text-soft">No hay comprobaciones disponibles para mostrar.</p></section></td></tr>
                    </template></tbody>
                </table>
            </section>
            <EmptyState v-else title="Sin verificaciones disponibles" message="Las revisiones realizadas desde los equipos aparecerán aquí dentro de tu acceso." />
            <p class="text-sm text-soft">Horarios de Ciudad de México.</p>
            <RecordPagination v-if="verifications?.last_page > 1" :links="verifications.links" />
        </div>
    </AuthenticatedLayout>
</template>
