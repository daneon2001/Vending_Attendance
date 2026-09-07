<script setup>
defineProps({ count: { type: Number, default: 0 } });
</script>
<template><details class="col-span-full rounded-xl border border-app p-3"><summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold text-app">Filtros avanzados<span v-if="count"> ({{ count }})</span></summary><div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><slot /></div></details></template>
