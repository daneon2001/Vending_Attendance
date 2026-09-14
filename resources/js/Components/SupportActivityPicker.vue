<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { activityOptionLabel } from '@/presentation/supportActivities';
import { supportError } from '@/presentation/support';

const props = defineProps({ modelValue: [String, Number], label: String, kind: String, purpose: { type: String, default: 'filter' }, machineId: [String, Number], activityType: String, disabled: Boolean, required: Boolean });
const emit = defineEmits(['update:modelValue', 'selected']);
const search = ref('');
const rows = ref([]);
const selected = ref(null);
const loading = ref(false);
const error = ref('');
const searched = ref(false);
const page = ref(1);
const hasMore = ref(false);
let request;
const valueOf = row => props.kind === 'tickets' ? row.uuid : row.id;
const choices = computed(() => selected.value && !rows.value.some(row => String(valueOf(row)) === String(valueOf(selected.value))) ? [selected.value, ...rows.value] : rows.value);
const missingSelection = computed(() => props.modelValue && !choices.value.some(row => String(valueOf(row)) === String(props.modelValue)));
async function load(number = 1) {
    request?.abort();
    const current = new AbortController();
    request = current;
    loading.value = true; error.value = '';
    try {
        const { data } = await axios.get(route('support.activities.options'), { signal: current.signal, params: {
            kind: props.kind, purpose: props.purpose, search: search.value, page: number,
            vending_machine_id: props.machineId || undefined, activity_type: props.activityType || undefined,
        } });
        if (current.signal.aborted) return;
        rows.value = data.data; page.value = data.page; hasMore.value = data.has_more; searched.value = true;
    } catch (failure) {
        if (!current.signal.aborted) error.value = supportError(failure, 'No fue posible consultar las opciones. Intenta nuevamente.');
    } finally { if (!current.signal.aborted) loading.value = false; }
}
function select(value) {
    selected.value = choices.value.find(row => String(valueOf(row)) === String(value)) ?? null;
    emit('update:modelValue', value); emit('selected', selected.value);
}
watch(() => [props.machineId, props.activityType, props.purpose], () => {
    request?.abort(); rows.value = []; selected.value = null; searched.value = false; loading.value = false; error.value = ''; page.value = 1; hasMore.value = false;
});
watch(() => props.modelValue, value => { if (!value) selected.value = null; });
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <div class="min-w-0 space-y-2" data-select-search-root="ignore">
        <div class="flex flex-wrap items-end gap-2">
            <label class="min-w-0 flex-1 text-sm">Buscar {{ label.toLowerCase() }}<input v-model="search" maxlength="160" class="mt-1 w-full border-app" :disabled="disabled || loading" @keydown.enter.prevent="load()" /></label>
            <SecondaryButton :disabled="disabled || loading" @click="load()">{{ loading ? 'Buscando…' : 'Buscar' }}</SecondaryButton>
        </div>
        <label class="block text-sm">{{ label }}<select :value="modelValue ?? ''" :required="required" :disabled="disabled || loading" class="mt-1 w-full min-w-0 border-app" @change="select($event.target.value)">
            <option value="">Sin selección</option><option v-if="missingSelection" :value="modelValue">Selección actual</option>
            <option v-for="row in choices" :key="valueOf(row)" :value="valueOf(row)">{{ activityOptionLabel(kind, row) }}</option>
        </select></label>
        <p v-if="error" class="text-sm text-rose-700 dark:text-rose-300" role="alert">{{ error }}</p>
        <p v-else-if="searched" class="text-xs text-soft" role="status">{{ rows.length ? 'Selecciona una opción. Página ' + page + ' · Hasta 20 resultados por página.' : 'No hay opciones disponibles para esta búsqueda dentro de tu acceso.' }}</p>
        <p v-else class="text-xs text-soft">Busca por número, código o nombre. Los resultados respetan tu acceso.</p>
        <div v-if="searched && (page > 1 || hasMore)" class="flex flex-wrap gap-2" :aria-label="'Paginación de ' + label.toLowerCase()">
            <SecondaryButton :disabled="loading || disabled || page <= 1" @click="load(page - 1)">Anterior</SecondaryButton>
            <SecondaryButton :disabled="loading || disabled || !hasMore" @click="load(page + 1)">Siguiente</SecondaryButton>
        </div>
    </div>
</template>
