<template>
  <ion-page>
    <ion-header><ion-toolbar>
      <ion-buttons slot="start"><ion-back-button default-href="/home" /></ion-buttons>
      <ion-title>Registrar asistencia</ion-title>
    </ion-toolbar></ion-header>
    <ion-content><main class="page-shell">
      <template v-if="employee">
        <h1>{{ employee.name }}</h1><p class="muted">{{ employee.employee_number }} · {{ assignmentLabel(employee.assignment.type) }}</p>
        <ion-button expand="block" size="large" :disabled="busy" @click="capture('CHECK_IN')">Entrada</ion-button>
        <ion-button expand="block" size="large" fill="outline" :disabled="busy" @click="capture('CHECK_OUT')">Salida</ion-button>
      </template>
      <ion-text v-else color="danger"><p>El empleado ya no tiene una asignación efectiva local.</p></ion-text>
      <p v-if="locationMessage" role="status" aria-live="polite">{{ locationMessage }}</p>
      <AttendanceResultCard v-if="result" :result="result" :receipt="receipt" />
      <ion-text v-if="message" color="danger"><p>{{ message }}</p></ion-text>
    </main></ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  IonBackButton, IonButton, IonButtons, IonContent, IonHeader, IonPage, IonText, IonTitle, IonToolbar,
} from '@ionic/vue'
import { attendanceCaptureService, edgeStore, edgeSyncService } from '@/app/services'
import type { EffectiveEmployee } from '@/storage/EdgeStore'
import type { AttendanceCaptureResult } from '@/services/AttendanceCaptureService'
import type { AttendanceEventType } from '@/domain/types'
import { safeErrorMessage } from '@/domain/errors'
import AttendanceResultCard from '@/components/AttendanceResultCard.vue'
import { useAttendanceReceipt } from '@/composables/useAttendanceReceipt'
import { useLocationProgress } from '@/composables/useLocationProgress'
import { assignmentLabel } from '@/presentation/operationLabels'

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
