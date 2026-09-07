<template>
  <ion-page>
    <ion-header><ion-toolbar>
      <ion-buttons slot="start"><ion-back-button default-href="/home" text="Volver" aria-label="Volver a empleados" /></ion-buttons>
      <ion-title>Registrar asistencia</ion-title>
    </ion-toolbar></ion-header>
    <ion-content><main class="page-shell">
      <template v-if="employee">
        <h1>{{ employee.name }}</h1><p class="muted">Número {{ employee.employee_number }}</p>
        <AttendanceResultCard v-if="result" :result="result" :receipt="receipt" :employee-name="employee.name" />
        <p v-if="busy && !locationMessage" class="processing-message" role="status" aria-live="polite">Estamos completando el intento de sincronización. Podrás registrar otra asistencia al terminar.</p>
        <div class="attendance-actions" aria-label="Registrar asistencia" :aria-busy="busy">
          <ion-button expand="block" size="large" :disabled="busy" @click="capture('CHECK_IN')">Registrar entrada</ion-button>
          <ion-button expand="block" size="large" fill="outline" :disabled="busy" @click="capture('CHECK_OUT')">Registrar salida</ion-button>
        </div>
      </template>
      <ion-text v-else color="danger"><p>Este empleado no está disponible en la terminal. Vuelve a la lista o solicita apoyo.</p></ion-text>
      <div v-if="locationMessage" class="location-progress" role="status" aria-live="polite"><ion-spinner name="crescent" aria-hidden="true" /><p>{{ locationMessage }}</p></div>
      <ion-text v-if="message" color="danger"><p role="alert">{{ message }}</p></ion-text>
    </main></ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  IonBackButton, IonButton, IonButtons, IonContent, IonHeader, IonPage, IonSpinner, IonText, IonTitle, IonToolbar,
} from '@ionic/vue'
import { attendanceCaptureService, edgeStore, edgeSyncService } from '@/app/services'
import type { EffectiveEmployee } from '@/storage/EdgeStore'
import type { AttendanceCaptureResult } from '@/services/AttendanceCaptureService'
import type { AttendanceEventType } from '@/domain/types'
import { safeErrorMessage } from '@/domain/errors'
import AttendanceResultCard from '@/components/AttendanceResultCard.vue'
import { useAttendanceReceipt } from '@/composables/useAttendanceReceipt'
import { useLocationProgress } from '@/composables/useLocationProgress'

const route = useRoute()
const employee = ref<EffectiveEmployee | null>(null)
const busy = ref(false)
const result = ref<AttendanceCaptureResult | null>(null)
const message = ref('')
const { message: locationMessage, start: startLocationProgress, stop: stopLocationProgress } = useLocationProgress()
const { receipt, refresh: refreshReceipt } = useAttendanceReceipt(
  () => result.value?.payload.event_uuid ?? null,
  edgeStore,
  edgeSyncService,
)

onMounted(async () => {
  const effective = await edgeStore.getEffectiveEmployees(new Date()).catch(() => [])
  employee.value = effective.find((item) =>
    item.employee_id === route.params.employeeId && item.assignment.uuid === route.params.assignmentUuid,
  ) ?? null
})

async function capture(type: AttendanceEventType): Promise<void> {
  if (!employee.value || busy.value) return
  busy.value = true; result.value = null; message.value = ''
  startLocationProgress()
  try {
    result.value = await attendanceCaptureService.capture(employee.value, type)
    stopLocationProgress()
    await edgeSyncService.syncNow('attendance')
    await refreshReceipt()
  } catch (error) {
    message.value = safeErrorMessage(error)
  } finally {
    stopLocationProgress()
    busy.value = false
  }
}
</script>

<style scoped>
.attendance-actions { display: grid; gap: 12px; margin: 24px 0; }
.processing-message { margin-top: 16px; line-height: 1.5; }
.attendance-actions ion-button { min-height: 56px; margin: 0; font-size: 1.125rem; letter-spacing: 0; }
.location-progress { display: flex; align-items: flex-start; gap: 12px; padding: 16px 0; }
.location-progress ion-spinner { flex-shrink: 0; margin-top: 2px; }
.location-progress p { margin: 0; line-height: 1.5; }
</style>
