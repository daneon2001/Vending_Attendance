<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import SupportActivityList from '@/Components/SupportActivityList.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { canSupport, supportError } from '@/presentation/support';
const props = defineProps({ machineId: [String, Number], ticketUuid: String });
const page = usePage();
const allowed = computed(() => canSupport(page.props.auth?.permissions));
const filters = computed(() => props.ticketUuid ? { ticket_uuid: props.ticketUuid } : { vending_machine_id: props.machineId });
const state = reactive({ available: false, loading: true, error: '', activities: [] });
let request;
async function load() {
    request?.abort();
    if (!allowed.value || (!props.machineId && !props.ticketUuid)) return;
    const current = new AbortController(); request = current;
    state.loading = true; state.error = ''; state.activities = []; state.available = false;
    try {
        const { data } = await axios.get(route('support.activities.summary'), { signal: current.signal, params: filters.value });
        if (!current.signal.aborted) { state.available = data.available; state.activities = data.activities ?? []; }
    } catch (error) {
        if (!current.signal.aborted) state.error = supportError(error, 'No fue posible consultar las actividades de soporte.');
    } finally { if (!current.signal.aborted) state.loading = false; }
}
onMounted(load);
watch(() => [props.machineId, props.ticketUuid], load);
onBeforeUnmount(() => request?.abort());
</script>
<template>
    <section v-if="allowed && (state.available || state.loading || state.error)" class="card min-w-0 overflow-hidden" aria-label="Actividades de campo relacionadas">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5"><h2 class="font-semibold text-app">{{ ticketUuid ? 'Actividades de campo' : 'Actividad de soporte' }}</h2><Link v-if="state.available" :href="route('support.activities.index', filters)" class="inline-flex min-h-11 items-center text-sm font-semibold text-indigo-700 dark:text-indigo-300">Ver historial de actividades</Link></div>
        <p v-if="state.loading" class="px-5 pb-5 text-sm text-soft" role="status">Consultando actividades…</p>
        <div v-else-if="state.error" class="px-5 pb-5"><p class="text-sm text-rose-700 dark:text-rose-300" role="alert">{{ state.error }}</p><SecondaryButton @click="load">Reintentar</SecondaryButton></div>
        <template v-else><SupportActivityList v-if="state.activities.length" :activities="state.activities" compact /><p v-else class="px-5 pb-5 text-sm text-soft">No hay actividades relacionadas disponibles en tu acceso.</p><p v-if="state.activities.length" class="p-5 text-xs text-soft">Hasta 5 actividades recientes · Horarios de Ciudad de México.</p></template>
    </section>
</template>
