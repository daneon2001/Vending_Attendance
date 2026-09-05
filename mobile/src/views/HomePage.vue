<template>
  <ion-page>
    <ion-header>
      <ion-toolbar color="primary">
        <ion-title>Vending Attendance</ion-title>
        <ion-buttons slot="end"><ion-button :disabled="syncing" @click="sync">Sincronizar</ion-button></ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content>
      <main class="page-shell">
        <ion-note :color="connectivity === 'ONLINE' ? 'success' : 'warning'">
          {{ connectivity === 'ONLINE' ? 'En línea' : connectivity === 'OFFLINE' ? 'Sin conexión' : 'Red desconocida' }}
        </ion-note>
        <h1>{{ state.summary?.machineCode ?? 'Máquina sin bootstrap' }}</h1>
        <div class="status-grid">
          <div class="status-card"><span>Dispositivo</span><strong>{{ state.summary?.deviceStatus ?? '—' }}</strong></div>
          <div class="status-card"><span>Sincronización</span><strong>{{ state.phase }}</strong></div>
          <div class="status-card"><span>Config</span><strong>v{{ state.summary?.configurationVersion ?? '—' }}</strong></div>
          <div class="status-card"><span>Empleados</span><strong>v{{ state.summary?.employeeManifestVersion ?? '—' }}</strong></div>
          <div class="status-card"><span>Eventos pendientes</span><strong>{{ state.summary?.pendingEvents ?? 0 }}</strong></div>
          <div class="status-card"><span>Último sync</span><strong>{{ formattedLastSync }}</strong></div>
        </div>
        <ion-card v-if="state.clockDriftWarning" color="warning">
          <ion-card-content>El reloj del dispositivo difiere del servidor. Corrige fecha y hora.</ion-card-content>
        </ion-card>
        <ion-text v-if="state.message" :color="state.phase === 'ERROR' ? 'danger' : 'medium'"><p>{{ state.message }}</p></ion-text>
        <ion-searchbar v-model="search" placeholder="Buscar empleado" />
        <ion-list inset>
          <ion-list-header><ion-label>Empleados autorizados</ion-label></ion-list-header>
          <ion-item v-for="employee in filteredEmployees" :key="employee.assignment.uuid" button
            :router-link="`/attendance/${employee.employee_id}/${employee.assignment.uuid}`">
            <ion-label><h2>{{ employee.name }}</h2><p>{{ employee.employee_number }} · {{ employee.assignment.type }}</p></ion-label>
          </ion-item>
          <ion-item v-if="filteredEmployees.length === 0"><ion-label class="muted">No hay empleados efectivos en el snapshot local.</ion-label></ion-item>
        </ion-list>
      </main>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  IonButton, IonButtons, IonCard, IonCardContent, IonContent, IonHeader, IonItem,
  IonLabel, IonList, IonListHeader, IonNote, IonPage, IonSearchbar, IonText, IonTitle, IonToolbar,
} from '@ionic/vue'
import { connectivityService, edgeStore, edgeSyncService } from '@/app/services'
import type { EffectiveEmployee } from '@/storage/EdgeStore'
import type { ConnectivityState } from '@/domain/types'
import type { SyncViewState } from '@/services/EdgeSyncService'

const employees = ref<EffectiveEmployee[]>([])
const search = ref('')
const connectivity = ref<ConnectivityState>(connectivityService.current())
const state = ref<SyncViewState>({ phase: 'IDLE', message: null, summary: null, clockDriftWarning: false })
let unsubscribeSync: (() => void) | undefined
let unsubscribeNetwork: (() => void) | undefined

const syncing = computed(() => state.value.phase === 'SYNCING')
const formattedLastSync = computed(() => state.value.summary?.lastSyncAt
  ? new Date(state.value.summary.lastSyncAt).toLocaleString() : 'Nunca')
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
