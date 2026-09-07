<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import InputError from '@/Components/InputError.vue';
import { formatDateTime } from '@/presentation/labels';
import { canSupport, supportBadgeClass, supportError, supportEventLabel, supportFileSize, supportLabel, supportOperationUuid } from '@/presentation/support';

const props = defineProps({ ticket: Object, events: Array, evidence: Array, options: Object, evidencePolicy: Object, allowedTransitions: Array });
const page = usePage();
const can = (action) => canSupport(page.props.auth?.permissions, action);
const immutable = computed(() => ['CLOSED', 'CANCELLED'].includes(props.ticket.status));
const events = computed(() => props.events ?? []);
const photographs = computed(() => props.evidence ?? []);
const transitions = computed(() => props.allowedTransitions ?? []);
const category = computed(() => (props.options?.categories ?? []).find((item) => item.value === props.ticket.category)?.label ?? 'Sin categoría');
const feedback = ref('');
const operationError = ref('');
const comment = useForm({ client_operation_uuid: '', body: '' });
const assignment = useForm({ client_operation_uuid: '', assignee_id: props.ticket.assignee?.id ?? '' });
const transition = useForm({ client_operation_uuid: '', status: '', resolution: '' });
const fingerprints = new Map();
const sendingEvidence = ref(false);
const busy = computed(() => comment.processing || assignment.processing || transition.processing || sendingEvidence.value);
const send = (form, name, fields, success) => {
    feedback.value = '';
    operationError.value = '';
    try {
        const fingerprint = JSON.stringify(fields.map((key) => form[key]));
        if (!form.client_operation_uuid || fingerprints.get(name) !== fingerprint) form.client_operation_uuid = supportOperationUuid();
        fingerprints.set(name, fingerprint);
        form.post(route(name, props.ticket.uuid), {
            preserveScroll: true,
            onSuccess: () => {
                form.client_operation_uuid = '';
                fingerprints.delete(name);
                feedback.value = success;
                if (form === comment) form.reset('body');
                if (form === transition) form.reset('status', 'resolution');
            },
        });
    } catch (error) {
        operationError.value = error.message;
    }
};
const changeStatus = () => {
    if (['CLOSED', 'CANCELLED'].includes(transition.status) && !window.confirm('El reporte quedará cerrado para nuevos cambios. Su historial y evidencias se conservarán. ¿Deseas continuar?')) return;
    send(transition, 'support.tickets.transition', ['status', 'resolution'], 'Estado actualizado.');
};

const fileInput = ref(null);
const selectedFile = ref(null);
const evidenceOperation = ref('');
const uploadError = ref('');
const uploadProgress = ref(null);
const failedThumbnails = reactive({});
const maxBytes = computed(() => props.evidencePolicy?.max_size_bytes ?? 5242880);
const maxCount = computed(() => props.evidencePolicy?.max_count ?? 5);
const acceptedMimes = computed(() => props.evidencePolicy?.allowed_mimes ?? ['image/jpeg', 'image/png', 'image/webp']);
const countReached = computed(() => photographs.value.length >= maxCount.value);
const invalidFile = computed(() => selectedFile.value && (!acceptedMimes.value.includes(selectedFile.value.type) || selectedFile.value.size < 1 || selectedFile.value.size > maxBytes.value));
const selectFile = (event) => {
    const file = event.target.files?.[0] ?? null;
    if (!selectedFile.value || !file || [file.name, file.size, file.lastModified].join('|') !== [selectedFile.value.name, selectedFile.value.size, selectedFile.value.lastModified].join('|')) evidenceOperation.value = '';
    selectedFile.value = file;
    uploadError.value = '';
    if (file && !acceptedMimes.value.includes(file.type)) uploadError.value = 'Selecciona una imagen JPEG, PNG o WebP.';
    else if (file && (file.size < 1 || file.size > maxBytes.value)) uploadError.value = 'La imagen excede el tamaño permitido o está vacía.';
};
const upload = async () => {
    if (!selectedFile.value || sendingEvidence.value || invalidFile.value) return;
    sendingEvidence.value = true;
    feedback.value = '';
    uploadError.value = '';
    uploadProgress.value = null;
    try {
        evidenceOperation.value ||= supportOperationUuid();
        const data = new FormData();
        data.append('client_operation_uuid', evidenceOperation.value);
        data.append('file', selectedFile.value);
        const response = await axios.post(route('support.evidence.upload', props.ticket.uuid), data, {
            onUploadProgress: (event) => { if (event.total) uploadProgress.value = Math.min(100, Math.round(event.loaded * 100 / event.total)); },
        });
        if (response.data?.evidence?.status !== 'CONFIRMED') throw new Error('No se recibió la confirmación de la fotografía.');
        selectedFile.value = null;
        evidenceOperation.value = '';
        if (fileInput.value) fileInput.value.value = '';
        feedback.value = 'Fotografía agregada.';
        router.reload({ only: ['evidence', 'events', 'ticket', 'allowedTransitions'], preserveScroll: true });
    } catch (error) {
        uploadError.value = supportError(error, 'No fue posible confirmar la fotografía. Conserva el archivo e intenta nuevamente.');
    } finally {
        sendingEvidence.value = false;
        uploadProgress.value = null;
    }
};
</script>

<template>
    <Head :title="ticket.folio + ' · Soporte'" />
    <AuthenticatedLayout>
        <template #header>
            <div class="min-w-0"><Link :href="route('support.tickets.index')" class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-700 dark:text-indigo-300">← Tickets</Link><div class="flex flex-wrap items-center gap-3"><h1 class="text-xl font-semibold text-app">{{ ticket.folio }}</h1><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="supportBadgeClass(ticket.status)">{{ supportLabel(ticket.status) }}</span></div><p class="mt-2 break-words text-sm text-soft">{{ ticket.machine?.code }}{{ ticket.machine?.name ? ' · ' + ticket.machine.name : '' }}</p></div>
        </template>
        <div class="space-y-5">
            <p v-if="feedback" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200" role="status">{{ feedback }}</p>
            <p v-if="operationError" class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-200" role="alert">{{ operationError }}</p>
            <p v-if="immutable" class="card p-4 text-sm text-soft">Este reporte está cerrado. Su historial y evidencias se conservan para consulta.</p>
            <div class="grid items-start gap-5 lg:grid-cols-3">
                <div class="min-w-0 space-y-5 lg:col-span-2">
                    <section class="card p-5"><h2 class="break-words text-lg font-semibold text-app">{{ ticket.title }}</h2><div class="mt-3 flex flex-wrap gap-2 text-xs"><span class="inline-flex rounded-full px-2.5 py-1 font-semibold" :class="supportBadgeClass(ticket.severity)">Impacto {{ supportLabel(ticket.severity).toLowerCase() }}</span><span class="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ category }}</span></div><p class="mt-4 whitespace-pre-wrap break-words text-sm leading-relaxed text-app">{{ ticket.description }}</p><dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-soft">Reportado</dt><dd>{{ formatDateTime(ticket.reported_at) }}</dd></div><div><dt class="text-soft">Equipo</dt><dd>{{ ticket.device?.name || 'Sin equipo específico' }}</dd></div><div><dt class="text-soft">Ubicación</dt><dd>{{ ticket.location_available ? 'Ubicación obtenida' : 'Ubicación no disponible' }}</dd></div><div v-if="ticket.geofence_result"><dt class="text-soft">Contexto de geocerca</dt><dd>{{ supportLabel(ticket.geofence_result) }}</dd></div></dl></section>

                    <section class="card p-5" aria-labelledby="evidence-heading">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="evidence-heading" class="font-semibold text-app">Evidencias</h2><span class="text-sm text-soft">{{ photographs.length }} de {{ maxCount }} fotografías</span></div>
                        <div v-if="photographs.length" class="mt-4 grid gap-4 sm:grid-cols-2">
                            <figure v-for="(photo, index) in photographs" :key="photo.uuid" class="overflow-hidden rounded-xl border border-app">
                                <img v-if="photo.thumbnail && !failedThumbnails[photo.uuid]" :src="route('support.evidence.thumbnail', [ticket.uuid, photo.uuid])" :alt="'Fotografía ' + (index + 1) + ' del reporte ' + ticket.folio" loading="lazy" decoding="async" class="h-44 w-full bg-slate-50 object-contain dark:bg-slate-800" @error="failedThumbnails[photo.uuid] = true" />
                                <p v-else class="flex min-h-32 items-center justify-center p-4 text-center text-sm text-soft">La vista previa no está disponible.</p>
                                <figcaption class="space-y-1 p-3"><p class="text-sm font-medium">Fotografía {{ index + 1 }}</p><p class="text-xs text-soft">{{ supportFileSize(photo.size_bytes) }} · {{ formatDateTime(photo.confirmed_at) }}</p><a :href="route('support.evidence.download', [ticket.uuid, photo.uuid])" class="inline-flex min-h-11 items-center text-sm font-semibold text-indigo-700 dark:text-indigo-300" :aria-label="'Descargar fotografía ' + (index + 1)">Descargar imagen</a></figcaption>
                            </figure>
                        </div>
                        <p v-else class="mt-3 text-sm text-soft">Aún no hay fotografías confirmadas en este reporte.</p>
                        <form v-if="can('comment') && !immutable" class="mt-5 space-y-3 border-t border-app pt-4" @submit.prevent="upload">
                            <label class="block text-sm font-medium">Agregar fotografía<input ref="fileInput" type="file" :accept="acceptedMimes.join(',')" class="mt-2 block w-full min-w-0 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-3 file:text-sm file:font-medium dark:file:bg-slate-800 dark:file:text-slate-200" :disabled="busy || countReached" aria-describedby="evidence-hint evidence-error" @change="selectFile" /></label>
                            <p id="evidence-hint" class="text-xs text-soft">JPEG, PNG o WebP · Hasta {{ supportFileSize(maxBytes) }} por imagen.</p>
                            <p v-if="countReached" class="text-sm text-soft">Este reporte alcanzó el límite de fotografías.</p>
                            <p v-if="uploadError" id="evidence-error" class="text-sm text-rose-700 dark:text-rose-300" role="alert">{{ uploadError }}</p>
                            <p v-if="sendingEvidence" class="text-sm text-soft" role="status">{{ uploadProgress === null || uploadProgress === 100 ? 'Confirmando fotografía…' : 'Enviando fotografía: ' + uploadProgress + '%' }}</p>
                            <button class="min-h-11 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy || !selectedFile || invalidFile || countReached">{{ sendingEvidence ? 'Enviando…' : 'Guardar fotografía' }}</button>
                        </form>
                    </section>

                    <section class="card p-5" aria-labelledby="timeline-heading">
                        <h2 id="timeline-heading" class="font-semibold text-app">Historial</h2>
                        <p v-if="events.length === 100" class="mt-2 text-xs text-soft">Se muestran las 100 actividades más recientes.</p>
                        <ol v-if="events.length" class="mt-4 space-y-4"><li v-for="event in events" :key="event.uuid" class="border-l-2 border-app pl-4"><div class="flex flex-wrap items-baseline justify-between gap-2"><h3 class="text-sm font-semibold">{{ supportEventLabel(event.kind) }}</h3><time class="text-xs text-soft" :datetime="event.created_at">{{ formatDateTime(event.created_at) }}</time></div><p v-if="event.body" class="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed">{{ event.body }}</p><p v-if="event.metadata?.status" class="mt-1 text-sm text-soft">{{ supportLabel(event.metadata.previous_status) }} → {{ supportLabel(event.metadata.status) }}</p><p v-if="event.kind === 'support.ticket.assigned'" class="mt-1 text-sm text-soft">{{ event.metadata?.assignee_name || 'Sin responsable' }}</p></li></ol>
                        <p v-else class="mt-3 text-sm text-soft">No hay actividades disponibles para mostrar.</p>
                        <form v-if="can('comment') && !immutable" class="mt-5 space-y-3 border-t border-app pt-4" @submit.prevent="send(comment, 'support.tickets.comment', ['body'], 'Comentario agregado.')"><label class="block text-sm font-medium">Agregar comentario<textarea v-model="comment.body" required maxlength="5000" rows="3" class="mt-2 w-full border-app" :disabled="busy" placeholder="Agrega una observación para dar seguimiento." /><InputError :message="comment.errors.body" /></label><InputError :message="comment.errors.support || comment.errors.client_operation_uuid" /><button class="min-h-11 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy">{{ comment.processing ? 'Guardando…' : 'Guardar comentario' }}</button></form>
                    </section>
                </div>

                <aside class="min-w-0 space-y-5" aria-label="Atención del reporte">
                    <section class="card p-5"><h2 class="font-semibold text-app">Responsable</h2><p class="mt-3 break-words text-sm">{{ ticket.assignee?.name || 'Sin responsable asignado' }}</p><form v-if="can('assign') && !immutable" class="mt-4 space-y-3" @submit.prevent="send(assignment, 'support.tickets.assign', ['assignee_id'], 'Responsable actualizado.')"><label class="block text-sm">Asignar a<select v-model="assignment.assignee_id" class="mt-1 w-full border-app" :disabled="busy"><option value="">Sin responsable</option><option v-for="person in options?.assignees ?? []" :key="person.id" :value="person.id">{{ person.name }}</option></select><InputError :message="assignment.errors.assignee_id" /></label><InputError :message="assignment.errors.support || assignment.errors.client_operation_uuid" /><button class="min-h-11 rounded-xl border border-app px-4 py-2 text-sm font-semibold disabled:opacity-50" :disabled="busy">{{ assignment.processing ? 'Guardando…' : 'Guardar responsable' }}</button></form></section>
                    <section v-if="transitions.length && !immutable && can('resolve')" class="card p-5"><h2 class="font-semibold text-app">Actualizar estado</h2><form class="mt-4 space-y-3" @submit.prevent="changeStatus"><label class="block text-sm">Nuevo estado<select v-model="transition.status" required class="mt-1 w-full border-app" :disabled="busy"><option value="">Selecciona una acción</option><option v-for="status in transitions" :key="status" :value="status">{{ supportLabel(status) }}</option></select><InputError :message="transition.errors.status" /></label><label v-if="transition.status === 'RESOLVED'" class="block text-sm">Resolución<textarea v-model="transition.resolution" required maxlength="5000" rows="4" class="mt-1 w-full border-app" :disabled="busy" placeholder="Explica cómo se atendió el problema." /><InputError :message="transition.errors.resolution" /></label><InputError :message="transition.errors.support || transition.errors.client_operation_uuid" /><button class="min-h-11 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy || !transition.status">{{ transition.processing ? 'Guardando…' : 'Guardar estado' }}</button></form></section>
                    <section v-if="ticket.resolution" class="card p-5"><h2 class="font-semibold text-app">Resolución</h2><p class="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed">{{ ticket.resolution }}</p><p class="mt-3 text-xs text-soft">{{ formatDateTime(ticket.resolved_at) }}</p></section>
                    <section class="card p-5"><h2 class="font-semibold text-app">Tiempos objetivo</h2><p v-if="!ticket.response_due_at && !ticket.resolution_due_at" class="mt-3 text-sm text-soft">Este reporte no tiene tiempos objetivo configurados.</p><dl v-else class="mt-3 space-y-3 text-sm"><div><dt class="text-soft">Primera respuesta</dt><dd>{{ formatDateTime(ticket.response_due_at, 'Sin objetivo configurado') }}</dd><dd v-if="ticket.response_breached" class="mt-1 font-medium text-rose-700 dark:text-rose-300">Tiempo excedido</dd></div><div><dt class="text-soft">Resolución</dt><dd>{{ formatDateTime(ticket.resolution_due_at, 'Sin objetivo configurado') }}</dd><dd v-if="ticket.resolution_breached" class="mt-1 font-medium text-rose-700 dark:text-rose-300">Tiempo excedido</dd></div></dl><p class="mt-3 text-xs text-soft">Horarios de Ciudad de México.</p><TechnicalDetails><p>Origen: {{ supportLabel(ticket.source) }}</p><p>Prioridad operativa: {{ supportLabel(ticket.priority) }}</p><p>Última actualización: {{ formatDateTime(ticket.updated_at) }}</p><p v-if="ticket.external_system">Sistema de origen: {{ ticket.external_system }}</p><p v-if="ticket.external_reference">Referencia externa: {{ ticket.external_reference }}</p></TechnicalDetails></section>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
