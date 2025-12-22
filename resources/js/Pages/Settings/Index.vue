<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    sections: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const canManageSettings = computed(() => {
    const actions = permissionMatrix.value?.settings ?? [];
    return actions.includes('manage');
});
</script>

<template>
    <Head title="Configuración" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-2xl font-semibold">Configuración</h1>
                <p class="text-sm text-muted">Ajusta los permisos, seguridad y preferencias del sistema.</p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="card p-6">
                <header class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Centro de control</p>
                        <h2 class="text-xl font-semibold text-app">Secciones disponibles</h2>
                    </div>
                    <span
                        class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-soft dark:bg-slate-800 dark:text-slate-300"
                    >
                        {{ sections.length }} secciones
                    </span>
                </header>

                <div class="mt-6 grid gap-4 lg:grid-cols-2">
                    <article
                        v-for="section in sections"
                        :key="section.label"
                        class="rounded-3xl border border-app px-5 py-4 shadow-sm transition hover:border-indigo-200 hover:shadow-lg dark:border-slate-700 dark:hover:border-indigo-500/40"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                    {{ section.label }}
                                </p>
                                <p class="text-sm text-muted">
                                    {{ section.description }}
                                </p>
                            </div>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-widest"
                                :class="section.available ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-100' : 'bg-slate-100 text-soft dark:bg-slate-800'"
                            >
                                {{ section.available ? 'Disponible' : 'Sin acceso' }}
                            </span>
                        </div>
                        <div class="mt-4 flex flex-wrap justify-between gap-3">
                            <Link
                                v-if="section.route && section.available"
                                :href="section.route"
                                class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                            >
                                Administrar
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </Link>
                            <p v-else class="text-xs text-muted">
                                No cuentas con permisos suficientes para esta sección.
                            </p>
                        </div>
                    </article>
                </div>
            </div>

            <div
                v-if="!canManageSettings"
                class="rounded-3xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/40 dark:bg-amber-900/40 dark:text-amber-100"
            >
                <strong>Nota:</strong> Tu cuenta tiene permisos limitados en Configuración. Solicita a un administrador los permisos necesarios para realizar cambios.
            </div>
        </section>
    </AuthenticatedLayout>
</template>
