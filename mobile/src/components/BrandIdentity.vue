<template>
  <div class="brand-identity" :class="{ 'brand-identity--full': full }">
    <img :src="full ? '/brand/one-logo.png' : '/brand/one-symbol.png'" alt="" :width="full ? 120 : 40" :height="full ? 120 : 40" />
    <div><span class="brand-name">MEDICAL LIFE ONE</span><span class="brand-descriptor">{{ full ? 'Tecnología en Movimiento' : 'Vending Attendance' }}</span></div>
  </div>
</template>
<script setup lang="ts">
withDefaults(defineProps<{ full?: boolean }>(), { full: false })
</script>
<style scoped>
.brand-identity { display: flex; align-items: center; gap: 10px; margin: 8px 0 24px; }
.brand-identity img { flex-shrink: 0; object-fit: contain; border-radius: 12px; }
.brand-name { display: block; color: #083873; font-weight: 800; font-size: .8rem; letter-spacing: .06em; }
.brand-descriptor { display: block; color: var(--ion-color-medium); font-size: .75rem; margin-top: 4px; }
.brand-identity--full { flex-direction: column; text-align: center; margin: 16px 0 28px; }
.brand-identity--full .brand-name { font-size: 1rem; }
.brand-identity--full .brand-descriptor { font-size: .875rem; }
</style>
