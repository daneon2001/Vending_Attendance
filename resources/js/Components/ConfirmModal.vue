<script setup>
import { computed } from 'vue';
import { useBodyScrollLock } from '@/composables/useBodyScrollLock';

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

useBodyScrollLock(() => props.show);

const dialogClasses = computed(() => [
    'w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-4 shadow-2xl transition dark:bg-slate-900 dark:text-slate-100 sm:p-6',
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
                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        class="w-full rounded-lg border border-app px-4 py-2 text-sm font-semibold text-muted hover:text-app dark:border-slate-700 dark:text-slate-200 sm:w-auto"
                        :disabled="loading"
                        @click="emit('cancel')"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
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
