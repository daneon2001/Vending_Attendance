<template>
  <SupportLayout title="Verificar este equipo">
    <h1>Verificación del equipo</h1><p class="muted">Comprueba la conexión, los datos guardados y los permisos disponibles.</p>
    <ion-button expand="block" :disabled="busy || !context" @click="verify">{{ busy ? 'Verificando equipo…' : 'Iniciar verificación' }}</ion-button>
    <p v-if="busy" role="status" aria-live="polite">Estamos revisando el equipo. La ubicación puede tardar unos segundos.</p>
    <template v-if="receipt">
      <p class="support-card" role="status">{{ receipt.status === 'ACKNOWLEDGED' ? 'Verificación enviada y confirmada.' : receipt.status === 'PENDING' || receipt.status === 'SENDING' ? 'Verificación guardada. Se enviará al recuperar conexión.' : 'La verificación permanece guardada y requiere revisión.' }}</p>
      <p v-if="confirmed?.ticket_folio">Se generó el ticket {{ confirmed.ticket_folio }}.</p>
      <ul class="support-list">
        <li v-for="check in checks" :key="check.code"><h2>{{ checkLabel(check.code) }}</h2><p>{{ verificationResult(check.result) }}</p><p v-if="check.code === 'CAMERA_AVAILABILITY'" class="support-caption">La captura de una fotografía permite comprobar el funcionamiento de la cámara.</p></li>
      </ul>
      <p class="support-caption">{{ dateLabel(String(receipt.payload.completed_at ?? '')) }}</p>
    </template>
    <p v-if="message" class="support-error" role="alert">{{ message }}</p>
  </SupportLayout>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { IonButton, onIonViewWillEnter } from '@ionic/vue'
import SupportLayout from './SupportLayout.vue'
import { useSupport } from './useSupport'
import { supportStore, supportSync, supportVerification } from './services'
import type { SupportOperation, VerificationInput } from './types'
import { checkLabel, dateLabel, supportMessage, verificationResult } from './presentation'

const { context, deviceUuid, state } = useSupport()
const busy = ref(false); const message = ref(''); const receipt = ref<SupportOperation | null>(null)
const confirmed = computed(() => receipt.value?.result?.verification as { ticket_folio?: string; checks?: VerificationInput['checks'] } | undefined)
const checks = computed(() => confirmed.value?.checks ?? (receipt.value?.payload.checks as VerificationInput['checks'] | undefined) ?? [])
async function refresh(): Promise<void> {
  const owner = deviceUuid.value; const machine = context.value?.machine.uuid
  if (!owner || !machine) { receipt.value = null; return }
  const last = await supportStore.lastVerification(owner)
  if (deviceUuid.value === owner && context.value?.machine.uuid === machine) receipt.value = last?.payload.captured_machine_uuid === machine ? last : null
}
watch([deviceUuid, () => context.value?.machine.uuid, () => state.value.phase], () => {
  if (receipt.value?.payload.captured_machine_uuid !== context.value?.machine.uuid) receipt.value = null
  void refresh().catch(() => undefined)
}, { immediate: true })
onIonViewWillEnter(() => { void refresh().catch(() => undefined) })
async function verify(): Promise<void> {
  if (busy.value) return
  busy.value = true; message.value = ''
  try { await supportVerification.run(); await refresh(); void supportSync.syncNow() }
  catch (error) { message.value = supportMessage(error) } finally { busy.value = false }
}
</script>
