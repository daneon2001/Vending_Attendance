<template>
  <ion-card :color="color" role="status" aria-live="polite" aria-atomic="true">
    <ion-card-header>
      <ion-card-title>{{ attendanceEventLabel(result.payload.event_type) }}</ion-card-title>
    </ion-card-header>
    <ion-card-content>
      <p class="result-message">{{ attendanceSyncMessage(receipt) }}</p>
      <p>{{ attendanceLocationLabel(result.evaluation.result) }}</p>
      <p v-if="distance">Distancia: {{ distance }}</p>
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
  attendanceResultColor, attendanceSyncMessage,
} from '@/presentation/attendanceResult'

const props = defineProps<{ result: AttendanceCaptureResult; receipt: AttendanceReceipt | null }>()
const distance = computed(() => attendanceDistance(props.result.evaluation.distance_m))
const color = computed(() => attendanceResultColor(props.result.evaluation.result, props.receipt))
</script>

<style scoped>
.result-message { font-weight: 600; margin-bottom: 0.75rem; }
</style>
