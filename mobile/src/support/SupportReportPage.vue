<template>
  <SupportLayout title="Reportar incidencia">
    <h1>¿Qué problema tiene la máquina?</h1>
    <p class="muted">Describe el problema y, si puedes, agrega una fotografía.</p>
    <p v-if="!context" role="status">Preparando las categorías de soporte. Si es la primera vez, conecta el equipo.</p>
    <form v-else class="support-stack" :aria-busy="busy" @submit.prevent="send">
      <label class="support-field" for="support-category">Categoría
        <select id="support-category" v-model="category" required :disabled="busy"><option disabled value="">Selecciona una categoría</option><option v-for="item in context.categories" :key="item.value" :value="item.value">{{ item.label }}</option></select>
      </label>
      <label class="support-field" for="support-title">Problema
        <input id="support-title" v-model="title" required maxlength="160" :disabled="busy" placeholder="Ej. La pantalla no responde" />
      </label>
      <label class="support-field" for="support-description">Descripción
        <textarea id="support-description" v-model="description" required rows="5" maxlength="10000" :disabled="busy" placeholder="Explica qué ocurrió y desde cuándo." />
      </label>
      <section aria-labelledby="support-evidence-title">
        <h2 id="support-evidence-title">Fotografías</h2>
        <p class="support-caption">Hasta {{ context.evidence_policy.max_count }} fotografías. Evita fotografiar información personal.</p>
        <div v-if="photos.length" class="support-photos">
          <figure v-for="(photo, index) in photos" :key="photo.localUuid">
            <img v-if="previews[photo.localUuid]" :src="previews[photo.localUuid]" :alt="`Fotografía ${index + 1} del problema`" />
            <figcaption>Foto {{ index + 1 }} · {{ photo.state === 'CAPTURING' ? 'Pendiente de recuperar' : 'Guardada en el equipo' }}</figcaption>
          </figure>
        </div>
        <ion-button type="button" fill="outline" :disabled="busy || photos.length >= context.evidence_policy.max_count" @click="takePhoto">Tomar foto</ion-button>
        <template v-if="pendingPhoto">
          <ion-button type="button" fill="clear" :disabled="busy" @click="recoverPhoto">Recuperar fotografía</ion-button>
          <ion-button type="button" fill="clear" :disabled="busy" @click="cancelPhoto">Cancelar captura pendiente</ion-button>
        </template>
      </section>
      <section aria-labelledby="support-location-title">
        <h2 id="support-location-title">Ubicación</h2>
        <p role="status" aria-live="polite">{{ locating ? 'Obteniendo una ubicación nueva…' : location ? 'Ubicación obtenida' : 'Se intentará obtener al enviar. Puedes continuar sin ubicación.' }}</p>
        <ion-button v-if="locating" type="button" fill="outline" @click="skipGps">Enviar sin ubicación</ion-button>
      </section>
      <p v-if="message" class="support-error" role="alert">{{ message }}</p>
      <p v-if="draft" class="support-caption" role="status">Borrador guardado en este equipo.</p>
      <div class="support-actions">
        <ion-button type="submit" expand="block" :disabled="busy || pendingPhoto">{{ busy ? 'Guardando reporte…' : 'Enviar reporte' }}</ion-button>
        <ion-button type="button" fill="clear" :disabled="busy" @click="save">Guardar borrador</ion-button>
      </div>
    </form>
  </SupportLayout>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { IonButton } from '@ionic/vue'
import { useRoute, useRouter } from 'vue-router'
import type { LocationEvidence } from '@/domain/types'
import SupportLayout from './SupportLayout.vue'
import { useSupport } from './useSupport'
import { supportCapture, supportFiles, supportStore, supportSync } from './services'
import { supportMessage } from './presentation'
import type { LocalEvidence, LocalTicket, TicketInput } from './types'

const route = useRoute(); const router = useRouter()
const { context, deviceUuid } = useSupport()
const category = ref(''); const title = ref(''); const description = ref(''); const message = ref('')
const draft = ref<LocalTicket | null>(null); const photos = ref<LocalEvidence[]>([]); const previews = ref<Record<string, string>>({})
const busy = ref(false); const locating = ref(false); const location = ref<LocationEvidence | null>(null)
const pendingPhoto = computed(() => photos.value.some(photo => photo.state === 'CAPTURING'))
let skipLocation: (() => void) | null = null
function skipGps(): void { skipLocation?.() }
watch([deviceUuid, () => context.value?.machine.uuid, () => route.query.draft], async () => {
  if (draft.value && (draft.value.deviceUuid !== deviceUuid.value || draft.value.machineUuid !== context.value?.machine.uuid)) { draft.value = null; category.value = ''; title.value = ''; description.value = ''; photos.value = []; previews.value = {} }
  if (!deviceUuid.value || !context.value) return
  if (typeof route.query.draft !== 'string') { draft.value = null; category.value = ''; title.value = ''; description.value = ''; photos.value = []; previews.value = {}; return }
  if (draft.value?.localUuid === route.query.draft) return
  const owner = deviceUuid.value; const machine = context.value.machine.uuid; const uuid = route.query.draft
  const saved = await supportStore.getTicket(uuid, owner).catch(() => null)
  if (deviceUuid.value !== owner || context.value?.machine.uuid !== machine || route.query.draft !== uuid) return
  if (!saved || saved.machineUuid !== context.value?.machine.uuid) { message.value = 'No se encontró un borrador autorizado para esta máquina.'; return }
  if (saved.status !== 'DRAFT') { await router.replace(`/support/tickets/${saved.localUuid}`); return }
  draft.value = saved; category.value = saved.payload.category; title.value = saved.payload.title; description.value = saved.payload.description
  await loadPhotos()
}, { immediate: true })

async function loadPhotos(): Promise<void> {
  if (!draft.value || !deviceUuid.value) return
  photos.value = await supportStore.evidenceForTicket(draft.value.localUuid, deviceUuid.value)
  const next: Record<string, string> = {}
  for (const photo of photos.value) if (photo.path && !photo.purgedAt) next[photo.localUuid] = await supportFiles.preview(photo).catch(() => '')
  previews.value = next
}
async function persist(overrides: Partial<TicketInput> = {}): Promise<LocalTicket> {
  if (!deviceUuid.value || !context.value) throw { code: 'CONTEXT_UNAVAILABLE' }
  const payload: TicketInput = { category: category.value, title: title.value, description: description.value,
    reported_at: draft.value?.payload.reported_at ?? new Date().toISOString(), ...overrides }
  if (draft.value) await supportStore.updateDraft(draft.value.localUuid, deviceUuid.value, payload)
  else draft.value = await supportStore.createDraft(deviceUuid.value, payload)
  await router.replace({ path: '/support/report', query: { draft: draft.value.localUuid } })
  return draft.value
}
async function save(): Promise<void> {
  if (busy.value) return
  busy.value = true; message.value = ''
  try { await persist() } catch (error) { message.value = supportMessage(error) } finally { busy.value = false }
}
async function takePhoto(): Promise<void> {
  if (busy.value) return
  busy.value = true; message.value = ''
  try { const saved = await persist(); await supportCapture.capture(saved.localUuid) }
  catch (error) { message.value = supportMessage(error) }
  finally { await loadPhotos().catch(() => undefined); busy.value = false }
}
async function recoverPhoto(): Promise<void> {
  busy.value = true; message.value = ''
  try { await supportCapture.recoverPending(); await loadPhotos() }
  catch (error) { message.value = supportMessage(error) } finally { busy.value = false }
}
async function cancelPhoto(): Promise<void> {
  if (!window.confirm('¿Cancelar la captura pendiente? El reporte seguirá guardado.')) return
  busy.value = true
  try { await supportCapture.cancelPending(); await loadPhotos() } catch (error) { message.value = supportMessage(error) } finally { busy.value = false }
}
async function send(): Promise<void> {
  if (busy.value || !deviceUuid.value) return
  busy.value = true; message.value = ''
  try {
    await persist()
    locating.value = true
    const skip = new Promise<null>(resolve => { skipLocation = () => resolve(null) })
    location.value = await Promise.race([supportCapture.captureLocation(), skip])
    locating.value = false; skipLocation = null
    const saved = await persist({ reported_at: new Date().toISOString(), location: location.value })
    await supportStore.submit(saved.localUuid, deviceUuid.value)
    void supportSync.syncNow()
    await router.replace(`/support/tickets/${saved.localUuid}`)
  } catch (error) { message.value = supportMessage(error) }
  finally { busy.value = false; locating.value = false; skipLocation = null }
}
</script>
