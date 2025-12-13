<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';
import { useSidebar } from '@/composables/useSidebar';

const mobileSidebarOpen = ref(false);
const { theme, toggleTheme } = useTheme();
const { isCollapsed, collapseSidebar, expandSidebar } = useSidebar();

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
        label: 'Catalogo de empleados',
        description: 'Estado y huellas',
        routeName: 'employees.index',
        icon: 'users',
    },
    {
        label: 'Catalogo Sucursales',
        description: 'Administracion de sucursales',
        routeName: 'units.index',
        icon: 'branches',
    },
];

const iconPaths = {
    dashboard: ['M4 5h16', 'M4 19h16', 'M7 12v7', 'M12 9v10', 'M17 14v5'],
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
    users: [
        'M16 11c1.657 0 3-1.567 3-3.5S17.657 4 16 4s-3 1.567-3 3.5S14.343 11 16 11z',
        'M8 11c1.657 0 3-1.567 3-3.5S9.657 4 8 4 5 5.567 5 7.5 6.343 11 8 11z',
        'M3 20v-1c0-2.761 2.239-5 5-5c2.761 0 5 2.239 5 5v1',
        'M13 20v-1c0-2.075 1.567-4 3.5-4H17c1.933 0 3.5 1.925 3.5 4v1',
    ],
};

const currentYear = new Date().getFullYear();
const isActive = (routeName) => (routeName ? route().current(routeName) : false);
</script>

<template>
    <div class="min-h-screen bg-app text-app transition-colors duration-300">
        <div class="flex min-h-screen">
            <aside
                :class="[
                    'hidden border-r border-app bg-white/90 backdrop-blur transition-all duration-300 transition-[width] ease-in-out dark:bg-slate-900/70 lg:flex lg:flex-col lg:py-6',
                    isCollapsed ? 'w-20 px-3 items-center' : 'w-72 px-6',
                ]"
            >
                <div class="flex w-full items-center gap-3" :class="isCollapsed ? 'flex-col gap-4' : 'justify-between'">
                    <div class="flex items-center gap-3" :class="isCollapsed ? 'justify-center' : ''">
                        <ApplicationLogo :variant="isCollapsed ? 'short' : 'full'" class="h-12 w-auto drop-shadow-sm" />
                    </div>

                    <button
                        v-if="isCollapsed"
                        type="button"
                        class="flex h-10 w-10 items-center justify-center rounded-2xl border border-app text-soft transition-colors hover:text-app dark:hover:text-white"
                        aria-label="Expandir menu lateral"
                        @click="expandSidebar"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>

                <nav
                    :class="[
                        'mt-10 flex-1',
                        isCollapsed ? 'space-y-3 text-center text-xs' : 'space-y-1 text-sm',
                    ]"
                >
                    <Link
                        v-for="item in navItems"
                        :key="item.label"
                        :href="item.routeName ? route(item.routeName) : '#'"
                        :class="[
                            'group flex flex-col rounded-2xl border transition-all text-muted',
                            isCollapsed ? 'items-center px-2 py-4' : 'px-4 py-3',
                            isActive(item.routeName)
                                ? 'border-indigo-200 bg-indigo-50 text-indigo-700 shadow-sm dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-200'
                                : 'border-transparent bg-white hover:border-app hover:bg-slate-50 dark:border-transparent dark:bg-slate-900 dark:hover:border-slate-700 dark:hover:bg-slate-800',
                            !item.routeName ? 'opacity-70' : '',
                        ]"
                        :title="isCollapsed ? item.label : undefined"
                        :aria-disabled="!item.routeName"
                    >
                        <div class="flex w-full items-center gap-3" :class="isCollapsed ? 'flex-col gap-2 text-xs' : ''">
                            <span
                                :class="[
                                    'flex h-10 w-10 items-center justify-center rounded-2xl border text-soft transition-colors',
                                    isActive(item.routeName)
                                        ? 'border-indigo-200 bg-indigo-50 text-indigo-600 dark:border-indigo-500/50 dark:bg-indigo-500/10 dark:text-indigo-200'
                                        : 'border-app bg-white  dark:bg-slate-900',
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
                            <div v-if="!isCollapsed" class="flex flex-col text-left">
                                <span class="text-sm font-semibold">{{ item.label }}</span>
                                <span class="text-xs text-soft" v-text="item.description" />
                            </div>
                        </div>
                        <span
                            v-if="!isCollapsed && !item.routeName"
                            class="chip-muted mt-2 w-fit"
                        >
                            Proximamente
                        </span>
                    </Link>
                </nav>

                <div
                    v-if="!isCollapsed"
                    class="mt-6 rounded-3xl border border-app bg-gradient-to-br from-indigo-50 via-white to-purple-50 p-4 text-sm text-muted  dark:from-indigo-900/40 dark:via-slate-900 dark:to-purple-900/40 "
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-indigo-500">Clima laboral</p>
                    <p class="mt-2 text-lg font-semibold text-app">+92% satisfaccion</p>
                    <p class="mt-1 text-xs text-soft">Datos obtenidos de la ultima campana de pulso.</p>
                </div>
            </aside>

            <div class="flex flex-1 flex-col">
                <header class="sticky top-0 z-20 border-b border-app bg-white/90 backdrop-blur  dark:bg-slate-900/80">
                    <div class="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-10">
                        <div class="flex items-center gap-4">
                            <button
                                class="rounded-2xl border border-app p-2 text-soft lg:hidden"
                                @click="mobileSidebarOpen = true"
                            >
                                <span class="sr-only">Abrir menu</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h10" />
                                </svg>
                            </button>

                            <button
                                v-if="!isCollapsed"
                                type="button"
                                class="hidden h-10 w-10 items-center justify-center rounded-2xl border border-app text-soft transition-colors hover:text-app dark:hover:text-white lg:inline-flex"
                                aria-label="Colapsar menu lateral"
                                @click="collapseSidebar"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>

                            <div>
                                <div class="text-base font-semibold text-app">
                                    <slot name="header">
                                        Panel de control
                                    </slot>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <button
                                type="button"
                                class="flex h-10 w-10 items-center justify-center rounded-full border border-app text-soft transition-colors hover:text-app dark:hover:text-white"
                                :aria-label="theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'"
                                @click="toggleTheme"
                            >
                                <svg
                                    v-if="theme === 'dark'"
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.364 6.364l-1.414-1.414M7.05 7.05 5.636 5.636m12.728 0-1.414 1.414M7.05 16.95l-1.414 1.414" />
                                    <circle cx="12" cy="12" r="4" />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                                </svg>
                            </button>

                            <div class="hidden text-right sm:block">
                                <p class="text-sm font-semibold text-app">{{ $page.props.auth.user.name }}</p>
                                <p class="text-xs text-soft">{{ $page.props.auth.user.email }}</p>
                            </div>

                            <Dropdown align="right" width="48" :content-classes="'bg-white shadow-lg ring-1 ring-slate-900/5 rounded-2xl py-2 dark:bg-slate-900 dark:text-slate-100'">
                                <template #trigger>
                                    <button class="flex items-center gap-2 rounded-full border border-app bg-white px-3 py-1.5 text-sm font-medium text-muted shadow-sm hover:text-app  dark:bg-slate-900  dark:hover:text-white">
                                        <span class="hidden text-right sm:flex sm:flex-col">
                                            <span class="text-xs uppercase tracking-wide text-soft dark:text-soft">Usuario</span>
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
                    <div class="card p-6">
                        <slot />
                    </div>
                </main>

                <footer class="border-t border-app bg-white/80 px-4 py-4 text-xs text-soft  dark:bg-slate-900/80 sm:px-6 lg:px-10">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <p>&copy; {{ currentYear }} Medicallife suite | Gestion humana digital.</p>
                        <p class="text-[11px] uppercase tracking-[0.3em] text-soft dark:text-soft">
                            Datos protegidos
                        </p>
                    </div>
                </footer>
            </div>
        </div>

        <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="mobileSidebarOpen" class="fixed inset-0 z-40 flex lg:hidden">
                <div class="w-72 bg-white p-6 shadow-2xl dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <ApplicationLogo class="h-8 w-8 text-indigo-600" />
                            <p class="text-base font-semibold text-app">Opensync HR</p>
                        </div>
                        <button class="rounded-full border border-app p-2 " @click="mobileSidebarOpen = false">
                            <span class="sr-only">Cerrar menu</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <nav class="mt-8 space-y-2">
                        <Link
                            v-for="item in navItems"
                            :key="item.label"
                            :href="item.routeName ? route(item.routeName) : '#'"
                            class="block rounded-2xl border px-4 py-3 text-sm font-medium text-muted"
                            :class="item.routeName && route().current(item.routeName)
                                ? 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-200'
                                : 'border-app'"
                            :aria-disabled="!item.routeName"
                            @click="mobileSidebarOpen = false"
                        >
                            {{ item.label }}
                            <span class="block text-xs font-normal text-soft dark:text-soft">{{ item.description }}</span>
                            <span
                                v-if="!item.routeName"
                                class="chip-muted mt-1 inline-flex"
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
