<template>
  <SupportLayout title="Detalle del reporte" back="/support/reports">
    <template v-if="ticket">
      <SupportTicketSummary :ticket="ticket" />
      <section v-if="localPhotos.length || ticket.detail?.evidence.length" class="support-stack" aria-labelledby="ticket-photos">
        <h2 id="ticket-photos">Fotografías</h2>
        <p v-for="(photo, index) in localPhotos" :key="photo.localUuid" class="support-caption">Foto {{ index + 1 }} · {{ photo.state === 'CONFIRMED' ? 'Confirmada' : photo.deliveryStatus ? deliveryLabel(photo.deliveryStatus) : 'Guardada en el equipo' }}</p>
        <div v-for="(photo, index) in ticket.detail?.evidence ?? []" :key="photo.uuid">
          <ion-button v-if="photo.thumbnail && !thumbnails[photo.uuid]" fill="outline" :disabled="thumbnailBusy || network !== 'ONLINE'" @click="showThumbnail(photo.uuid)">Ver fotografía {{ index + 1 }}</ion-button>
          <img v-if="thumbnails[photo.uuid]" :src="thumbnails[photo.uuid]" :alt="`Fotografía ${index + 1} del reporte`" class="ticket-thumbnail" />
        </div>
        <p v-if="network !== 'ONLINE'" class="support-caption">Conecta el equipo para consultar las vistas previas confirmadas.</p>
      </section>
      <section class="support-stack" aria-labelledby="ticket-timeline">
        <h2 id="ticket-timeline">Actividad y comentarios</h2>
        <p v-if="!ticket.detail?.events.length && !comments.length" class="muted">La actividad aparecerá cuando se confirme el reporte.</p>
        <ul class="support-list">
          <li v-for="event in ticket.detail?.events ?? []" :key="event.uuid"><h3>{{ eventLabel(event) }}</h3><p v-if="event.body" class="support-body">{{ event.body }}</p><p class="support-caption">{{ dateLabel(event.created_at) }}</p></li>
          <li v-for="comment in comments" :key="comment.uuid"><h3>Comentario guardado</h3><p class="support-body">{{ comment.payload.body }}</p><p class="support-caption">{{ deliveryLabel(comment.status) }}</p></li>
        </ul>
      </section>
      <form v-if="canComment" class="support-stack" @submit.prevent="addComment">
        <label class="support-field" for="support-comment">Agregar comentario<textarea id="support-comment" v-model="body" required maxlength="10000" rows="3" :disabled="busy" /></label>
        <ion-button type="submit" :disabled="busy || !body.trim()">{{ busy ? 'Guardando…' : 'Enviar comentario' }}</ion-button>
      </form>
      <p v-else class="support-caption">Este reporte ya no admite comentarios.</p>
      <ion-button fill="clear" :disabled="state.phase === 'SYNCING' || loading" @click="update">Actualizar reporte</ion-button>
    </template>
    <p v-else role="status">{{ ready && deviceUuid ? 'No se encontró un reporte permitido para este equipo.' : 'Consultando reporte…' }}</p>
    <p v-if="message" class="support-error" role="alert">{{ message }}</p>
  </SupportLayout>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { IonButton, onIonViewWillEnter, onIonViewWillLeave } from '@ionic/vue'
import { useRoute } from 'vue-router'
import SupportLayout from './SupportLayout.vue'
import SupportTicketSummary from './SupportTicketSummary.vue'
import { useSupport } from './useSupport'
import { supportApi, supportStore, supportSync } from './services'
import type { LocalTicket, LocalEvidence, SupportOperation } from './types'
import { dateLabel, deliveryLabel, eventLabel, supportMessage } from './presentation'

const route = useRoute()
const { deviceUuid, context, ready, network, state } = useSupport()
const ticket = ref<LocalTicket | null>(null); const localPhotos = ref<LocalEvidence[]>([]); const comments = ref<SupportOperation[]>([])
const thumbnails = ref<Record<string, string>>({}); const thumbnailBusy = ref(false)
const busy = ref(false); const loading = ref(false); const body = ref(''); const message = ref('')
let thumbnailGeneration = 0
const canComment = computed(() => ticket.value && ticket.value.status !== 'DRAFT' && !['CLOSED', 'CANCELLED'].includes(ticket.value.server?.status ?? ''))
async function load(remote = true): Promise<void> {
  if (!deviceUuid.value || !context.value || typeof route.params.localUuid !== 'string' || loading.value) return
  loading.value = true
  const uuid = route.params.localUuid
  const owner = deviceUuid.value
  const machine = context.value.machine.uuid
  const current = () => route.params.localUuid === uuid && deviceUuid.value === owner && context.value?.machine.uuid === machine
  try {
    const stored = await supportStore.getTicket(uuid, owner)
    if (!current()) return
    ticket.value = stored?.machineUuid === machine ? stored : null
    if (!ticket.value) return
    if (remote && ticket.value.serverUuid && network.value === 'ONLINE') {
      try {
        const detail = await supportApi.ticket(owner, ticket.value.serverUuid)
        if (!current()) return
        await supportStore.cacheTicketDetail(uuid, owner, detail)
        const next = await supportStore.getTicket(uuid, owner)
        if (!current()) return
        ticket.value = next
      }
      catch { /* Offline/denied refresh does not erase the last permitted local receipt. */ }
    }
    const photos = await supportStore.evidenceForTicket(uuid, owner)
    const pending = await supportStore.comments(uuid, owner)
    if (!current()) return
    localPhotos.value = photos; comments.value = pending
  } finally { loading.value = false; if (!current()) void load().catch(() => undefined) }
}
watch([deviceUuid, () => context.value?.machine.uuid, () => state.value.phase, () => route.params.localUuid], () => {
  if (ticket.value && (ticket.value.deviceUuid !== deviceUuid.value || ticket.value.machineUuid !== context.value?.machine.uuid)) { ticket.value = null; localPhotos.value = []; comments.value = []; releaseThumbnails() }
  void load().catch(() => undefined)
}, { immediate: true })
onIonViewWillEnter(() => { void load().catch(() => undefined) })
function releaseThumbnails(): void { thumbnailGeneration++; for (const url of Object.values(thumbnails.value)) URL.revokeObjectURL(url); thumbnails.value = {} }
onIonViewWillLeave(releaseThumbnails); onBeforeUnmount(releaseThumbnails)
async function showThumbnail(evidenceUuid: string): Promise<void> {
  if (!deviceUuid.value || !ticket.value?.serverUuid || thumbnailBusy.value) return
  thumbnailBusy.value = true; message.value = ''
  const generation = thumbnailGeneration
  try { const blob = await supportApi.thumbnail(deviceUuid.value, ticket.value.serverUuid, evidenceUuid); if (generation === thumbnailGeneration) thumbnails.value[evidenceUuid] = URL.createObjectURL(blob) }
  catch (error) { message.value = supportMessage(error) } finally { thumbnailBusy.value = false }
}
async function addComment(): Promise<void> {
  if (!deviceUuid.value || !ticket.value || busy.value || !body.value.trim()) return
  busy.value = true; message.value = ''
  try { await supportStore.queueComment(ticket.value.localUuid, deviceUuid.value, body.value); body.value = ''; await load(false); void supportSync.syncNow() }
  catch (error) { message.value = supportMessage(error) } finally { busy.value = false }
}
async function update(): Promise<void> { await supportSync.syncNow(); await load() }
</script>
<style scoped>
.ticket-thumbnail { display: block; width: 100%; max-width: 480px; height: auto; border-radius: 10px; }
</style>
