<template>
  <ion-page>
    <ion-header><ion-toolbar color="primary"><ion-title>Activar dispositivo</ion-title></ion-toolbar></ion-header>
    <ion-content>
      <main class="page-shell">
        <h1>Vincular esta instalación</h1>
        <p class="muted">Ingresa el código temporal proporcionado por el responsable de la máquina.</p>
        <ion-list inset>
          <ion-item><ion-input v-model="token" label="Código de activación" label-placement="stacked"
            type="password" autocomplete="off" :clear-input="true" /></ion-item>
          <ion-item><ion-input v-model="serial" label="Número de serie (opcional)" label-placement="stacked" /></ion-item>
        </ion-list>
        <ion-button expand="block" :disabled="busy || !validToken" @click="provision">
          {{ busy ? 'Activando…' : 'Activar dispositivo' }}
        </ion-button>
        <ion-text v-if="message" :color="failed ? 'danger' : 'success'"><p>{{ message }}</p></ion-text>
        <ion-note>No compartas el código de activación.</ion-note>
      </main>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { IonButton, IonContent, IonHeader, IonInput, IonItem, IonList, IonNote, IonPage, IonText, IonTitle, IonToolbar } from '@ionic/vue'
import { edgeStore, edgeSyncService, provisioningService } from '@/app/services'
import { safeErrorMessage } from '@/domain/errors'

const router = useRouter()
const token = ref('')
const serial = ref('')
const busy = ref(false)
const failed = ref(false)
const message = ref('')
const validToken = computed(() => /^[a-f0-9]{64}$/i.test(token.value.trim()))

async function provision(): Promise<void> {
  busy.value = true; failed.value = false; message.value = ''
  try {
    await edgeStore.initialize()
    await provisioningService.provision(token.value, serial.value || undefined)
    token.value = ''
    await router.replace('/home')
    await edgeSyncService.start()
  } catch (error) {
    failed.value = true
    message.value = safeErrorMessage(error)
  } finally {
    busy.value = false
  }
}
</script>
