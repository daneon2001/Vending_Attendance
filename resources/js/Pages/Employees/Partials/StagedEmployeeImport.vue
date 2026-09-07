<script setup>
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDateTime, friendlyError, statusLabel } from '@/presentation/labels';
import Modal from '@/Components/Modal.vue';
import axios from 'axios';
import { computed, reactive } from 'vue';
import { importSteps, navigateImport, canApplyImport, classificationLabels, confirmationPayload, emptyImportState, receivePreview, selectImportFile } from '../importState.js';

defineProps({ open: Boolean, limits: Object });
const emit = defineEmits(['close', 'applied']);
const state = reactive(emptyImportState());
const canApply = computed(() => state.step === 4 && canApplyImport(state));
const go = (step) => Object.assign(state, navigateImport(state, step));
const counts = { total_rows: 'Total', valid_new: 'Nuevos', valid_update: 'Actualizados', unchanged: 'Sin cambios', invalid: 'Con errores', duplicates: 'Duplicados', conflicts: 'Conflictos' };
const fieldLabels = { employee_number: 'Número de empleado', full_name: 'Nombre completo', status: 'Estado' };

function onFile(event) { Object.assign(state, selectImportFile(state, event.target.files?.[0] ?? null)); }
function failure(error) {
    const code = error.response?.data?.errors?.file?.[0];
    const messages = {
        INVALID_FILE_TYPE: 'Selecciona un CSV o XLSX válido.', EMPTY_FILE: 'El archivo no contiene empleados.',
        REQUIRED_HEADERS_MISSING: 'Se requieren columnas de número de empleado, nombre y estado.',
        AMBIGUOUS_HEADER: 'Hay varias columnas para el mismo campo. Conserva sólo una.',
        ROW_LIMIT_EXCEEDED: 'El archivo excede el límite de filas.', FILE_TOO_LARGE: 'El archivo excede el tamaño permitido.',
        INVALID_ENCODING: 'Guarda el CSV con codificación UTF-8.', XLSX_REQUIRES_ONE_SHEET: 'El XLSX debe contener una sola hoja.',
        UNSAFE_XLSX: 'El archivo contiene elementos no admitidos. Usa un XLSX sin macros, enlaces externos ni objetos.',
        XLSX_ARCHIVE_LIMIT: 'El archivo comprimido excede los límites permitidos.', MALFORMED_XLSX: 'No se pudo leer el XLSX.',
    };
    state.error = messages[code] ?? (error.response?.status === 410 ? 'La vista previa expiró. Vuelve a seleccionar el archivo.' : 'No fue posible completar la operación. Revisa el archivo, tu acceso y la conexión.');
}
async function upload() {
    if (!state.file || state.busy) return;
    state.busy = true; state.error = ''; state.confirmed = false;
    const form = new FormData(); form.append('file', state.file);
    try {
        const { data } = await axios.post(route('vending-employees.imports.store'), form);
        Object.assign(state, receivePreview(state, data));
    } catch (error) { failure(error); }
    finally { state.busy = false; }
}
async function page(number) {
    state.busy = true;
    try {
        const { data } = await axios.get(route('vending-employees.imports.show', state.preview.uuid), { params: { page: number } });
        Object.assign(state, receivePreview(state, data));
    } catch (error) { failure(error); }
    finally { state.busy = false; }
}
async function apply() {
    if (!canApply.value) return;
    const payload = confirmationPayload(state);
    state.busy = true; state.error = '';
    try {
        const { data } = await axios.post(route('vending-employees.imports.apply', state.preview.uuid), payload);
        Object.assign(state, receivePreview(state, data.preview));
        emit('applied');
    } catch (error) {
        if (error.response?.status === 409 && error.response.data.preview) {
            Object.assign(state, receivePreview(state, error.response.data.preview));
            state.step = 3;
            state.error = 'El catálogo cambió después de la vista previa. Revisa las diferencias y confirma de nuevo.';
        } else { failure(error); }
    } finally { state.busy = false; }
}
</script>

<template>
    <Modal :show="open" max-width="2xl" :closeable="!state.busy" @close="emit('close')">
        <section class="space-y-5 p-4 text-app sm:p-6" aria-labelledby="import-title">
            <div class="flex items-start justify-between gap-4">
                <div><h2 id="import-title" class="text-xl font-semibold">Importar empleados</h2><p class="mt-1 text-sm text-muted">Importa empleados desde un archivo CSV o Excel. Los datos administrados por Fortia no serán reemplazados.</p></div>
                <button type="button" class="btn-secondary min-h-11" :disabled="state.busy" @click="emit('close')">Cerrar</button>
            </div>
            <ol class="grid gap-2 text-sm sm:grid-cols-5" aria-label="Pasos de importación"><li v-for="(label, index) in importSteps" :key="label" :aria-current="state.step === index + 1 ? 'step' : undefined" class="rounded-lg border border-app p-2" :class="state.step === index + 1 ? 'bg-indigo-50 font-semibold text-indigo-900' : 'text-muted'">{{ index + 1 }}. {{ label }}</li></ol>
            <div v-if="state.step === 1" class="space-y-2 rounded-xl border border-app p-4">
                <label for="employee-import-file" class="block font-medium">Archivo CSV UTF-8 o XLSX de una sola hoja</label>
                <input id="employee-import-file" type="file" accept=".csv,.xlsx" class="block min-h-11 max-w-full text-sm" :disabled="state.busy" @change="onFile" />
                <p class="text-xs text-muted">Máximo {{ limits.file_kb }} KB y {{ limits.rows }} filas. Guarda el número como texto para conservar ceros iniciales. Columnas de número de empleado, nombre y estado.</p>
                <button type="button" class="btn-primary min-h-11" :disabled="!state.file || state.busy" @click="upload">{{ state.busy ? 'Procesando…' : 'Continuar: revisar columnas' }}</button>
            </div>
            <p v-if="state.error" role="alert" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-900">{{ state.error }}</p>
            <template v-if="state.preview">
                <div v-if="state.step === 2" class="rounded-xl border border-app p-4"><h3 class="font-semibold">Columnas detectadas</h3><dl class="mt-2 grid gap-3 text-sm sm:grid-cols-3"><div v-for="(header, field) in state.preview.mapping" :key="field"><dt class="text-muted">{{ header }}</dt><dd>{{ fieldLabels[field] }}</dd></div></dl></div>
                <dl v-if="state.step >= 3" class="grid grid-cols-2 gap-3 sm:grid-cols-4"><div v-for="(label, key) in counts" :key="key" class="rounded-lg border border-app p-3"><dt class="text-xs text-muted">{{ label }}</dt><dd class="mt-1 text-lg font-semibold">{{ state.preview.summary[key] }}</dd></div></dl>
                <p v-if="state.step === 3" class="text-sm text-muted">Válidos: {{ Number(state.preview.summary.valid_new) + Number(state.preview.summary.valid_update) + Number(state.preview.summary.unchanged) }}. Se excluyen filas inválidas, duplicadas y conflictos. Un archivo parcial no desactiva a los empleados ausentes ni crea asignaciones vending.</p>
                <div v-if="state.step === 3" class="overflow-x-auto rounded-xl border border-app">
                    <table class="min-w-full text-left text-sm"><caption class="sr-only">Vista previa y diferencias por empleado</caption><thead><tr><th class="p-3" scope="col">Fila</th><th class="p-3" scope="col">Número</th><th class="p-3" scope="col">Nombre</th><th class="p-3" scope="col">Estado</th><th class="p-3" scope="col">Clasificación / diferencias</th></tr></thead>
                        <tbody class="divide-y divide-app"><tr v-for="row in state.preview.rows.data" :key="row.row_number"><td class="p-3">{{ row.row_number }}</td><td class="p-3 font-mono">{{ row.employee_number }}</td><td class="p-3">{{ row.full_name }}</td><td class="p-3"><StatusBadge :value="row.normalized_status" /></td><td class="min-w-64 p-3"><p class="font-medium">{{ classificationLabels[row.classification] }}</p><p v-for="(change, field) in row.changes" :key="field" class="mt-1 text-xs text-muted">{{ fieldLabels[field] }}: {{ field === 'status' ? statusLabel(change.before) : change.before ?? 'Sin valor' }} → {{ field === 'status' ? statusLabel(change.after) : change.after }}</p><p v-for="error in row.errors" :key="error.code + error.field" class="mt-1 text-xs text-rose-700">{{ fieldLabels[error.field] || 'Fila' }}: {{ friendlyError(error.reason || error.code) }}</p><TechnicalDetails v-if="row.errors.length"><p v-for="error in row.errors" :key="error.code + error.field">{{ error.field }} · {{ error.code }}</p></TechnicalDetails></td></tr></tbody>
                    </table>
                </div>
                <div v-if="state.step === 3" class="flex items-center justify-between gap-3 text-sm"><button class="btn-secondary min-h-11" :disabled="state.busy || state.preview.rows.current_page <= 1" @click="page(state.preview.rows.current_page - 1)">Anterior</button><span>Página {{ state.preview.rows.current_page }} de {{ state.preview.rows.last_page }}</span><button class="btn-secondary min-h-11" :disabled="state.busy || state.preview.rows.current_page >= state.preview.rows.last_page" @click="page(state.preview.rows.current_page + 1)">Siguiente</button></div>
                <div v-if="state.step === 4 && state.preview.status === 'PREVIEW'" class="space-y-3 rounded-xl border border-app p-4"><label class="flex min-h-11 items-center gap-3"><input v-model="state.confirmed" type="checkbox" :disabled="state.busy" /><span class="text-sm">Revisé las columnas y las diferencias. Confirmo aplicar únicamente las filas nuevas y los cambios permitidos.</span></label><button class="btn-primary min-h-11" :disabled="!canApply" @click="apply">Confirmar y aplicar</button><p class="text-xs text-muted">Vista previa temporal hasta {{ formatDateTime(state.preview.expires_at) }}. El archivo original no se conserva.</p></div>
                <p v-if="state.preview.status === 'COMPLETED'" role="status" class="rounded-lg bg-emerald-50 p-4 text-emerald-900">Importación completada: {{ state.preview.summary.valid_new }} creados y {{ state.preview.summary.valid_update }} actualizados. Las asignaciones se administran por separado.</p>
                <div v-if="state.step < 5" class="flex justify-between gap-3"><button type="button" class="btn-secondary min-h-11" :disabled="state.busy" @click="go(state.step - 1)">Volver</button><button v-if="state.step < 4" type="button" class="btn-primary min-h-11" :disabled="state.busy" @click="go(state.step + 1)">Continuar</button></div>
                <button v-if="state.step === 5" type="button" class="btn-secondary min-h-11" @click="Object.assign(state, emptyImportState())">Importar otro archivo</button>
            </template>
        </section>
    </Modal>
</template>
