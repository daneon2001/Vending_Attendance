<template>
  <section class="terminal-status" aria-label="Estado de la terminal" aria-live="polite">
    <div class="terminal-status__line">
      <strong>{{ networkLabel }}</strong>
      <ion-button fill="clear" size="small" :disabled="state.phase === 'SYNCING'" @click="$emit('sync')">
        {{ state.phase === 'SYNCING' ? 'Sincronizando…' : 'Sincronizar' }}
      </ion-button>
    </div>
    <p v-if="connectivity === 'OFFLINE'">Los registros se guardan en este dispositivo hasta recuperar conexión.</p>
    <p>{{ pendingLabel }}</p>
    <p v-if="state.phase === 'ERROR'" class="terminal-status__error" role="alert">No se pudo completar la sincronización. Revisa la conexión e intenta de nuevo. Si continúa, solicita apoyo.</p>
    <p v-if="state.summary && state.summary.deviceStatus !== 'ACTIVE'" class="terminal-status__error" role="alert">Dispositivo {{ deviceStatusLabel(state.summary.deviceStatus).toLocaleLowerCase() }}. Solicita apoyo al responsable.</p>
    <p v-if="state.clockDriftWarning" class="terminal-status__warning" role="alert">Revisa la fecha y hora del dispositivo antes de registrar.</p>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { IonButton } from '@ionic/vue'
import type { ConnectivityState } from '@/domain/types'
import type { SyncViewState } from '@/services/EdgeSyncService'
import { deviceStatusLabel } from '@/presentation/operationLabels'

const props = defineProps<{ connectivity: ConnectivityState; state: SyncViewState }>()
defineEmits<{ sync: [] }>()
const networkLabel = computed(() => props.connectivity === 'ONLINE' ? 'En línea'
  : props.connectivity === 'OFFLINE' ? 'Sin conexión' : 'Comprobando conexión')
// Network availability is not a server receipt. Never infer synchronization from it.
const pendingLabel = computed(() => {
  const count = props.state.summary?.pendingEvents
  if (count == null) return 'Consultando registros pendientes…'
  return count === 1 ? '1 asistencia pendiente de sincronizar' : `${count} asistencias pendientes de sincronizar`
})
</script>

<style scoped>
.terminal-status { border-bottom: 1px solid var(--ion-color-light-shade); padding-bottom: 16px; }
.terminal-status__line { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.terminal-status__line strong { font-size: 1rem; }
.terminal-status p { margin: 4px 0; font-size: .875rem; line-height: 1.5; }
.terminal-status__error { color: var(--ion-color-danger); }
.terminal-status__warning { color: #785000; }
</style>
