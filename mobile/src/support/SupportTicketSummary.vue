<template>
  <h1>{{ ticket.server?.folio ?? 'Reporte guardado' }}</h1>
  <p role="status" aria-live="polite">{{ ticketMessage(ticket) }}</p>
  <section class="support-card">
    <h2>{{ ticket.server?.title ?? ticket.payload.title }}</h2>
    <p>{{ ticket.server ? ticketStatus(ticket.server.status) : deliveryLabel(ticket.status) }}</p>
    <p class="support-body">{{ ticket.server?.description ?? ticket.payload.description }}</p>
    <p class="support-caption">{{ dateLabel(ticket.payload.reported_at) }} · {{ ticket.payload.location ? 'Ubicación adjunta' : 'Ubicación no disponible' }}</p>
  </section>
</template>
<script setup lang="ts">
import type { LocalTicket } from './types'
import { dateLabel, deliveryLabel, ticketMessage, ticketStatus } from './presentation'
defineProps<{ ticket: LocalTicket }>()
</script>
