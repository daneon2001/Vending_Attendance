<script setup>
import { computed } from 'vue';
import { assetUrl } from '@/utils/url';

const props = defineProps({
    variant: {
        type: String,
        default: 'full',
    },
});

const logoMap = {
    full: 'images/medical-life-one-full.png',
    short: 'images/medical-life-one-mark.png',
};

const assetVersion = import.meta.env.VITE_ASSET_VERSION ? `?v=${import.meta.env.VITE_ASSET_VERSION}` : '';

const logoSrc = computed(() => {
    const base = logoMap[props.variant] ?? logoMap.full;
    return `${assetUrl(base)}${assetVersion}`;
});
</script>

<template>
    <img :src="logoSrc" alt="Medical Life One" class="h-auto w-full rounded-xl object-contain" />
</template>
