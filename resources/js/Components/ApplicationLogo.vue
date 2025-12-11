<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'full',
    },
});

const logoMap = {
    full: '/images/medical-life-logo.png',
    short: '/images/medical-life-logo_short.png',
};

const logoSrc = computed(() => logoMap[props.variant] ?? logoMap.full);
</script>

<template>
    <img :src="logoSrc" alt="Medical Life" class="h-auto w-full object-contain" />
</template>
