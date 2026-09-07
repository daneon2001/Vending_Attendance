<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { GeofenceSnapshot, GeofenceEvaluation } from '@/domain/types'
import { edgeStore } from '@/app/services'
import { runtimeConfig } from '@/config/runtime'
import { CapacitorLocationService } from '@/services/LocationService'
import { GeofenceDiagnosticService } from '@/services/GeofenceDiagnosticService'
import { attendanceDistance, attendanceLocationLabel } from '@/presentation/attendanceResult'

const props = defineProps<{ configurationVersion: number | null }>()
const service = new GeofenceDiagnosticService(edgeStore, new CapacitorLocationService(runtimeConfig.gpsTimeoutMs))
const zone = ref<GeofenceSnapshot | null>(null)
const result = ref<GeofenceEvaluation | null>(null)
const capturedAt = ref('')
const message = ref('')
const busy = ref(false)
let generation = 0
async function load() {
 const current = ++generation
 result.value = null; busy.value = false; message.value = ''
 try {
  const snapshot = await service.read()
  if (current === generation) zone.value = snapshot
 } catch { if (current === generation) zone.value = null }
}
async function measure() {
 const current = ++generation
 busy.value = true; message.value = ''; result.value = null
 try {
  const response = await service.measure()
  if (current === generation) { result.value = response.evaluation; capturedAt.value = response.capturedAt }
 } catch {
  if (current === generation) message.value = 'No fue posible consultar la ubicación con la zona actual. Revisa el permiso de ubicación e inténtalo de nuevo.'
 } finally { if (current === generation) busy.value = false }
}
onMounted(load)
watch(() => props.configurationVersion, load)
onBeforeUnmount(() => { generation++ })
</script>

<template>
 <section class="terminal-zone" aria-labelledby="terminal-zone-title">
  <h3 id="terminal-zone-title">Zona asignada</h3>
  <template v-if="zone">
   <p>Configuración local disponible · Radio {{ attendanceDistance(zone.radius_m) }}</p>
   <p class="muted">Información de la última configuración aplicada. No modifica la zona ni registra una asistencia.</p>
   <button type="button" :disabled="busy" @click="measure">{{ busy ? 'Consultando ubicación…' : 'Consultar mi distancia a la zona' }}</button>
   <div v-if="result" role="status">
    <p>{{ attendanceLocationLabel(result.result) }}</p>
    <p>Distancia al centro: {{ attendanceDistance(result.distance_m) }}</p>
    <p>Precisión de la ubicación: {{ attendanceDistance(result.accuracy_m) }}</p>
    <p>Consultada: {{ new Date(capturedAt).toLocaleTimeString('es-MX') }}. No es seguimiento en tiempo real.</p>
   </div>
  </template>
  <p v-else>No hay una configuración local completa disponible para consultar la zona. Sincroniza o solicita apoyo.</p>
  <p v-if="message" role="alert">{{ message }}</p>
 </section>
</template>

<style scoped>
.terminal-zone { margin-top: 16px; border-top: 1px solid var(--ion-color-light-shade); padding-top: 16px; }
.terminal-zone h3 { font-size: 1rem; font-weight: 650; }
.terminal-zone p { line-height: 1.5; }
.terminal-zone button { min-height: 48px; padding: 10px 12px; border: 1px solid var(--ion-color-primary); border-radius: 8px; background: transparent; color: var(--ion-color-primary); font: inherit; }
.terminal-zone button:focus-visible { outline: 3px solid var(--ion-color-primary); outline-offset: 3px; }
.terminal-zone button:disabled { opacity: .6; }
</style>
