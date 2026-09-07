<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { canUse } from '@/presentation/navigation';
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
  <section class="card max-w-3xl space-y-5 p-6 sm:p-8"><p class="text-sm font-semibold text-soft">Medical Life · Operación vending</p><h2 class="text-2xl font-semibold text-app">¿Qué necesita atención hoy?</h2><p class="text-soft">Consulta máquinas, dispositivos y registros pendientes en el resumen de operación.</p><Link v-if="canVending" :href="route('vending-fleet.dashboard')" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white">Abrir resumen de operación</Link><p v-else class="text-soft">Utiliza las opciones autorizadas del menú. Si necesitas otro acceso, solicítalo al administrador.</p><Link v-if="canLegacy" :href="route('dashboard', { view: 'legacy' })" class="block py-2 text-sm text-indigo-600">Consultar panel general del sistema anterior</Link></section>
 </AuthenticatedLayout>
</template>
