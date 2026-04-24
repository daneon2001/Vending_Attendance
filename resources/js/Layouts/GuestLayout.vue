<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
</script>

<template>
    <div class="min-h-screen bg-[#f3f6fb] px-4 py-10 sm:px-6 lg:px-8">
        <div
            class="mx-auto grid max-w-6xl gap-6 rounded-2xl p-6 sm:p-8 lg:p-10 lg:grid-cols-[1.1fr_0.9fr]"
        >
            <!-- PANEL IZQUIERDO: HERO / RESUMEN -->
            <section
                class="relative order-2 overflow-hidden rounded-2xl 
                       bg-gradient-to-b 
                       from-white 
                       via-white 
                       via-50% 
                       to-[#155F88] 
                       to-80%
                       p-6 sm:p-8 lg:p-10
                       text-slate-900
                       shadow-2xl shadow-[#0F3F6F]/30
                       lg:order-1"
            >
                <div class="relative space-y-6 sm:space-y-8">
                    <!-- CHIP SUPERIOR -->
                    <p
                        class="inline-flex items-center rounded-full 
                               bg-slate-900/80 
                               px-4 sm:px-5 py-1 
                               text-[0.65rem] sm:text-xs 
                               font-semibold uppercase 
                               tracking-[0.3em] sm:tracking-[0.35em] 
                               text-white"
                    >
                        Medical Life Suite
                    </p>

                    <!-- TÍTULO PRINCIPAL -->
                    <h1
                        class="text-2xl sm:text-3xl lg:text-4xl xl:text-5xl 
                               font-semibold leading-tight 
                               text-slate-900"
                    >
                        Control de asistencias y biométricos.
                    </h1>

                    <!-- DESCRIPCIÓN -->
                    <p class="text-sm sm:text-base text-slate-700 max-w-xl">
                        Consolida registro de checada desde cualquier clínica o sucursal.
                        Seguridad biométrica integrada y tableros en vivo para el personal
                        administrativo.
                    </p>
                </div>

                <!-- MÉTRICAS / CARDS -->
                <dl
                    class="mt-10 sm:mt-12 grid gap-4 sm:gap-6 
                           text-xs sm:text-sm font-medium 
                           text-slate-900 sm:grid-cols-2"
                >
                    <div class="rounded-3xl border border-white/60 bg-white/90 p-4 sm:p-5">
                        <dt class="text-[0.65rem] sm:text-xs uppercase tracking-[0.35em] text-slate-500">
                            Cobertura
                        </dt>
                        <dd class="mt-2 text-2xl sm:text-3xl font-semibold text-slate-900">
                            120+
                        </dd>
                        <p class="mt-1 text-[0.7rem] sm:text-xs text-slate-600">
                            clínicas monitoreadas
                        </p>
                    </div>

                </dl>

                <!-- TAGS INFERIORES -->
                <div
                    class="mt-8 sm:mt-10 flex flex-wrap gap-3 sm:gap-4 
                           text-[0.65rem] sm:text-xs 
                           uppercase tracking-[0.35em] 
                           text-slate-700"
                >
                    <span class="rounded-full bg-white/80 px-3 py-1">
                        Sanctum
                    </span>
                    <span class="rounded-full bg-white/80 px-3 py-1">
                        Logs cifrados
                    </span>
                    <span class="rounded-full bg-white/80 px-3 py-1">
                        Soporte 24/7
                    </span>
                </div>
            </section>

            <!-- PANEL DERECHO: LOGIN -->
            <section
                class="relative order-1 rounded-2xl border border-[#c5d6ef] bg-white 
                       p-6 sm:p-8 lg:p-10 
                       shadow-xl shadow-slate-900/5
                       lg:order-2"
            >
                <ApplicationLogo class="mx-auto h-16 w-auto sm:h-20 lg:h-24 drop-shadow-lg" />
                <div class="text-center">
                    <p
                        class="text-[0.7rem] sm:text-xs font-semibold uppercase 
                               tracking-[0.35em] text-[#36A144]"
                    >
                        Acceso seguro
                    </p>
                    <h2
                        class="mt-2 text-2xl sm:text-3xl font-semibold text-slate-900 leading-snug"
                    >
                        Inicia sesión en Medical Life
                    </h2>
                    <p class="mt-2 text-xs sm:text-sm text-slate-500">
                        Conecta tu credencial para registrar asistencias o administrar dispositivos.
                    </p>
                </div>

                <div class="mt-8 sm:mt-10">
                    <slot />
                </div>

                <p class="mt-8 sm:mt-10 text-center text-[0.7rem] sm:text-xs text-slate-400">
                    Protegido con tokens Bearer + Laravel Sanctum
                </p>
            </section>
        </div>
    </div>
</template>
