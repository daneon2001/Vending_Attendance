<script setup>
import { computed } from 'vue';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    mode: {
        type: String,
        default: 'create',
    },
    form: {
        type: Object,
        required: true,
    },
    errors: {
        type: Object,
        default: () => ({}),
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'submit']);

useBodyScrollLock(() => props.open);

const title = computed(() => (props.mode === 'create' ? 'Nueva empresa' : 'Editar empresa'));

const statusOptions = [
    { value: 1, label: 'Activa' },
    { value: 0, label: 'Inactiva' },
];
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
    >
        <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        {{ mode === 'create' ? 'Registrar' : 'Actualizar' }}
                    </p>
                    <h3 class="text-2xl font-semibold text-slate-900">
                        {{ title }}
                    </h3>
                </div>
                <button
                    type="button"
                    class="rounded-full border border-slate-200 p-2 text-slate-500 hover:text-slate-900"
                    aria-label="Cerrar formulario de empresa"
                    @click="emit('close')"
                >
                    <span class="sr-only">Cerrar</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form class="mt-6 space-y-4" @submit.prevent="emit('submit')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium text-slate-600">
                        Nombre
                        <input
                            v-model="form.name"
                            type="text"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        />
                        <span v-if="errors.name" class="text-xs text-rose-600">
                            {{ errors.name[0] }}
                        </span>
                    </label>

                    <label class="text-sm font-medium text-slate-600">
                        Clave
                        <input
                            v-model="form.code"
                            type="text"
                            maxlength="20"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm uppercase"
                        />
                        <span v-if="errors.code" class="text-xs text-rose-600">
                            {{ errors.code[0] }}
                        </span>
                    </label>
                </div>

                <label class="text-sm font-medium text-slate-600">
                    Estatus
                    <select
                        v-model.number="form.status"
                        class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                    >
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <span v-if="errors.status" class="text-xs text-rose-600">
                        {{ errors.status[0] }}
                    </span>
                </label>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="w-full rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 sm:w-auto"
                        @click="emit('close')"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60 sm:w-auto"
                        :disabled="loading"
                    >
                        <span v-if="loading">Guardando...</span>
                        <span v-else>Guardar</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
