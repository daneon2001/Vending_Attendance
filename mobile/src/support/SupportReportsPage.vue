<template>
  <SupportLayout title="Mis reportes">
    <h1>Reportes de este equipo</h1><p class="muted">Borradores y reportes permitidos para esta terminal.</p>
    <ion-button fill="outline" router-link="/support/report" :disabled="!context">Nuevo reporte</ion-button>
    <p v-if="archivedReports" class="support-caption" role="status">Hay reportes de una asociación anterior conservados y separados. Solicita revisión al responsable.</p>
    <p v-if="startupError" class="support-error" role="alert">No se pudo abrir soporte. Vuelve a intentarlo desde la pantalla anterior.</p>
    <p v-else-if="!ready" role="status">Consultando reportes guardados…</p>
    <p v-else-if="!tickets.length" class="support-card">Aún no hay reportes guardados en este equipo.</p>
    <ul v-else class="support-list">
      <li v-for="ticket in tickets" :key="ticket.localUuid">
        <RouterLink class="support-link" :to="ticket.status === 'DRAFT' ? { path: '/support/report', query: { draft: ticket.localUuid } } : `/support/tickets/${ticket.localUuid}`">
          <span>{{ ticket.server?.folio ?? (ticket.status === 'DRAFT' ? 'Continuar borrador' : 'Reporte guardado') }}</span>
          <h2>{{ ticket.server?.title ?? ticket.payload.title }}</h2>
        </RouterLink>
        <p>{{ ticket.server ? ticketStatus(ticket.server.status) : deliveryLabel(ticket.status) }}</p>
        <p class="support-caption">{{ dateLabel(ticket.payload.reported_at) }}</p>
      </li>
    </ul>
    <p class="support-caption">Se muestran hasta 100 reportes guardados. Las novedades se recuperan por páginas.</p>
    <ion-button fill="clear" :disabled="state.phase === 'SYNCING'" @click="sync">{{ state.phase === 'SYNCING' ? 'Actualizando…' : 'Actualizar reportes' }}</ion-button>
  </SupportLayout>
</template>
<script setup lang="ts">
import { IonButton } from '@ionic/vue'
import { RouterLink } from 'vue-router'
import SupportLayout from './SupportLayout.vue'
import { useSupport } from './useSupport'
import { dateLabel, deliveryLabel, ticketStatus } from './presentation'
const { context, tickets, archivedReports, ready, startupError, state, sync } = useSupport()
</script>
