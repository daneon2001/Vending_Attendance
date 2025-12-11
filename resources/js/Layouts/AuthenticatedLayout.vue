<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link } from '@inertiajs/vue3';

const mobileSidebarOpen = ref(false);
const sidebarCollapsed = ref(false);

const navItems = [
    {
        label: 'Panel general',
        description: 'KPI diarios y alertas',
        routeName: 'dashboard',
        icon: 'dashboard',
    },
    {
        label: 'Relojes biometricos',
        description: 'Catalogo y monitoreo',
        routeName: 'clocks.index',
        icon: 'clocks',
    },
    {
        label: 'Catálogo Sucursales',
        description: 'Adminitración de sucursales',
        routeName: null,
        icon: 'branches',
    },
];

const iconPaths = {
    dashboard: [
        'M4 5h16',
        'M4 19h16',
        'M7 12v7',
        'M12 9v10',
        'M17 14v5',
    ],
    clocks: [
        'M12 7a5 5 0 110 10 5 5 0 010-10z',
        'M12 3v2',
        'M12 9v3.5l2 1.5',
        'M5 12h2',
        'M17 12h2',
    ],
    branches: [
        'M12 4L5 10v8a2 2 0 002 2h3v-5h4v5h3a2 2 0 002-2v-8l-7-6z',
        'M9 21V9.5',
        'M15 21V9.5',
    ],
};

const currentYear = new Date().getFullYear();

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
};

const isActive = (routeName) => (routeName ? route().current(routeName) : false);
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-800">
        <div class="flex min-h-screen">
            <!-- Desktop sidebar -->
            <aside
                :class="[
                    'hidden border-r border-slate-100 bg-white/90 backdrop-blur transition-all duration-300 lg:flex lg:flex-col lg:py-6',
                    sidebarCollapsed ? 'w-24 px-3 items-center' : 'w-72 px-6',
                ]"
            >
                <div class="flex w-full items-center gap-3" :class="sidebarCollapsed ? 'justify-center' : 'justify-between'">
                    <div class="flex items-center gap-3" :class="sidebarCollapsed ? 'justify-center' : ''">
                        <ApplicationLogo
                            :variant="sidebarCollapsed ? 'short' : 'full'"
                            class="h-12 w-auto drop-shadow-sm"
                        />
                   <!--     <div v-if="!sidebarCollapsed">
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                                Medical Life
                            </p>
                            <p class="text-lg font-semibold text-slate-900">
                                Plataforma RH
                            </p>
                        </div>
                    -->
                    </div>

                    <button
                        type="button"
                        class="rounded-2xl border border-slate-200 p-2 text-slate-500 hover:text-slate-900"
                        @click="toggleSidebar"
                        :aria-pressed="sidebarCollapsed"
                    >
                        <span class="sr-only">Alternar tamaño de menú</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path
                                v-if="!sidebarCollapsed"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.5"
                                d="M15.75 19.5L8.25 12l7.5-7.5"
                            />
                            <path
                                v-else
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.5"
                                d="M8.25 4.5L15.75 12l-7.5 7.5"
                            />
                        </svg>
                    </button>
                </div>

                <nav
                    :class="[
                        'mt-10 flex-1',
                        sidebarCollapsed ? 'space-y-3 text-center text-xs' : 'space-y-1 text-sm',
                    ]"
                >
                    <Link
                        v-for="item in navItems"
                        :key="item.label"
                        :href="item.routeName ? route(item.routeName) : '#'"
                        :class="[
                            'group flex flex-col rounded-2xl border transition-all',
                            sidebarCollapsed ? 'items-center px-2 py-4' : 'px-4 py-3',
                            isActive(item.routeName)
                                ? 'border-indigo-200 bg-indigo-50 text-indigo-700 shadow-sm'
                                : 'border-transparent bg-white hover:border-slate-200 hover:bg-slate-50',
                            !item.routeName ? 'opacity-70' : '',
                        ]"
                        :title="sidebarCollapsed ? item.label : undefined"
                        :aria-disabled="!item.routeName"
                    >
                        <div
                            class="flex w-full items-center gap-3"
                            :class="sidebarCollapsed ? 'flex-col gap-2 text-xs' : ''"
                        >
                            <span
                                :class="[
                                    'flex h-10 w-10 items-center justify-center rounded-2xl border text-slate-500 transition-colors',
                                    isActive(item.routeName)
                                        ? 'border-indigo-200 bg-indigo-50 text-indigo-600'
                                        : 'border-slate-200 bg-white',
                                ]"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path
                                        v-for="(path, idx) in iconPaths[item.icon] || []"
                                        :key="`${item.icon}-${idx}`"
                                        :d="path"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                                <span class="sr-only">{{ item.label }}</span>
                            </span>
                            <div v-if="!sidebarCollapsed" class="flex flex-col text-left">
                                <span class="text-sm font-semibold">{{ item.label }}</span>
                                <span class="text-xs text-slate-500" v-text="item.description" />
                            </div>
                        </div>
                        <span
                            v-if="!sidebarCollapsed && !item.routeName"
                            class="mt-2 w-fit rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400"
                        >
                            Próximamente
                        </span>
                    </Link>
                </nav>

                <div
                    v-if="!sidebarCollapsed"
                    class="mt-6 rounded-3xl border border-slate-100 bg-gradient-to-br from-indigo-50 via-white to-purple-50 p-4 text-sm text-slate-600"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-indigo-500">Clima laboral</p>
                    <p class="mt-2 text-lg font-semibold text-slate-900">+92% satisfacción</p>
                    <p class="mt-1 text-xs text-slate-500">Datos obtenidos de la última campaña de pulso.</p>
                </div>
            </aside>

            <!-- Content -->
            <div class="flex flex-1 flex-col">
                <header class="sticky top-0 z-20 border-b border-slate-100 bg-white/90 backdrop-blur">
                    <div class="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-10">
                        <div class="flex items-center gap-4">
                            <button
                                class="rounded-2xl border border-slate-200 p-2 text-slate-500 lg:hidden"
                                @click="mobileSidebarOpen = true"
                            >
                                <span class="sr-only">Abrir menu</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h10" />
                                </svg>
                            </button>
                            <div>
                                <div class="text-base font-semibold text-slate-900">
                                    <slot name="header">
                                        Panel de control
                                    </slot>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="hidden text-right sm:block">
                                <p class="text-sm font-semibold text-slate-900">{{ $page.props.auth.user.name }}</p>
                                <p class="text-xs text-slate-500">{{ $page.props.auth.user.email }}</p>
                            </div>

                            <Dropdown align="right" width="48" :content-classes="'bg-white shadow-lg ring-1 ring-slate-900/5 rounded-2xl py-2'">
                                <template #trigger>
                                    <button class="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm hover:text-slate-900">
                                        <span class="hidden text-right sm:flex sm:flex-col">
                                            <span class="text-xs uppercase tracking-wide text-slate-400">Usuario</span>
                                            <span>{{ $page.props.auth.user.name.split(' ')[0] }}</span>
                                        </span>
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 text-sm font-semibold text-white">
                                            {{ $page.props.auth.user.name.slice(0, 2).toUpperCase() }}
                                        </span>
                                    </button>
                                </template>
                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">
                                        Perfil
                                    </DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">
                                        Cerrar sesion
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </header>

                <main class="flex-1 px-4 py-8 sm:px-6 lg:px-10">
                    <div class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <slot />
                    </div>
                </main>

                <footer class="border-t border-slate-100 bg-white/80 px-4 py-4 text-xs text-slate-500 sm:px-6 lg:px-10">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <p>&copy; {{ currentYear }} Medicallife suite | Gestion humana digital.</p>
                        <p class="text-[11px] uppercase tracking-[0.3em] text-slate-400">
                             Datos protegidos
                        </p>
                    </div>
                </footer>
            </div>
        </div>

        <!-- Mobile sidebar -->
        <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="mobileSidebarOpen" class="fixed inset-0 z-40 flex lg:hidden">
                <div class="w-72 bg-white p-6 shadow-2xl">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <ApplicationLogo class="h-8 w-8 text-indigo-600" />
                            <p class="text-base font-semibold text-slate-900">Opensync HR</p>
                        </div>
                        <button class="rounded-full border border-slate-200 p-2" @click="mobileSidebarOpen = false">
                            <span class="sr-only">Cerrar menu</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <nav class="mt-8 space-y-2">
                        <Link
                            v-for="item in navItems"
                            :key="item.label"
                            :href="item.routeName ? route(item.routeName) : '#'"
                            class="block rounded-2xl border px-4 py-3 text-sm font-medium"
                            :class="item.routeName && route().current(item.routeName) ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-100 text-slate-600'"
                            :aria-disabled="!item.routeName"
                            @click="mobileSidebarOpen = false"
                        >
                            {{ item.label }}
                            <span class="block text-xs font-normal text-slate-400">{{ item.description }}</span>
                            <span
                                v-if="!item.routeName"
                                class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] uppercase tracking-wide text-slate-400"
                            >
                                Proximamente
                            </span>
                        </Link>
                    </nav>
                </div>
                <div class="flex-1 bg-slate-900/30" @click="mobileSidebarOpen = false"></div>
            </div>
        </Transition>
    </div>
</template>
