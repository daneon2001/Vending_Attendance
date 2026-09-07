<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
</script>
<template>
 <main class="min-h-screen bg-slate-50 text-slate-900">
  <div class="mx-auto grid min-h-screen max-w-6xl items-center gap-8 px-5 py-8 lg:grid-cols-2 lg:gap-16">
   <section class="space-y-5"><ApplicationLogo class="h-16 w-auto max-w-full" /><p class="text-sm font-semibold uppercase tracking-widest text-emerald-700">Medical Life</p><h1 class="text-3xl font-semibold text-slate-900 sm:text-4xl">Vending Attendance</h1><p class="max-w-md text-lg leading-relaxed text-slate-600">La operación de tus máquinas, en un solo lugar.</p><p class="max-w-md text-sm leading-relaxed text-slate-600">Consulta dispositivos, empleados y sincronización. Encuentra lo que necesita atención y continúa con tu operación.</p></section>
   <section class="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"><slot /></section>
  </div>
 </main>
</template>
