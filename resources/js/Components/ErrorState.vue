<script setup>
defineProps({
    title: {
        type: String,
        default: 'Ocurrio un error',
    },
    message: {
        type: String,
        default: 'No fue posible completar la solicitud.',
    },
    retryLabel: {
        type: String,
        default: 'Reintentar',
    },
});

const emit = defineEmits(['retry']);
</script>

<template>
    <section class="card border border-rose-200 bg-rose-50/70 p-4 dark:border-rose-500/40 dark:bg-rose-900/20">
        <p class="text-sm font-semibold text-rose-700 dark:text-rose-200">{{ title }}</p>
        <p class="mt-1 text-sm text-rose-600 dark:text-rose-100">{{ message }}</p>
        <button
            type="button"
            class="mt-3 inline-flex items-center justify-center rounded-xl border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100 dark:border-rose-500/60 dark:text-rose-100 dark:hover:bg-rose-900/30"
            @click="emit('retry')"
        >
            {{ retryLabel }}
        </button>
    </section>
</template>
