<template>
  <ion-page><ion-header><ion-toolbar>
    <ion-buttons slot="start"><ion-back-button :default-href="back" text="Volver" :aria-label="`Volver desde ${title}`" /></ion-buttons>
    <ion-title>{{ title }}</ion-title>
  </ion-toolbar></ion-header><ion-content><main class="page-shell"><slot /></main></ion-content></ion-page>
</template>
<script setup lang="ts">
import { IonPage, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonContent } from '@ionic/vue'
import './support.css'
withDefaults(defineProps<{ title: string; back?: string }>(), { back: '/support' })
</script>
