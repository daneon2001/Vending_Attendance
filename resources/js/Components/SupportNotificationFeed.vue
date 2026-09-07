<script setup>
import { computed, onMounted, onUnmounted, reactive } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDateTime } from '@/presentation/labels';
import { canSupport, supportError, supportEventLabel } from '@/presentation/support';

const page = usePage();
const allowed = computed(() => canSupport(page.props.auth?.permissions));
const feed = reactive({ notifications: [], unreadCount: null, loading: true, loaded: false, marking: null, error: '', feedback: '' });
const requests = new AbortController();
const load = async () => {
    if (!allowed.value || requests.signal.aborted) return;
    feed.loading = true;
    feed.error = '';
    try {
        const { data } = await axios.get(route('support.notifications.index'), { signal: requests.signal });
        if (!Array.isArray(data?.notifications) || !Number.isInteger(data?.unread_count) || data.unread_count < 0) throw new Error('Invalid feed response');
        feed.notifications = data.notifications;
        feed.unreadCount = data.unread_count;
        feed.loaded = true;
    } catch (error) {
        if (requests.signal.aborted) return;
        feed.notifications = [];
        feed.unreadCount = null;
        feed.loaded = false;
        feed.error = supportError(error, 'No fue posible consultar los avisos. Intenta actualizar nuevamente.');
    } finally {
        feed.loading = false;
    }
};
const markRead = async (notification) => {
    if (feed.marking || feed.loading || notification.read_at || !allowed.value) return;
    feed.marking = notification.id;
    feed.feedback = '';
    feed.error = '';
    try {
        await axios.post(route('support.notifications.read', notification.id), {}, { signal: requests.signal });
        feed.feedback = 'Aviso marcado como leído.';
        await load();
    } catch (error) {
        if (!requests.signal.aborted) feed.error = supportError(error, 'No fue posible marcar el aviso. Intenta nuevamente.');
    } finally {
        feed.marking = null;
    }
};
onMounted(load);
onUnmounted(() => requests.abort());
</script>

<template>
    <details v-if="allowed" class="card p-4 sm:p-5">
        <summary class="min-h-11 cursor-pointer py-2 font-semibold text-app">Avisos de soporte <span v-if="feed.unreadCount !== null" class="ml-2 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ feed.unreadCount }} sin leer</span><span v-else class="ml-2 text-xs font-normal text-soft">{{ feed.loading ? 'Consultando…' : 'Sin información' }}</span></summary>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-soft">Avisos más recientes. El contador incluye todos los avisos sin leer dentro de tu acceso.</p><SecondaryButton :disabled="feed.loading || !!feed.marking" @click="load">{{ feed.loading ? 'Actualizando…' : 'Actualizar avisos' }}</SecondaryButton></div>
        <p v-if="feed.error" class="mt-3 text-sm text-rose-700 dark:text-rose-300" role="alert">{{ feed.error }}</p>
        <p v-if="feed.feedback" class="mt-3 text-sm text-emerald-700 dark:text-emerald-300" role="status">{{ feed.feedback }}</p>
        <p v-if="feed.loading" class="mt-3 text-sm text-soft" role="status">Consultando avisos…</p>
        <ul v-if="feed.notifications.length" class="mt-3 max-h-96 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800" aria-label="Avisos recientes de soporte"><li v-for="notification in feed.notifications" :key="notification.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><Link :href="route('support.tickets.show', notification.ticket_uuid)" class="inline-flex min-h-11 items-center break-words text-sm font-semibold text-indigo-700 dark:text-indigo-300">{{ notification.folio }} · {{ supportEventLabel(notification.kind) }}</Link><p class="text-xs text-soft">{{ formatDateTime(notification.created_at) }} · {{ notification.read_at ? 'Leído' : 'Sin leer' }}</p></div><button v-if="!notification.read_at" type="button" class="min-h-11 shrink-0 rounded-xl border border-app px-3 py-2 text-sm font-medium disabled:opacity-50" :disabled="feed.loading || !!feed.marking" :aria-label="'Marcar aviso de ' + notification.folio + ' como leído'" @click="markRead(notification)">{{ feed.marking === notification.id ? 'Guardando…' : 'Marcar como leído' }}</button></li></ul>
        <p v-else-if="feed.loaded && !feed.loading" class="mt-3 text-sm text-soft">No hay avisos de soporte disponibles para tu cuenta.</p>
        <p class="mt-3 text-xs text-soft">Horarios de Ciudad de México. Usa Actualizar avisos para consultar novedades.</p>
    </details>
</template>
