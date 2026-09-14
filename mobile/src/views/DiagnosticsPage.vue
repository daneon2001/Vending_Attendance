<template>
  <ion-page>
    <ion-header><ion-toolbar><ion-buttons slot="start"><ion-back-button default-href="/home" text="Volver" /></ion-buttons><ion-title>Diagnóstico</ion-title></ion-toolbar></ion-header>
    <ion-content><main class="page-shell">
      <h1>Información de la aplicación</h1>
      <p class="muted">Consulta el estado sin crear registros ni reenviar pendientes. No se solicitan nuevos permisos.</p>
      <ion-button expand="block" fill="outline" :disabled="busy" @click="refresh">{{ busy ? 'Consultando…' : 'Actualizar diagnóstico' }}</ion-button>
      <p role="status" aria-live="polite">{{ busy ? 'Consultando el teléfono y el servidor…' : message }}</p>
      <dl :aria-busy="busy"><div v-for="row in rows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd></div></dl>
      <p class="muted">Para reportar una falla comparte el mensaje, versión, hora y paso realizado. No compartas credenciales, códigos, teléfonos ni fotografías sensibles.</p>
    </main></ion-content>
  </ion-page>
</template>
<script setup lang="ts">
import { ref } from 'vue'
import { IonBackButton, IonButton, IonButtons, IonContent, IonHeader, IonPage, IonTitle, IonToolbar, onIonViewWillEnter } from '@ionic/vue'
import { collectDiagnostics, type DiagnosticRow } from '@/diagnostics/collect'
const rows = ref<DiagnosticRow[]>([])
const busy = ref(false)
const message = ref('')
async function refresh() {
  if (busy.value) return
  busy.value = true
  try { rows.value = await collectDiagnostics(); message.value = 'Consulta finalizada. Los valores no disponibles no equivalen a cero.' }
  catch { rows.value = []; message.value = 'No fue posible consultar el diagnóstico. Intenta nuevamente.' }
  finally { busy.value = false }
}
onIonViewWillEnter(refresh)
</script>
<style scoped>
dl > div { padding: 16px 0; border-bottom: 1px solid var(--ion-color-light-shade); }
dt { color: var(--ion-color-medium); }
dd { margin: 6px 0 0; overflow-wrap: anywhere; }
</style>
