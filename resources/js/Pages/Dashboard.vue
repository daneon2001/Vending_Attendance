<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { canUse } from '@/presentation/navigation';
import { assetUrl } from '@/utils/url';
defineProps({ companies: Array, locations: Array });
const page = usePage();
const canVending = computed(() => canUse(page.props.auth?.permissions, 'vending_machines'));
const canLegacy = computed(() => canUse(page.props.auth?.permissions, 'dashboard'));
const showLegacy = computed(() => canLegacy.value && (!canVending.value || new URL(page.url, 'http://localhost').searchParams.get('view') === 'legacy'));
const LegacyDashboard = defineAsyncComponent(() => import('./Dashboard/LegacyDashboard.vue'));
</script>
<template>
 <LegacyDashboard v-if="showLegacy" :companies="companies" :locations="locations" />
 <AuthenticatedLayout v-else>
  <Head title="Inicio · Vending Attendance" />
  <template #header><h1 class="text-xl font-semibold text-app">Vending Attendance</h1></template>
  <section class="card max-w-3xl space-y-5 p-6 sm:p-8"><p class="text-sm font-semibold text-soft">MEDICAL LIFE ONE · Operación vending</p><h2 class="text-2xl font-semibold text-app">¿Qué necesita atención hoy?</h2><p class="text-soft">Consulta máquinas, dispositivos y registros pendientes en el resumen de operación.</p><Link v-if="canVending" :href="route('vending-fleet.dashboard')" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white">Abrir resumen de operación</Link><p v-else class="text-soft">Utiliza las opciones autorizadas del menú. Si necesitas otro acceso, solicítalo al administrador.</p><Link v-if="canLegacy" :href="route('dashboard', { view: 'legacy' })" class="block py-2 text-sm text-indigo-600">Consultar panel general del sistema anterior</Link></section>
  <aside class="dispenser-brand-banner" aria-label="Dispensadora Medical Life"><div><span>MEDICAL LIFE ONE</span><h2>Tecnología en Movimiento</h2><p>Personas · Máquinas · Resultados</p></div><img :src="assetUrl('images/medical-life-dispenser-banner.webp')" alt="Panel y compartimentos de la dispensadora de medicamentos Medical Life" width="720" height="400" loading="lazy" /></aside>
 </AuthenticatedLayout>
</template>
<style scoped>
.dispenser-brand-banner { max-width: 48rem; display: grid; grid-template-columns: 1fr 1fr; overflow: hidden; border-radius: 20px; margin-top: 24px; background: linear-gradient(125deg,#06377e,#0058d4); color: white; }
.dispenser-brand-banner > div { align-self: center; padding: 24px; }
.dispenser-brand-banner span { font-size: .7rem; font-weight: 700; letter-spacing: .1em; }
.dispenser-brand-banner h2 { margin: 12px 0; font-size: 1.5rem; line-height: 1.25; font-weight: 700; }
.dispenser-brand-banner p { font-size: .875rem; }
.dispenser-brand-banner img { width: 100%; height: 100%; object-fit: cover; }
@media(max-width:480px){.dispenser-brand-banner > div{padding:16px}.dispenser-brand-banner h2{font-size:1.15rem}}
</style>
