<script setup>
import { Link } from '@inertiajs/vue3';
import { formatDateTime } from '@/presentation/labels';
import { activityBadgeClass, activityLabel } from '@/presentation/supportActivities';
defineProps({ activities: { type: Array, default: () => [] }, compact: Boolean });
</script>
<template>
    <div class="overflow-x-auto">
        <table class="w-full table-fixed text-left text-sm"><caption class="sr-only">Actividades de soporte dentro de tu acceso</caption>
            <thead class="bg-slate-50 text-soft dark:bg-slate-800"><tr><th scope="col" class="w-2/5 p-3">Actividad / máquina</th><th scope="col" class="w-1/4 p-3">Técnico</th><th scope="col" class="p-3">Estado</th><th v-if="!compact" scope="col" class="hidden p-3 xl:table-cell">Fechas</th></tr></thead>
            <tbody><tr v-for="activity in activities" :key="activity.uuid" class="border-t border-app align-top">
                <td class="break-words p-3"><Link :href="route('support.activities.show', activity.uuid)" class="inline-flex min-h-11 items-center font-semibold text-indigo-700 dark:text-indigo-300" :aria-label="'Ver actividad ' + activity.folio">{{ activity.folio }}</Link><p class="font-medium">{{ activity.title }}</p><p class="mt-1 text-soft">{{ activity.machine?.machine_code }} · {{ activity.machine?.name || 'Máquina vending' }}</p><p class="mt-1 text-xs text-soft">{{ activity.activity_type_label }}</p></td>
                <td class="break-words p-3">{{ activity.employee?.full_name || 'Sin información' }}<p class="mt-1 text-xs text-soft">{{ activity.employee?.employee_number }}</p><p class="mt-2 text-xs text-soft" :class="{ 'xl:hidden': !compact }">Creada: {{ formatDateTime(activity.created_at) }}</p></td>
                <td class="break-words p-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="activityBadgeClass(activity.status)">{{ activityLabel(activity.status) }}</span><p class="mt-2 text-xs text-soft">{{ activityLabel(activity.geofence_result) }}</p><Link v-if="activity.ticket" :href="route('support.tickets.show', activity.ticket.uuid)" class="mt-2 inline-flex min-h-11 items-center text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ activity.ticket.folio }}</Link></td>
                <td v-if="!compact" class="hidden p-3 text-xs text-soft xl:table-cell"><p>Creada: {{ formatDateTime(activity.created_at) }}</p><p class="mt-2">Inicio: {{ formatDateTime(activity.started_at, 'Sin iniciar') }}</p><p class="mt-2">Fin: {{ formatDateTime(activity.completed_at || activity.cancelled_at, 'Sin finalizar') }}</p></td>
            </tr></tbody>
        </table>
    </div>
</template>
