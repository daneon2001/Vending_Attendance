<script setup>
import { computed, onMounted, onUnmounted, reactive } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { canSupport, supportError } from '@/presentation/support';

const page = usePage();
const allowed = computed(() => canSupport(page.props.auth?.permissions));
const summary = reactive({ open: null, highCritical: null, loading: true, error: '' });
const requests = new AbortController();
const load = async () => {
    if (!allowed.value || requests.signal.aborted) return;
    summary.loading = true;
    summary.error = '';
    try {
        const { data } = await axios.get(route('support.summary'), { signal: requests.signal });
        if (![data?.open, data?.high_critical].every((count) => Number.isInteger(count) && count >= 0)) throw new Error('Invalid summary response');
        summary.open = data.open;
        summary.highCritical = data.high_critical;
    } catch (error) {
        if (requests.signal.aborted) return;
        summary.open = null;
        summary.highCritical = null;
        summary.error = supportError(error, 'No fue posible consultar el resumen de soporte.');
    } finally {
        summary.loading = false;
    }
};
onMounted(load);
onUnmounted(() => requests.abort());
</script>

<template>
    <section v-if="allowed" class="card flex flex-wrap items-center justify-between gap-3 p-4" aria-label="Resumen de soporte">
        <div><h2 class="text-sm font-semibold text-app">Soporte</h2><p v-if="summary.loading" class="mt-1 text-sm text-soft" role="status">Consultando reportes…</p><p v-else-if="summary.error" class="mt-1 text-sm text-rose-700 dark:text-rose-300" role="alert">{{ summary.error }}</p><p v-else class="mt-1 text-sm text-soft"><span class="font-semibold text-app">{{ summary.open }}</span> abiertos · <span class="font-semibold text-app">{{ summary.highCritical }}</span> de impacto alto o crítico</p></div>
        <div class="flex flex-wrap items-center gap-3"><button type="button" class="min-h-11 px-2 text-sm font-medium text-soft disabled:opacity-50" :disabled="summary.loading" @click="load">Actualizar</button><Link :href="route('support.tickets.index')" class="inline-flex min-h-11 items-center rounded-xl border border-app px-4 py-2 text-sm font-semibold text-indigo-700 dark:text-indigo-300">Ver tickets</Link></div>
    </section>
</template>
