<script setup>
defineProps({
    title: {
        type: String,
        default: 'Cargando informacion...',
    },
    rows: {
        type: Number,
        default: 3,
    },
});
</script>

<template>
    <section class="card p-4">
        <p class="text-sm font-semibold text-app">{{ title }}</p>
        <div class="mt-3 space-y-2">
            <div
                v-for="index in rows"
                :key="index"
                class="h-10 animate-pulse rounded-xl bg-slate-100/80 dark:bg-slate-800/70"
            />
        </div>
    </section>
</template>
