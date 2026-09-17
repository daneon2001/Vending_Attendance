<template>
  <ion-page>
    <ion-header><ion-toolbar><ion-buttons slot="start"><ion-back-button :default-href="uuid ? '/my-activities' : '/home'" text="Volver" /></ion-buttons><ion-title>Mis actividades</ion-title></ion-toolbar></ion-header>
    <ion-content><main class="page-shell activities-page">
      <BrandIdentity />
      <h1>{{ uuid ? 'Detalle de actividad' : 'Mis actividades' }}</h1>
      <p class="muted">Trabajo de campo con tu identidad personal. No registra asistencia.</p>
      <p v-if="unavailable || flow?.error" class="notice" role="alert">{{ unavailable || flow?.error }}</p>
      <p v-if="flow?.busy" role="status">Consultando al servidor o comprobando ubicación…</p>
      <template v-if="flow">
        <p v-if="offline" class="notice" role="status">Sin conexión. Se muestra la última información descargada; los cambios nuevos quedan pendientes.</p>
        <p role="status">Pendientes de trabajo de campo: {{ pendingCount }}</p>
        <p v-if="localMessage" class="notice" role="status">{{ localMessage }}</p>
        <ion-button fill="outline" :disabled="flow.busy || editing" @click="refresh">Actualizar estado</ion-button>
        <ion-button v-if="pendingCount" fill="outline" :disabled="editing" @click="act(() => flow?.offlineSupport?.sync())">Sincronizar pendientes</ion-button>
        <ion-button v-if="flow.error" fill="clear" router-link="/my-device">Revisar mi sesión y dispositivo</ion-button>
        <template v-if="!uuid">
          <p v-if="!flow.busy && !flow.error && !flow.rows.length">{{ emptyActivitiesMessage(offline) }}</p>
          <details v-if="!offline && flow.notifications">
            <summary>Avisos de actividades ({{ flow.notifications.unread_count }} sin leer)</summary>
            <p class="muted">Avisos recientes de tu identidad personal. Usa Actualizar estado para consultar novedades.</p>
            <p v-if="!flow.notifications.notifications.length">No hay avisos de actividades disponibles.</p>
            <ion-list aria-label="Avisos de actividades"><ion-item v-for="notice in flow.notifications.notifications" :key="notice.id">
              <ion-label class="ion-text-wrap"><h2>{{ activityNotificationLabel(notice.kind) }}</h2><p>{{ notice.folio }} · {{ date(notice.created_at) }} · {{ notice.read_at ? 'Leído' : 'Sin leer' }}</p>
                <ion-button fill="clear" :router-link="'/my-activities/' + notice.activity_uuid">Ver actividad</ion-button>
                <ion-button v-if="!notice.read_at" fill="clear" :disabled="flow.busy" @click="flow.markNotificationRead(notice)">Marcar como leído</ion-button>
              </ion-label>
            </ion-item></ion-list>
          </details>
          <ion-list><ion-item v-for="row in flow.rows" :key="row.uuid" button :router-link="'/my-activities/' + row.uuid">
            <img v-if="row.machine === 'VM-DEMO-001'" src="/brand/dispenser-thumb.webp" alt="" width="56" height="60" class="machine-thumbnail" />
            <ion-label class="ion-text-wrap"><h2>{{ row.title }}</h2><p>{{ row.machine }} · {{ row.type_label }}</p><p>{{ activityStatus(row.status) }}</p></ion-label>
          </ion-item></ion-list>
          <nav v-if="flow.page > 1 || flow.hasMore" aria-label="Páginas de actividades">
            <ion-button fill="clear" :disabled="flow.busy || flow.page === 1" @click="flow.open(undefined, flow.page - 1)">Anterior</ion-button>
            <span>Página {{ flow.page }}</span>
            <ion-button fill="clear" :disabled="flow.busy || !flow.hasMore" @click="flow.open(undefined, flow.page + 1)">Siguiente</ion-button>
          </nav>
        </template>
        <section v-else-if="flow.activity" aria-label="Actividad asignada">
          <div v-if="flow.activity.machine === 'VM-DEMO-001'" class="machine-context"><img src="/brand/dispenser-thumb.webp" alt="Dispensadora Medical Life" width="56" height="60" class="machine-thumbnail" /><div><strong>{{ flow.activity.machine }}</strong><p class="muted">Dispensadora de medicamentos</p></div></div>
          <h2>{{ flow.activity.title }}</h2>
          <p>{{ flow.activity.description }}</p>
          <dl><div><dt>Máquina</dt><dd>{{ flow.activity.machine }}</dd></div><div><dt>Empleado</dt><dd>{{ flow.activity.employee }}</dd></div><div><dt>Tipo</dt><dd>{{ flow.activity.type_label }}</dd></div></dl>
          <p class="state" role="status">{{ activityStatus(flow.activity.status) }}</p>
          <p v-if="flow.edgeResult || flow.activity.geofence_result" role="status">{{ zoneLabel(flow.edgeResult || flow.activity.geofence_result || null) }}</p>
          <p v-if="flow.activity.started_at">Inicio confirmado: {{ date(flow.activity.started_at) }}</p>
          <p v-if="flow.activity.completed_at">Finalización confirmada: {{ date(flow.activity.completed_at) }}</p>
          <p v-if="flow.pending" class="notice">Hay una solicitud pendiente de confirmar. Consulta el estado o reintenta la misma solicitud; no se enviará automáticamente.</p>
          <p v-if="flow.mismatch" role="alert" class="notice">La zona del servidor no coincide. Detén la prueba y solicita revisión.</p>
          <ion-button v-if="flow.pending && flow.pending.operation.activity_uuid === flow.activity.uuid && !flow.mismatch" expand="block" :disabled="flow.busy" @click="flow.retry()">Reintentar confirmación</ion-button>
          <ion-button v-else-if="flow.pending" fill="outline" :router-link="'/my-activities/' + flow.pending.operation.activity_uuid">Revisar solicitud pendiente</ion-button>
          <template v-if="flow.activity.status === 'ASSIGNED' && !flow.pending">
            <p>Para iniciar obtendremos una ubicación nueva y comprobaremos que estás dentro de la zona de la máquina.</p>
            <ion-button expand="block" :disabled="flow.busy || flow.mismatch" @click="flow.start()">Comprobar ubicación e iniciar</ion-button>
          </template>
          <template v-if="flow.activity.status === 'IN_PROGRESS'">
            <section v-if="flow.activity.contribution_policy?.available" aria-label="Notas y fotografías">
              <h2>Notas de campo</h2>
              <form @submit.prevent="saveNote">
                <label :for="'field-note-' + uuid">Agregar nota</label>
                <textarea :id="'field-note-' + uuid" v-model="note" rows="4" :maxlength="flow.activity.contribution_policy.max_note_length" :disabled="editing || completionPending" required />
                <p class="muted">Texto sin formato. {{ note.length }} / {{ flow.activity.contribution_policy.max_note_length }}</p>
                <ion-button type="submit" expand="block" :disabled="editing || completionPending || !note.trim()">Guardar nota</ion-button>
              </form>
              <h2>Fotografías</h2>
              <p>Captura sólo la evidencia necesaria. No incluyas personas, documentos ni pantallas con información sensible en esta demostración.</p>
              <ion-button expand="block" fill="outline" :disabled="editing || completionPending || captures.some(value => value.preview)" @click="act(() => flow?.offlineSupport?.capture(flow.activity!))">Tomar fotografía</ion-button>
            </section>
            <p>La ubicación se comprobó al iniciar. Finalizar no obtiene ni acredita una nueva ubicación.</p>
            <ion-button expand="block" :disabled="flow.busy || editing || !!flow.pending || flow.mismatch || completionPending || captures.some(value => value.preview)" @click="complete">Finalizar actividad</ion-button>
          </template>
          <section aria-label="Notas guardadas">
            <h2>Notas guardadas</h2>
            <p v-if="!flow.activity.notes?.length && !pendingNotes.length">Sin notas guardadas.</p>
            <article v-for="item in flow.activity.notes" :key="item.uuid" class="contribution"><p class="note-body">{{ item.body }}</p><p>{{ item.author }} · {{ date(item.captured_at) }}</p><p>Sincronizada</p></article>
            <article v-for="item in pendingNotes" :key="item.uuid" class="contribution"><p class="note-body">{{ operation(item).body }}</p><p>Guardada en el dispositivo · {{ date(operation(item).captured_at!) }}</p><p>{{ delivery(item.status) }}</p></article>
          </section>
          <section aria-label="Fotografías guardadas">
            <h2>Fotografías guardadas</h2>
            <p v-if="!captures.length">Sin fotografías guardadas en este dispositivo.</p>
            <article v-for="item in captures" :key="item.evidence.localUuid" class="contribution">
              <img v-if="previews[item.evidence.localUuid]" :src="previews[item.evidence.localUuid]" alt="Vista previa de la evidencia" class="evidence-preview" />
              <p>{{ date(item.evidence.capturedAt) }}</p>
              <template v-if="item.preview">
                <p>Vista previa. La fotografía aún no está en la cola de envío.</p>
                <ion-button v-if="item.evidence.state === 'READY'" :disabled="editing" @click="act(() => flow?.offlineSupport?.confirmPhoto(item))">Guardar fotografía</ion-button>
                <ion-button fill="clear" :disabled="editing" @click="act(() => flow?.offlineSupport?.discardPhoto(item))">Descartar</ion-button>
              </template>
              <p v-else>{{ item.confirmed ? 'Sincronizada' : delivery(pendingItems.find(op => op.uuid === item.evidence.localUuid)?.status ?? 'PENDING') }}</p>
            </article>
          </section>
          <p v-if="flow.activity.status === 'COMPLETED'">Actividad finalizada y confirmada por el servidor.</p>
        </section>
      </template>
    </main></ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, reactive, ref, onUnmounted } from 'vue'
import BrandIdentity from '@/components/BrandIdentity.vue'
import { useRoute } from 'vue-router'
import { IonPage, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonContent, IonButton, IonList, IonItem, IonLabel, onIonViewWillEnter, onIonViewDidLeave } from '@ionic/vue'
import { fieldActivityFlow } from './services'
import { activityStatus, zoneLabel, emptyActivitiesMessage, activityNotificationLabel, type FieldActivityFlow, type Operation } from './FieldActivityFlow'
import { SupportError, type SupportOperation } from '@/support/types'
import type { FieldCapture } from './FieldOfflineStore'
const route = useRoute()
const uuid = computed(() => typeof route.params.uuid === 'string' ? route.params.uuid : undefined)
const unavailable = ref('')
let flow: ReturnType<typeof reactive<FieldActivityFlow>> | null = null
try { flow = reactive(fieldActivityFlow()) } catch { unavailable.value = 'Las actividades requieren una conexión segura y un dispositivo autorizado.' }
const date = (value: string) => new Date(value).toLocaleString('es-MX', { timeZone: 'America/Mexico_City' })
const note = ref(''), localMessage = ref(''), editing = ref(false), offline = ref(false), pendingCount = ref(0)
const captures = ref<FieldCapture[]>([]), pendingItems = ref<SupportOperation[]>([]), previews = ref<Record<string, string>>({})
const operation = (item: SupportOperation) => item.payload.operation as Operation
const pendingNotes = computed(() => pendingItems.value.filter(item => operation(item).action === 'note'))
const completionPending = computed(() => pendingItems.value.some(item => operation(item).action === 'complete'))
const delivery = (status: string) => ['REJECTED', 'BLOCKED'].includes(status) ? 'No se pudo confirmar. Conservada en el dispositivo; requiere revisión.' : 'Guardada en el dispositivo · Pendiente de sincronizar'
let active = false, unsubscribe: (() => void) | undefined
async function refreshLocal() {
  if (!active || !flow?.offlineSupport?.scope) return
  try {
    const service = flow.offlineSupport
    offline.value = service.offline; localMessage.value = service.message
    const all = await service.pending(); pendingCount.value = await service.pendingCount()
    pendingItems.value = all.filter(item => operation(item).activity_uuid === uuid.value)
    if (uuid.value) {
      const cached = (await service.cached(uuid.value))[0]
      if (cached) flow.activity = cached
      captures.value = await service.captures(uuid.value)
      const images: Record<string, string> = {}
      for (const item of captures.value) if (item.evidence.path) {
        try { images[item.evidence.localUuid] = await service.files.preview(item.evidence) } catch { /* Metadata still shows pending/error. */ }
      }
      previews.value = images
    }
  } catch { captures.value = []; pendingItems.value = []; previews.value = {}; if (flow) flow.activity = null }
}
async function refresh() { await flow?.open(uuid.value); await refreshLocal() }
async function act(work: () => Promise<unknown> | undefined) {
  if (editing.value) return
  editing.value = true; localMessage.value = ''
  try { await work(); await refreshLocal() }
  catch (error) { localMessage.value = error instanceof SupportError ? error.message : 'No fue posible guardar. Conserva esta pantalla y revisa tu sesión.' }
  finally { editing.value = false }
}
async function saveNote() {
  await act(async () => { if (!flow?.activity) return; await flow.offlineSupport?.note(flow.activity, note.value); note.value = '' })
}
async function complete() {
  if (!window.confirm('¿Finalizar la actividad? Si no hay conexión, quedará pendiente hasta la confirmación del servidor.')) return
  await act(async () => { await flow?.complete() })
}
onIonViewWillEnter(() => { active = true; unsubscribe?.(); unsubscribe = flow?.offlineSupport?.subscribe(() => { void refreshLocal() }); void refresh() })
onIonViewDidLeave(() => { active = false; unsubscribe?.(); unsubscribe = undefined })
onUnmounted(() => { active = false; unsubscribe?.() })
</script>

<style scoped>
.activities-page { max-width: 620px; overflow-wrap: anywhere; }
.notice { padding: 16px; border-left: 4px solid var(--ion-color-warning); background: var(--ion-color-light); color: var(--ion-color-dark); }
.state { font-weight: 700; }
textarea { display: block; width: 100%; border: 1px solid var(--ion-color-medium); border-radius: 8px; padding: 12px; margin-top: 8px; background: var(--ion-background-color); color: var(--ion-text-color); }
.contribution { margin: 16px 0; padding: 16px; border: 1px solid var(--ion-color-medium); border-radius: 12px; }
.note-body { white-space: pre-wrap; }
.evidence-preview { display: block; width: 100%; max-height: 260px; object-fit: contain; }
dl > div { margin: 16px 0; }
dt { color: var(--ion-color-medium); }
dd { margin: 4px 0 0; }
nav { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; }
ion-item { --min-height: 88px; }
ion-label h2 { font-size: 1.1rem; line-height: 1.4; }
</style>
