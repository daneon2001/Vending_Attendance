<script setup>
defineProps({
    title: {
        type: String,
        default: 'Sin resultados',
    },
    message: {
        type: String,
        default: 'No hay datos para mostrar con los filtros actuales.',
    },
});
</script>

<template>
    <section class="card p-6 text-center">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-soft dark:bg-slate-800">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 9h8M8 13h5m-7 8h12a2 2 0 002-2V7a2 2 0 00-2-2h-3.5l-1-1h-3l-1 1H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
        </div>
        <p class="mt-3 text-base font-semibold text-app">{{ title }}</p>
        <p class="mt-1 text-sm text-muted">{{ message }}</p>
    </section>
</template>
