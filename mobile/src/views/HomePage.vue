<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>Asistencia MDM</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content>
      <main class="page-shell">
        <BrandIdentity />
        <div class="home-intro"><p class="eyebrow">PERSONAS · MÁQUINAS · RESULTADOS</p><h1>Tu operación, a la mano</h1><p class="muted">Accede a las herramientas de tu jornada.</p></div>
        <nav aria-label="Acciones disponibles" class="home-actions">
          <ion-button v-if="terminalActive && employees.length" expand="block" :aria-expanded="showAttendance" aria-controls="attendance-employees" @click="toggleAttendance">Registrar asistencia</ion-button>
          <ion-button v-if="canUseActivities" expand="block" fill="outline" router-link="/my-activities">Mis actividades</ion-button>
          <ion-button v-if="terminalActive" expand="block" fill="outline" router-link="/support/report">Reportar incidencia</ion-button>
          <ion-button expand="block" fill="outline" router-link="/my-device">Mi dispositivo</ion-button>
          <ion-button v-if="terminalActive" expand="block" fill="clear" router-link="/support" class="support-entry">Soporte</ion-button>
        </nav>
        <aside class="dispenser-banner brand-wave" aria-label="Dispensadora de medicamentos Medical Life">
          <div><p class="eyebrow">Medical Life</p><h2>Tecnología<br />en Movimiento</h2><p>Soporte en campo</p></div>
          <img src="/brand/dispenser-banner.webp" alt="Panel y compartimentos de la dispensadora de medicamentos Medical Life" width="720" height="400" />
        </aside>
        <p v-if="!hasTerminal" class="muted">Para trabajar con tu identidad personal, entra en Mi dispositivo. No necesitas activar una terminal de máquina.</p>
        <p v-else-if="terminalActive && !employees.length" class="muted">No hay empleados disponibles para asistencia. Sincroniza o solicita apoyo al responsable.</p>
        <section v-if="terminalActive && showAttendance" id="attendance-employees" aria-labelledby="attendance-heading">
        <h2 id="attendance-heading" ref="attendanceHeading" tabindex="-1">Selecciona tu nombre</h2>
        <p class="muted">Después elige registrar entrada o salida.</p>
        <div class="employee-search">
          <input v-model="search" type="search" placeholder="Nombre o número" aria-label="Buscar empleado por nombre o número" autocomplete="off" />
          <button v-if="search" type="button" aria-label="Limpiar búsqueda de empleados" @click="search = ''">Limpiar</button>
        </div>
        <ion-list class="employee-list" aria-label="Empleados asignados">
          <ion-item v-for="employee in filteredEmployees" :key="employee.assignment.uuid" button
            :router-link="`/attendance/${employee.employee_id}/${employee.assignment.uuid}`">
            <ion-label class="ion-text-wrap"><h2>{{ employee.name }}</h2><p>Número {{ employee.employee_number }}</p></ion-label>
          </ion-item>
          <ion-item v-if="filteredEmployees.length === 0"><ion-label class="ion-text-wrap muted">{{ search.trim() ? 'No encontramos ese nombre o número. Revisa la búsqueda.' : 'No hay empleados disponibles. Sincroniza o solicita apoyo al responsable.' }}</ion-label></ion-item>
        </ion-list>
        </section>
        <TerminalStatus v-if="hasTerminal" :connectivity="connectivity" :state="state" @sync="sync" />
        <p v-else class="muted" role="status">{{ connectivity === 'ONLINE' ? 'Red disponible' : connectivity === 'OFFLINE' ? 'Sin conexión' : 'Consultando conexión' }}</p>
        <ion-button expand="block" fill="clear" router-link="/diagnostics">Diagnóstico e información</ion-button>
        <details v-if="!hasTerminal" class="terminal-details"><summary>Configuración de terminal</summary><p class="muted">Sólo para el responsable de una máquina. No registra tu identidad personal.</p><ion-button expand="block" fill="outline" router-link="/provision">Configurar terminal de máquina</ion-button></details>
        <details v-if="hasTerminal" class="terminal-details">
          <summary>Información de la terminal</summary>
          <dl>
            <div><dt>Máquina</dt><dd>{{ state.summary?.machineCode ?? 'Sin configurar' }}</dd></div>
            <div><dt>Dispositivo</dt><dd>{{ deviceStatusLabel(state.summary?.deviceStatus) }}</dd></div>
            <div><dt>Sincronización</dt><dd>{{ syncPhaseLabel(state.phase) }}</dd></div>
            <div><dt>Versión de configuración</dt><dd>{{ state.summary?.configurationVersion ?? 'Sin información' }}</dd></div>
            <div><dt>Versión de empleados</dt><dd>{{ state.summary?.employeeManifestVersion ?? 'Sin información' }}</dd></div>
            <div><dt>Última confirmación de configuración</dt><dd>{{ formattedLastSync }}</dd></div>
          </dl>
          <TerminalGeofence :configuration-version="state.summary?.configurationVersion ?? null" />
        </details>
      </main>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  IonButton, IonContent, IonHeader, IonItem, IonLabel, IonList, IonPage, IonTitle, IonToolbar,
} from '@ionic/vue'
import { connectivityService, credentialStore, edgeStore, edgeSyncService } from '@/app/services'
import type { EffectiveEmployee } from '@/storage/EdgeStore'
import type { ConnectivityState } from '@/domain/types'
import type { SyncViewState } from '@/services/EdgeSyncService'
import { deviceStatusLabel, syncPhaseLabel } from '@/presentation/operationLabels'
import TerminalStatus from '@/components/TerminalStatus.vue'
import TerminalGeofence from '@/components/TerminalGeofence.vue'
import BrandIdentity from '@/components/BrandIdentity.vue'
import { onIonViewWillEnter } from '@ionic/vue'
import { fieldActivitiesAvailable } from '@/fieldSupport/services'

const canUseActivities = ref(false)
const hasTerminal = ref(false)
const showAttendance = ref(false)
const attendanceHeading = ref<HTMLElement | null>(null)
async function toggleAttendance(): Promise<void> {
  showAttendance.value = !showAttendance.value
  if (!showAttendance.value) return
  await nextTick()
  const heading = attendanceHeading.value
  if (!showAttendance.value || !heading) return
  heading.focus({ preventScroll: true })
  const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  heading.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start', inline: 'nearest' })
}
const terminalActive = computed(() => hasTerminal.value && state.value.summary?.deviceStatus === 'ACTIVE')
onIonViewWillEnter(async () => {
  canUseActivities.value = false
  hasTerminal.value = !!await credentialStore.get().catch(() => null)
  await loadEmployees()
  canUseActivities.value = await fieldActivitiesAvailable()
})

const employees = ref<EffectiveEmployee[]>([])
const search = ref('')
const connectivity = ref<ConnectivityState>(connectivityService.current())
const state = ref<SyncViewState>({ phase: 'IDLE', message: null, summary: null, clockDriftWarning: false })
let unsubscribeSync: (() => void) | undefined
let unsubscribeNetwork: (() => void) | undefined

const formattedLastSync = computed(() => state.value.summary?.lastSyncAt
  ? new Date(state.value.summary.lastSyncAt).toLocaleString('es-MX') : 'Sin confirmación')
const filteredEmployees = computed(() => {
  const needle = search.value.trim().toLocaleLowerCase()
  if (!needle) return employees.value
  return employees.value.filter((employee) =>
    `${employee.name} ${employee.employee_number}`.toLocaleLowerCase().includes(needle),
  )
})

async function loadEmployees(): Promise<void> {
  employees.value = await edgeStore.getEffectiveEmployees(new Date()).catch(() => [])
}

async function sync(): Promise<void> {
  await edgeSyncService.syncNow('manual')
  await loadEmployees()
}

onMounted(async () => {
  hasTerminal.value = !!await credentialStore.get().catch(() => null)
  unsubscribeSync = edgeSyncService.subscribe((next) => {
    const previousEmployeeVersion = state.value.summary?.employeeManifestVersion ?? null
    state.value = next
    const nextEmployeeVersion = next.summary?.employeeManifestVersion ?? null
    if (next.phase === 'IDLE' && nextEmployeeVersion !== previousEmployeeVersion) {
      void loadEmployees()
    }
  })
  unsubscribeNetwork = connectivityService.subscribe((next) => { connectivity.value = next })
  await loadEmployees()
})
onBeforeUnmount(() => { unsubscribeSync?.(); unsubscribeNetwork?.() })
</script>

<style scoped>
/* Ionic searchbar ships English inner-input/clear labels. A native, explicitly
   labelled search field keeps the same v-model/filter without changing Ionic's internals. */
.employee-search { display: flex; align-items: center; gap: 8px; margin: 24px 0 8px; }
.employee-search input { min-width: 0; width: 100%; min-height: 48px; padding: 12px; border: 1px solid var(--ion-color-light-shade); border-radius: 10px; background: var(--ion-color-light); color: var(--ion-text-color); font: inherit; }
.employee-search input::placeholder { color: var(--ion-color-medium); opacity: 1; }
.employee-search input::-webkit-search-cancel-button { display: none; }
.employee-search button { flex-shrink: 0; white-space: nowrap; min-height: 48px; padding: 8px; background: transparent; color: var(--ion-color-primary); font: inherit; }
.employee-search :focus-visible { outline: 3px solid var(--ion-color-primary); outline-offset: 2px; }
.employee-list { background: transparent; padding: 0; margin: 16px 0; }
.employee-list ion-item { --min-height: 80px; --padding-start: 0; --inner-padding-end: 4px; }
.employee-list h2 { font-size: 1.125rem; font-weight: 650; line-height: 1.4; }
.employee-list p { color: var(--ion-color-medium); margin-top: 4px; }
#attendance-heading { scroll-margin-top: 16px; }
#attendance-heading:focus-visible { outline: 3px solid var(--ion-color-primary); outline-offset: 4px; }
.terminal-details { margin-top: 24px; font-size: .875rem; }
.terminal-details summary { min-height: 48px; padding: 14px 0; cursor: pointer; color: var(--ion-color-medium); }
.terminal-details dl { margin: 0; }
.terminal-details dl > div { padding: 8px 0; }
.terminal-details dt { color: var(--ion-color-medium); }
.terminal-details dd { margin: 4px 0 0; overflow-wrap: anywhere; }
.product-heading { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
.product-heading img { flex-shrink: 0; object-fit: contain; }
.product-heading h1 { font-size: 1.5rem; margin: 0; overflow-wrap: anywhere; }
.product-heading p { margin: 4px 0 0; }
.home-actions { display: grid; gap: 8px; margin-bottom: 20px; }
.home-intro { margin-bottom: 24px; }
.home-intro h1 { margin-top: 8px; }
.eyebrow { font-size: .68rem; font-weight: 800; letter-spacing: .08em; color: #287947; }
.home-actions { grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; }
.home-actions ion-button { min-height: 84px; margin: 0; --background: #fff; --border-color: #d1e2f3; --border-width: 1px; --border-style: solid; --color: #164b84; --border-radius: 16px; }
.home-actions ion-button:first-child { --background: #0058d4; --color: #fff; }
.home-actions .support-entry { grid-column: 1 / -1; min-height: 48px; }
.dispenser-banner { display: grid; grid-template-columns: 1fr 1fr; min-height: 170px; background: #fff; border: 1px solid #dce7f1; margin: 24px 0; }
.dispenser-banner > div { align-self: center; padding: 20px 0 28px 20px; z-index: 1; }
.dispenser-banner h2 { font-size: 1.15rem; margin: 6px 0; line-height: 1.3; }
.dispenser-banner p { font-size: .75rem; }
.dispenser-banner img { contain: size; width: 100%; height: 100%; object-fit: cover; object-position: 48% center; }
@media(max-width: 340px) { .home-actions { grid-template-columns: 1fr; } .dispenser-banner h2 { font-size: 1rem; } }
</style>
