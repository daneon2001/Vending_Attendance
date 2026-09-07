<template>
  <SupportLayout title="Soporte" back="/home">
    <h1>Soporte de la máquina</h1>
    <p class="muted">Reporta un problema o revisa este equipo.</p>
    <p v-if="context" class="support-caption">{{ context.machine.name || context.machine.code }}</p>
    <p v-if="startupError" class="support-error" role="alert">No se pudo abrir soporte. La asistencia sigue disponible.</p>
    <p v-else-if="!ready" role="status">Preparando soporte…</p>
    <p v-else-if="!context" role="status">Conecta el equipo una vez para preparar las categorías y los límites de soporte.</p>
    <p v-if="state.phase === 'ERROR'" class="support-error" role="alert">No se pudo actualizar soporte. Los reportes guardados permanecen en el equipo; revisa la conexión o solicita apoyo.</p>
    <div class="support-actions">
      <ion-button expand="block" router-link="/support/report" :disabled="!context">Reportar incidencia</ion-button>
      <ion-button expand="block" fill="outline" router-link="/support/reports" :disabled="!ready">Mis reportes</ion-button>
      <ion-button expand="block" fill="outline" router-link="/support/verify" :disabled="!context">Verificar este equipo</ion-button>
    </div>
    <p class="support-caption">Los reportes de esta terminal se guardan sin conexión y se envían al recuperar la red.</p>
    <div class="support-status" role="status" aria-live="polite">{{ network === 'ONLINE' ? 'Equipo conectado' : 'Sin conexión disponible' }} · {{ state.phase === 'SYNCING' ? 'Actualizando soporte…' : 'Tus reportes permanecen guardados' }}</div>
    <ion-button fill="clear" :disabled="state.phase === 'SYNCING'" @click="sync">Actualizar soporte</ion-button>
    <section v-if="notices.length" aria-labelledby="support-news-title">
      <h2 id="support-news-title">Novedades recientes <span class="support-caption">({{ unread }} sin leer)</span></h2>
      <ul class="support-list">
        <li v-for="notice in notices.slice(0, 8)" :key="notice.event.uuid">
          <h3>{{ eventLabel(notice.event) }}</h3><p>{{ notice.event.ticket?.folio ?? 'Reporte de este equipo' }}</p>
          <p class="support-caption">{{ dateLabel(notice.event.created_at) }} · {{ notice.read ? 'Leída' : 'Sin leer' }}</p>
          <ion-button v-if="!notice.read" fill="clear" @click="readNotice(notice.event.uuid)">Marcar como leída</ion-button>
        </li>
      </ul>
    </section>
  </SupportLayout>
</template>
<script setup lang="ts">
import { IonButton } from '@ionic/vue'
import SupportLayout from './SupportLayout.vue'
import { useSupport } from './useSupport'
import { dateLabel, eventLabel } from './presentation'
const { context, ready, startupError, network, state, notices, unread, readNotice, sync } = useSupport()
</script>
