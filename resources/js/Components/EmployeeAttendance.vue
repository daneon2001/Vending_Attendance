<script setup>
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    employeeId: {
        type: Number,
        required: true,
    },
});

const from = ref('');
const to = ref('');
const logs = ref([]);
const loading = ref(false);
const errorMessage = ref('');

const search = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await axios.get(`/api/attendance/employee/${props.employeeId}`, {
            params: {
                from: from.value || undefined,
                to: to.value || undefined,
            },
        });
        logs.value = data.data ?? [];
    } catch (error) {
        errorMessage.value = 'No se pudieron cargar las asistencias.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <section class="card space-y-4 p-4">
        <h2 class="text-app text-lg font-semibold">Asistencias</h2>
        <div class="flex flex-wrap gap-3 text-sm">
            <label class="flex flex-col">
                <span class="text-xs font-semibold uppercase text-soft">Desde</span>
                <input v-model="from" type="date" class="rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900" />
            </label>
            <label class="flex flex-col">
                <span class="text-xs font-semibold uppercase text-soft">Hasta</span>
                <input v-model="to" type="date" class="rounded-2xl border border-app bg-white px-3 py-2 dark:bg-slate-900" />
            </label>
            <button
                class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                @click="search"
            >
                Buscar
            </button>
        </div>

        <p v-if="errorMessage" class="text-sm text-rose-600">{{ errorMessage }}</p>

        <div class="overflow-hidden rounded-2xl border border-app">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft dark:bg-slate-900/40">
                    <tr>
                        <th class="px-3 py-2">Fecha/hora</th>
                        <th class="px-3 py-2">Reloj</th>
                        <th class="px-3 py-2">Tipo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="3" class="px-3 py-4 text-center text-soft">Cargando...</td>
                    </tr>
                    <tr v-for="log in logs" :key="log.id" class="border-t border-app text-muted">
                        <td class="px-3 py-2">{{ log.log_date }}</td>
                        <td class="px-3 py-2">{{ log.device_id ?? '-' }}</td>
                        <td class="px-3 py-2">{{ log.log_type }}</td>
                    </tr>
                    <tr v-if="!loading && !logs.length">
                        <td colspan="3" class="px-3 py-4 text-center text-soft">Sin registros.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
