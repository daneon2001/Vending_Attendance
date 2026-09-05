<template>
  <ion-app>
    <ion-router-outlet />
  </ion-app>
</template>

<script setup lang="ts">
import { IonApp, IonRouterOutlet } from '@ionic/vue';
</script>

<style>
:root { color-scheme: light; }
.page-shell { max-width: 760px; margin: 0 auto; padding: 20px 16px 40px; }
.muted { color: var(--ion-color-medium); }
.status-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.status-card { border: 1px solid var(--ion-color-light-shade); border-radius: 14px; padding: 14px; background: var(--ion-color-light); }
.status-card span { display: block; color: var(--ion-color-medium-shade); font-size: 0.8rem; margin-bottom: 4px; }
.status-card strong { font-size: 1.05rem; }
</style>
