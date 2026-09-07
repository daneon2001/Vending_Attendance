<script setup>
import { statusLabel, statusTone } from '@/presentation/labels';
defineProps({ value: [String, Number] });
const tones = {
 success: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
 warning: 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
 danger: 'bg-rose-50 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
 neutral: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
};
</script>
<template><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="tones[statusTone(value)]">{{ statusLabel(value) }}</span></template>
