<template>
  <ion-page>
    <ion-header><ion-toolbar><ion-title>Recuperación requerida</ion-title></ion-toolbar></ion-header>
    <ion-content class="ion-padding">
      <ion-card>
        <ion-card-header><ion-card-title>No fue posible abrir el almacenamiento local</ion-card-title></ion-card-header>
        <ion-card-content>
          <p>La identidad y la evidencia local no se borraron. No vuelvas a provisionar el dispositivo.</p>
          <p>Reinicia la aplicación. Si el problema continúa, conserva el equipo y sigue el runbook de incidente.</p>
          <ion-button class="ion-margin-top" expand="block" @click="retry">Reintentar</ion-button>
        </ion-card-content>
      </ion-card>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonButton, IonCard, IonCardContent, IonCardHeader, IonCardTitle,
  IonContent, IonHeader, IonPage, IonTitle, IonToolbar,
} from '@ionic/vue'

const retry = () => globalThis.location.reload()
</script>
