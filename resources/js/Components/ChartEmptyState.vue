<script setup>
const props = defineProps({
    title: {
        type: String,
        default: 'Sin datos',
    },
    message: {
        type: String,
        default: 'Sin datos para el rango seleccionado',
    },
});
</script>

<template>
    <div
        class="flex h-full flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 px-4 text-center text-sm text-muted dark:border-slate-700 dark:text-slate-300"
    >
        <p class="text-app text-base font-semibold">
            {{ props.title }}
        </p>
        <p class="text-xs">
            {{ props.message }}
        </p>
    </div>
</template>
