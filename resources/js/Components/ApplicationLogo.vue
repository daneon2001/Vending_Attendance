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

const assetVersion = import.meta.env.VITE_ASSET_VERSION ? `?v=${import.meta.env.VITE_ASSET_VERSION}` : '';

const logoSrc = computed(() => {
    const base = logoMap[props.variant] ?? logoMap.full;
    return `${base}${assetVersion}`;
});
</script>

<template>
    <img :src="logoSrc" alt="Medical Life" class="h-auto w-full object-contain" />
</template>
