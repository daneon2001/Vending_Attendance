<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import InputError from '@/Components/InputError.vue';
import { formatDateTime } from '@/presentation/labels';
import { activityBadgeClass, activityDistance, activityEventLabel, activityLabel, employeeAccessLabel } from '@/presentation/supportActivities';
const props = defineProps({ activity: Object, events: Array, canCancel: Boolean });
const cancel = useForm({ cancellation_reason: '' });
function cancelActivity() {
    if (!window.confirm('¿Cancelar esta actividad? Se conservará el historial y no se cerrará el ticket relacionado.')) return;
    cancel.post(route('support.activities.cancel', props.activity.uuid), { preserveScroll: true });
}
</script>
<template>
    <Head :title="activity.folio + ' · Actividad de soporte'" />
    <AuthenticatedLayout>
        <template #header><div class="min-w-0"><Link :href="route('support.activities.index')" class="inline-flex min-h-11 items-center text-sm text-indigo-700 dark:text-indigo-300">← Actividades</Link><div class="flex flex-wrap items-center gap-3"><h1 class="text-xl font-semibold text-app">{{ activity.folio }}</h1><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="activityBadgeClass(activity.status)">{{ activityLabel(activity.status) }}</span></div><p class="mt-2 break-words text-sm text-soft">{{ activity.activity_type_label }} · {{ activity.machine?.machine_code }} · {{ activity.machine?.name }}</p></div></template>
        <div class="grid min-w-0 items-start gap-5 lg:grid-cols-3">
            <div class="min-w-0 space-y-5 lg:col-span-2">
                <section class="card p-5" aria-labelledby="activity-notes">
                    <h2 id="activity-notes" class="font-semibold text-app">Notas de campo</h2>
                    <p v-if="!activity.notes?.length" class="mt-3 text-sm text-soft">Sin notas recibidas.</p>
                    <ol v-else class="mt-4 space-y-4"><li v-for="note in activity.notes" :key="note.uuid" class="border-l-2 border-app pl-4">
                        <p class="whitespace-pre-wrap break-words text-sm">{{ note.body }}</p>
                        <p class="mt-2 text-xs text-soft">{{ note.author }} · Capturada: {{ formatDateTime(note.captured_at) }}</p>
                        <p class="mt-1 text-xs text-soft">Recibida: {{ formatDateTime(note.received_at) }}</p>
                    </li></ol>
                </section>
                <section class="card p-5" aria-labelledby="activity-evidence">
                    <h2 id="activity-evidence" class="font-semibold text-app">Evidencias de campo</h2>
                    <p class="mt-2 text-xs text-soft">Archivos privados. Las fechas de captura y recepción pueden diferir si se trabajó sin conexión.</p>
                    <p v-if="!activity.evidence?.length" class="mt-3 text-sm text-soft">Sin evidencias recibidas.</p>
                    <ul v-else class="mt-4 grid gap-4 sm:grid-cols-2"><li v-for="photo in activity.evidence" :key="photo.uuid" class="min-w-0 rounded-xl border border-app p-3">
                        <a :href="route('support.activities.evidence', { activity: activity.uuid, evidence: photo.uuid, format: 'download' })" class="block rounded-lg focus-visible:outline focus-visible:outline-2" :aria-label="'Descargar evidencia de ' + photo.author">
                            <img :src="route('support.activities.evidence', { activity: activity.uuid, evidence: photo.uuid, format: 'thumbnail' })" alt="Evidencia de la actividad" loading="lazy" class="h-40 w-full rounded-lg object-contain" />
                            <span class="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-indigo-700 dark:text-indigo-300">Descargar evidencia</span>
                        </a>
                        <p class="break-words text-xs text-soft">{{ photo.author }}</p>
                        <p class="mt-1 text-xs text-soft">Capturada: {{ formatDateTime(photo.captured_at) }}</p>
                        <p class="mt-1 text-xs text-soft">Recibida: {{ formatDateTime(photo.received_at) }}</p>
                    </li></ul>
                </section>
                <section class="card p-5"><h2 class="break-words text-lg font-semibold text-app">{{ activity.title }}</h2><p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ activity.description || 'Sin descripción adicional.' }}</p><dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-soft">Máquina</dt><dd class="break-words">{{ activity.machine?.machine_code }} · {{ activity.machine?.name }}</dd></div><div><dt class="text-soft">Dirección</dt><dd class="break-words">{{ activity.machine?.address_line || 'Sin dirección registrada' }}</dd></div><div><dt class="text-soft">Creada</dt><dd>{{ formatDateTime(activity.created_at) }}</dd></div><div><dt class="text-soft">Inicio</dt><dd>{{ formatDateTime(activity.started_at, 'Sin iniciar') }}</dd></div><div><dt class="text-soft">Finalización</dt><dd>{{ formatDateTime(activity.completed_at || activity.cancelled_at, 'Sin finalizar') }}</dd></div></dl><p class="mt-4 text-xs text-soft">Horarios de Ciudad de México.</p></section>
                <section class="card p-5"><h2 class="font-semibold text-app">Ubicación / geocerca</h2><p class="mt-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="activityBadgeClass(activity.snapshot.result)">{{ activityLabel(activity.snapshot.result) }}</span></p><p class="mt-3 text-sm text-soft">Evidencia histórica guardada al iniciar. No se recalcula con la geocerca actual.</p><dl v-if="activity.snapshot.evaluated_at" class="mt-4 grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-soft">Distancia</dt><dd>{{ activityDistance(activity.snapshot.distance_m) }}</dd></div><div><dt class="text-soft">Precisión GPS</dt><dd>{{ activityDistance(activity.snapshot.accuracy_m) }}</dd></div><div><dt class="text-soft">Validada</dt><dd>{{ formatDateTime(activity.snapshot.evaluated_at) }}</dd></div></dl><p v-else class="mt-3 text-sm text-soft">La ubicación aún no se ha validado para esta actividad.</p></section>
                <section class="card p-5" aria-labelledby="activity-timeline">
                    <h2 id="activity-timeline" class="font-semibold text-app">Historial de la actividad</h2>
                    <ol v-if="events?.length" class="mt-4 space-y-4"><li v-for="event in events" :key="event.id" class="border-l-2 border-app pl-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2"><h3 class="text-sm font-semibold">{{ activityEventLabel(event.kind) }}</h3><time class="text-xs text-soft" :datetime="event.occurred_at">{{ formatDateTime(event.occurred_at) }}</time></div>
                        <p class="mt-1 break-words text-sm text-soft">{{ event.actor }}</p>
                        <p v-if="event.received_at" class="mt-1 text-xs text-soft">Recibido por el servidor: {{ formatDateTime(event.received_at) }}</p>
                        <p v-if="event.kind === 'started' && activity.snapshot.evaluated_at" class="mt-1 text-sm text-soft">Ubicación validada al iniciar: {{ activityLabel(activity.snapshot.result).toLowerCase() }}.</p>
                    </li></ol>
                    <p v-else class="mt-3 text-sm text-soft">No hay eventos disponibles para esta actividad.</p>
                </section>
            </div>
            <aside class="min-w-0 space-y-5" aria-label="Asignación y seguimiento">
                <section class="card p-5"><h2 class="font-semibold text-app">Asignación</h2><p class="mt-3 break-words text-sm">{{ activity.employee?.full_name }}</p><p class="mt-1 text-sm text-soft">Número: {{ activity.employee?.employee_number }}</p><p class="mt-3 text-sm text-soft">{{ employeeAccessLabel(activity.employee) }}</p><p class="mt-3 text-xs text-soft">La reasignación aún no está disponible. No se cambia el empleado de un registro histórico.</p></section>
                <section class="card p-5"><h2 class="font-semibold text-app">Ticket relacionado</h2><Link v-if="activity.ticket" :href="route('support.tickets.show', activity.ticket.uuid)" class="mt-3 inline-flex min-h-11 items-center font-semibold text-indigo-700 dark:text-indigo-300">{{ activity.ticket.folio }}</Link><p v-else class="mt-3 text-sm text-soft">Sin ticket relacionado disponible en tu acceso.</p><p class="mt-2 text-xs text-soft">Finalizar la actividad no cierra automáticamente el ticket.</p></section>
                <section class="card p-5"><h2 class="font-semibold text-app">Trabajo en campo</h2><p class="mt-3 text-sm text-soft">Esta vista administrativa no inicia ni completa trabajos. El personal autorizado inicia y finaliza desde la aplicación móvil. La ubicación se valida al iniciar; finalizar no obtiene ni acredita una nueva ubicación.</p></section>
                <section v-if="canCancel" class="card p-5"><h2 class="font-semibold text-app">Cancelar actividad</h2><form class="mt-3 space-y-3" @submit.prevent="cancelActivity"><label class="block text-sm">Motivo de cancelación<textarea v-model="cancel.cancellation_reason" required maxlength="1000" rows="3" :disabled="cancel.processing" class="mt-1 w-full border-app" /><InputError :message="cancel.errors.cancellation_reason" /></label><InputError :message="cancel.errors.activity" /><button class="min-h-11 rounded-xl border border-app px-4 py-2 text-sm font-semibold text-rose-700 disabled:opacity-50 dark:text-rose-300" :disabled="cancel.processing">{{ cancel.processing ? 'Guardando…' : 'Confirmar cancelación' }}</button></form></section>
                <section v-if="activity.cancellation_reason" class="card p-5"><h2 class="font-semibold text-app">Motivo de cancelación</h2><p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ activity.cancellation_reason }}</p></section>
                <TechnicalDetails><p>Referencia: {{ activity.uuid }}</p><p>Política: {{ activity.presence_policy }}</p><p>Versión de geocerca: {{ activity.snapshot.version ?? 'Sin validar' }}</p><p>Estado: {{ activity.status }}</p><p>Los eventos del historial son la evidencia del dominio; no se duplican registros de auditoría general.</p></TechnicalDetails>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>
