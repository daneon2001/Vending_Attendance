<script setup>
import { computed } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    message: {
        type: String,
        default: '',
    },
    confirmLabel: {
        type: String,
        default: 'Confirmar',
    },
    cancelLabel: {
        type: String,
        default: 'Cancelar',
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['confirm', 'cancel']);

const dialogClasses = computed(() => [
    'w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl transition dark:bg-slate-900 dark:text-slate-100',
]);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur"
            @click.self="emit('cancel')"
        >
            <div :class="dialogClasses">
                <h3 class="text-lg font-semibold text-app dark:text-slate-100">{{ title }}</h3>
                <p class="mt-2 text-sm text-muted dark:text-slate-300">
                    {{ message }}
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        class="rounded-2xl border border-app px-4 py-2 text-sm font-semibold text-muted hover:text-app dark:border-slate-700 dark:text-slate-200"
                        :disabled="loading"
                        @click="emit('cancel')"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="loading"
                        @click="emit('confirm')"
                    >
                        <span v-if="loading">Procesando...</span>
                        <span v-else>{{ confirmLabel }}</span>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
 </template>
