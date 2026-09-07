<script setup>
import { Link } from '@inertiajs/vue3';
import { paginationLabel } from '@/presentation/labels';
defineProps({ links: Array });
</script>
<template><nav class="flex flex-wrap gap-2" aria-label="Paginación"><template v-for="(link, index) in links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="min-h-11 rounded-lg border border-app px-3 py-2 text-sm" :class="{ 'bg-indigo-600 text-white': link.active }" :aria-current="link.active ? 'page' : undefined">{{ paginationLabel(link.label) }}</Link><span v-else class="px-3 py-2 text-sm text-soft" aria-disabled="true">{{ paginationLabel(link.label) }}</span></template></nav></template>
