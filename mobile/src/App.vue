<template>
  <ion-app>
    <ion-router-outlet />
  </ion-app>
</template>

<script setup lang="ts">
import { IonApp, IonRouterOutlet } from '@ionic/vue';
</script>

<style>
.page-shell { max-width: 640px; margin: 0 auto; padding: 16px max(20px, env(safe-area-inset-right)) calc(32px + max(env(safe-area-inset-bottom), var(--ion-safe-area-bottom, 0px))) max(20px, env(safe-area-inset-left)); overflow-wrap: anywhere; }
.page-shell h1 { font-size: 1.5rem; font-weight: 700; line-height: 1.3; margin: 24px 0 8px; }
.page-shell p { line-height: 1.5; }
ion-header { background: var(--ion-toolbar-background); }
ion-title { font-size: 1.125rem; font-weight: 650; }
ion-toolbar ion-back-button { --color: #ffffff; }
ion-button { min-height: 48px; text-transform: none; --border-radius: 10px; --box-shadow: none; }
ion-button::part(native) { white-space: normal; overflow-wrap: anywhere; }
ion-item::part(native):focus-visible, ion-button::part(native):focus-visible, summary:focus-visible { outline: 3px solid var(--ion-color-primary); outline-offset: -3px; }
.muted { color: var(--ion-color-medium); }
</style>
