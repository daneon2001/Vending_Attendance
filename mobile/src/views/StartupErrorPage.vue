<template>
  <ion-page>
    <ion-header><ion-toolbar><ion-title>Recuperación requerida</ion-title></ion-toolbar></ion-header>
    <ion-content class="ion-padding">
      <BrandIdentity full />
      <ion-card>
        <ion-card-header><ion-card-title>No fue posible iniciar la terminal</ion-card-title></ion-card-header>
        <ion-card-content>
          <p>No borres los datos ni vuelvas a activar el dispositivo.</p>
          <p>Reinicia la aplicación. Si el problema continúa, solicita apoyo al responsable para conservar los registros.</p>
          <ion-button class="ion-margin-top" expand="block" @click="retry">Reintentar</ion-button>
        </ion-card-content>
      </ion-card>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import BrandIdentity from '@/components/BrandIdentity.vue'
import {
  IonButton, IonCard, IonCardContent, IonCardHeader, IonCardTitle,
  IonContent, IonHeader, IonPage, IonTitle, IonToolbar,
} from '@ionic/vue'

const retry = () => globalThis.location.reload()
</script>
