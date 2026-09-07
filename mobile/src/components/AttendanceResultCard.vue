<template>
  <ion-card class="attendance-result" :color="color" role="status" aria-live="polite" aria-atomic="true">
    <ion-card-header>
      <ion-card-title>{{ attendanceResultHeading(receipt) }}</ion-card-title>
    </ion-card-header>
    <ion-card-content>
      <p class="result-event">{{ attendanceEventLabel(result.payload.event_type) }} · {{ attendanceLocalTime(result.payload.captured_at, result.payload.device_timezone) }}</p>
      <p v-if="employeeName" class="result-employee">{{ employeeName }}</p>
      <p class="result-message">{{ attendanceSyncMessage(receipt) }}</p>
      <div class="result-location"><p>{{ attendanceLocationLabel(result.evaluation.result) }}</p><p v-if="distance">Distancia: {{ distance }}</p><p v-if="result.evaluation.result === 'OUTSIDE'" class="result-explanation">Tu ubicación está fuera de la zona asignada.</p></div>
    </ion-card-content>
  </ion-card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { IonCard, IonCardContent, IonCardHeader, IonCardTitle } from '@ionic/vue'
import type { AttendanceCaptureResult } from '@/services/AttendanceCaptureService'
import type { AttendanceReceipt } from '@/storage/EdgeStore'
import {
  attendanceDistance, attendanceEventLabel, attendanceLocationLabel,
  attendanceResultColor, attendanceSyncMessage, attendanceResultHeading, attendanceLocalTime,
} from '@/presentation/attendanceResult'

const props = defineProps<{ result: AttendanceCaptureResult; receipt: AttendanceReceipt | null; employeeName?: string }>()
const distance = computed(() => attendanceDistance(props.result.evaluation.distance_m))
const color = computed(() => attendanceResultColor(props.result.evaluation.result, props.receipt))
</script>

<style scoped>
.attendance-result { margin: 20px 0; box-shadow: none; border-radius: 14px; }
.attendance-result ion-card-title { font-size: 1.375rem; line-height: 1.3; }
.attendance-result p { line-height: 1.5; overflow-wrap: anywhere; }
.result-event { font-weight: 700; }
.result-employee { margin-top: 4px; }
.result-message { margin-top: 12px; }
.result-location { margin-top: 16px; border-top: 1px solid currentColor; padding-top: 12px; }
.result-explanation { margin-top: 8px; }
</style>
