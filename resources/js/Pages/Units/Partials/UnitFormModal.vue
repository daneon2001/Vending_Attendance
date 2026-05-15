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
    companies: {
        type: Array,
        default: () => [],
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

const statusOptions = [
    { value: 1, label: 'Activa' },
    { value: 0, label: 'Inactiva' },
];

const title = computed(() =>
    props.mode === 'create' ? 'Nueva unidad' : 'Editar unidad',
);
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
    >
        <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl sm:p-6">
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
                    aria-label="Cerrar formulario de unidad"
                    @click="emit('close')"
                >
                    <span class="sr-only">Cerrar</span>
                    ✕
                </button>
            </div>

            <form class="mt-6 space-y-4" @submit.prevent="emit('submit')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium text-slate-600">
                        Empresa
                        <select
                            v-model="form.company_id"
                            data-select-search="on"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        >
                            <option disabled value="">Selecciona empresa</option>
                            <option v-for="company in companies" :key="company.id" :value="company.id">
                                {{ company.name }}
                            </option>
                        </select>
                        <span v-if="errors.company_id" class="text-xs text-rose-600">
                            {{ errors.company_id[0] }}
                        </span>
                    </label>
                    <label class="text-sm font-medium text-slate-600">
                        Código
                        <input
                            v-model="form.code"
                            type="text"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm uppercase"
                        />
                        <span v-if="errors.code" class="text-xs text-rose-600">
                            {{ errors.code[0] }}
                        </span>
                    </label>
                </div>

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
                        Descripción
                        <input
                            v-model="form.description"
                            type="text"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        />
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="text-sm font-medium text-slate-600">
                        Ciudad
                        <input
                            v-model="form.city"
                            type="text"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        />
                    </label>
                    <label class="text-sm font-medium text-slate-600">
                        Estado
                        <input
                            v-model="form.state"
                            type="text"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        />
                    </label>
                    <label class="text-sm font-medium text-slate-600">
                        País
                        <input
                            v-model="form.country"
                            type="text"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        />
                    </label>
                </div>

                <label class="text-sm font-medium text-slate-600">
                    Dirección
                    <textarea
                        v-model="form.address"
                        rows="2"
                        class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                    />
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium text-slate-600">
                        Zona horaria
                        <input
                            v-model="form.timezone"
                            type="text"
                            placeholder="America/Mexico_City"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        />
                    </label>
                    <label class="text-sm font-medium text-slate-600">
                        Estado
                        <select
                            v-model.number="form.status"
                            class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"
                        >
                            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                </div>

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
