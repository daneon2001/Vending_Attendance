<script setup>
import { canUse, visibleNavigation } from '@/presentation/navigation';
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';
import { useSidebar } from '@/composables/useSidebar';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    contentOverflowVisible: {
        type: Boolean,
        default: false,
    },
});

const mobileSidebarOpen = ref(false);
const { theme, toggleTheme } = useTheme();
const { isCollapsed, collapseSidebar, expandSidebar } = useSidebar();
let desktopMedia;
const closeMobileOnDesktop = () => { if (desktopMedia?.matches) mobileSidebarOpen.value = false; };
onMounted(() => { desktopMedia = window.matchMedia('(min-width: 1024px)'); desktopMedia.addEventListener('change', closeMobileOnDesktop); });
onBeforeUnmount(() => desktopMedia?.removeEventListener('change', closeMobileOnDesktop));

const page = usePage();
watch(() => page.url, () => { mobileSidebarOpen.value = false; });
const permissions = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => canUse(permissions.value, module, action);

const SIDEBAR_GROUPS_STORAGE_KEY = 'sidebar_open_groups';

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
    machines: [
        'M4 7h16v10H4z',
        'M7 10h6',
        'M7 13h3',
        'M16 10h1',
        'M16 13h1',
        'M7 17v2',
        'M17 17v2',
    ],
    companies: [
        'M4 20h16',
        'M6 20V7l6-3 6 3v13',
        'M9 10h.01',
        'M12 10h.01',
        'M15 10h.01',
        'M9 14h.01',
        'M12 14h.01',
        'M15 14h.01',
    ],
    users: [
        'M16 11c1.657 0 3-1.567 3-3.5S17.657 4 16 4s-3 1.567-3 3.5S14.343 11 16 11z',
        'M8 11c1.657 0 3-1.567 3-3.5S9.657 4 8 4 5 5.567 5 7.5 6.343 11 8 11z',
        'M3 20v-1c0-2.761 2.239-5 5-5c2.761 0 5 2.239 5 5v1',
        'M13 20v-1c0-2.075 1.567-4 3.5-4H17c1.933 0 3.5 1.925 3.5 4v1',
    ],
    settings: [
        'M12 8a4 4 0 100 8 4 4 0 000-8z',
        'M4.93 6.37l1.42 1.42',
        'M17.66 19.07l1.42 1.42',
        'M3 12h2',
        'M19 12h2',
        'M4.93 17.63l1.42-1.42',
        'M17.66 4.93l1.42-1.42',
        'M12 3v2',
        'M12 19v2',
    ],
    audit: [
        'M5 5h14v14H5z',
        'M9 9h6v6H9z',
        'M5 12h4',
        'M15 12h4',
        'M12 5v4',
        'M12 15v4',
    ],
    attendance: [
        'M4 7h16',
        'M4 12h16',
        'M4 17h16',
        'M8 4v16',
    ],
};

const currentYear = new Date().getFullYear();

const isActive = (item) => {
    if (!item.routeName) return false;
    if (!route().current(item.routeName)) return false;
    const url = new URL(page.url, 'http://localhost');
    if (item.hash) return url.hash === '#' + item.hash;
    if (item.routeName === 'vending-fleet.dashboard') return url.hash !== '#alertas';
    if (item.routeName === 'vending-machines.index') return (url.searchParams.get('catalog_view') || 'operational') === (item.params?.catalog_view || 'operational');
    if (item.routeName === 'field-identity.admin.index') return (url.searchParams.get('tab') || 'summary') === (item.params?.tab || 'summary');
    return true;
};

const resolveHref = (item) => (item.routeName ? route(item.routeName, item.params ?? {}) + (item.hash ? '#' + item.hash : '') : '#');

const visibleNavGroups = computed(() => visibleNavigation(permissions.value));

const flatNavItems = computed(() => visibleNavGroups.value.flatMap((group) => group.items));

const readStoredOpenGroups = () => {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const stored = window.localStorage.getItem(SIDEBAR_GROUPS_STORAGE_KEY);
        if (!stored) return {};
        const parsed = JSON.parse(stored);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
};

const openGroups = ref(readStoredOpenGroups());

const persistOpenGroups = () => {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(
        SIDEBAR_GROUPS_STORAGE_KEY,
        JSON.stringify(openGroups.value),
    );
};

const isGroupActive = (group) => group.items.some((item) => isActive(item));

const isGroupOpen = (group) => {
    if (Object.prototype.hasOwnProperty.call(openGroups.value, group.key)) {
        return Boolean(openGroups.value[group.key]);
    }

    return true;
};

const toggleGroup = (groupKey) => {
    const group = visibleNavGroups.value.find((entry) => entry.key === groupKey);
    if (!group) {
        return;
    }

    openGroups.value = {
        ...openGroups.value,
        [groupKey]: !isGroupOpen(group),
    };
    persistOpenGroups();
};

const navItemClasses = (item, options = {}) => {
    const classes = ['sidebar-item'];

    if (options.collapsed) {
        classes.push('sidebar-item-collapsed');
    }

    if (isActive(item)) {
        classes.push('is-active');
    }

    if (!item.routeName) {
        classes.push('opacity-70');
    }

    return classes;
};

const groupHeaderClasses = (group) => [
    'sidebar-group-header',
    isGroupOpen(group) ? 'is-open' : '',
];

const groupChevronClasses = (group) => [
    'sidebar-group-chevron',
    isGroupOpen(group) ? 'is-open' : '',
];

watch(
    visibleNavGroups,
    (groups) => {
        const nextState = {};

        groups.forEach((group) => {
            const hasStoredState = Object.prototype.hasOwnProperty.call(openGroups.value, group.key);
            nextState[group.key] = hasStoredState
                ? Boolean(openGroups.value[group.key])
                : true;

            // Open active group on init/route render, but allow manual collapse afterwards.
            if (isGroupActive(group)) {
                nextState[group.key] = true;
            }
        });

        openGroups.value = nextState;
        persistOpenGroups();
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <div class="min-h-screen overflow-x-clip bg-app text-app transition-colors duration-300">
        <div class="flex min-h-screen overflow-x-clip">
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
                        'sidebar-scroll mt-10 flex-1',
                        isCollapsed ? 'space-y-3 text-center text-xs' : 'space-y-4 text-sm',
                    ]"
                >
                    <template v-if="isCollapsed">
                        <component
                            :is="item.external ? 'a' : Link"
                            v-for="item in flatNavItems"
                            :key="item.label"
                            :href="resolveHref(item)"
                            :class="navItemClasses(item, { collapsed: true })"
                            :title="item.label"
                            :aria-disabled="!item.routeName"
                        >
                            <div class="flex w-full flex-col items-center gap-2 text-xs">
                                <span class="sidebar-item-icon">
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
                            </div>
                        </component>
                    </template>

                    <template v-else>
                        <section
                            v-for="group in visibleNavGroups"
                            :key="group.key"
                            class="sidebar-group"
                        >
                            <button
                                type="button"
                                :class="groupHeaderClasses(group)"
                                @click="toggleGroup(group.key)"
                            >
                                <span class="sidebar-group-title">
                                    {{ group.title }}
                                </span>
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    :class="groupChevronClasses(group)"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <Transition
                                enter-active-class="transition-all duration-200 ease-out"
                                enter-from-class="max-h-0 opacity-0"
                                enter-to-class="max-h-96 opacity-100"
                                leave-active-class="transition-all duration-150 ease-in"
                                leave-from-class="max-h-96 opacity-100"
                                leave-to-class="max-h-0 opacity-0"
                            >
                                <div v-show="isGroupOpen(group)" class="sidebar-group-panel">
                                    <div class="sidebar-group-items">
                                        <component
                                            :is="item.external ? 'a' : Link"
                                            v-for="item in group.items"
                                            :key="item.label"
                                            :href="resolveHref(item)"
                                            :class="navItemClasses(item)"
                                            :aria-disabled="!item.routeName"
                                        >
                                            <span class="sidebar-item-icon">
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
                                            <div class="sidebar-item-content">
                                                <span class="sidebar-item-label">{{ item.label }}</span>
                                                <span class="sidebar-item-description" v-text="item.description" />
                                            </div>
                                        </component>
                                    </div>
                                </div>
                            </Transition>
                        </section>
                    </template>
                </nav>

            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 border-b border-app bg-white/90 backdrop-blur  dark:bg-slate-900/80">
                    <div class="flex min-h-16 min-w-0 items-center justify-between gap-2 px-3 py-2 sm:px-6 lg:px-10">
                        <div class="flex min-w-0 flex-1 items-center gap-2 sm:gap-4">
                            <button
                                type="button"
                                class="rounded-2xl border border-app p-2 text-soft lg:hidden"
                                aria-label="Abrir menu lateral"
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

                            <div class="min-w-0 flex-1 overflow-hidden">
                                <div class="page-header-slot min-w-0 text-base font-semibold text-app sm:text-xl">
                                    <slot name="header">
                                        Panel de control
                                    </slot>
                                </div>
                            </div>
                        </div>

                        <div class="shrink-0 flex items-center gap-2 sm:gap-4">
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
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 rounded-full border border-app bg-white px-2 py-1.5 text-sm font-medium text-muted shadow-sm hover:text-app  dark:bg-slate-900  dark:hover:text-white sm:px-3"
                                        aria-label="Abrir menu de usuario"
                                    >
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

                <main class="flex-1 min-w-0 px-3 py-6 sm:px-6 sm:py-8 lg:px-10">
                    <div
                        :class="[
                            'card p-4 sm:p-6',
                            props.contentOverflowVisible ? 'overflow-visible' : 'overflow-hidden',
                        ]"
                    >
                        <slot />
                    </div>
                </main>

                <footer class="border-t border-app bg-white/80 px-3 py-4 text-xs text-soft  dark:bg-slate-900/80 sm:px-6 lg:px-10">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <p>&copy; {{ currentYear }} Medical Life · Vending Attendance.</p>
                        <p class="text-[11px] uppercase tracking-[0.3em] text-soft dark:text-soft">
                            Datos protegidos
                        </p>
                    </div>
                </footer>
            </div>
        </div>

        <Modal :show="mobileSidebarOpen" max-width="sm" aria-label="Menú principal" @close="mobileSidebarOpen = false">
            <div>
                <div class="sidebar-mobile-sheet">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <ApplicationLogo class="h-8 w-8 text-indigo-600" />
                            <p class="text-base font-semibold text-app">Vending Attendance</p>
                        </div>
                        <button class="rounded-full border border-app p-2" aria-label="Cerrar menu lateral" @click="mobileSidebarOpen = false">
                            <span class="sr-only">Cerrar menu</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <nav class="mt-8 space-y-4 pb-6">
                        <section
                            v-for="group in visibleNavGroups"
                            :key="`mobile-${group.key}`"
                            class="sidebar-group"
                        >
                            <button
                                type="button"
                                :class="groupHeaderClasses(group)"
                                @click="toggleGroup(group.key)"
                            >
                                <span class="sidebar-group-title">
                                    {{ group.title }}
                                </span>
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    :class="groupChevronClasses(group)"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <Transition
                                enter-active-class="transition-all duration-200 ease-out"
                                enter-from-class="max-h-0 opacity-0"
                                enter-to-class="max-h-96 opacity-100"
                                leave-active-class="transition-all duration-150 ease-in"
                                leave-from-class="max-h-96 opacity-100"
                                leave-to-class="max-h-0 opacity-0"
                            >
                                <div v-show="isGroupOpen(group)" class="sidebar-group-panel">
                                    <div class="sidebar-group-items">
                                        <component
                                            :is="item.external ? 'a' : Link"
                                            v-for="item in group.items"
                                            :key="`mobile-${group.key}-${item.label}`"
                                            :href="resolveHref(item)"
                                            :class="navItemClasses(item)"
                                            :aria-disabled="!item.routeName"
                                            @click="mobileSidebarOpen = false"
                                        >
                                            <span class="sidebar-item-icon">
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
                                            <div class="sidebar-item-content">
                                                <span class="sidebar-item-label">{{ item.label }}</span>
                                                <span class="sidebar-item-description" v-text="item.description" />
                                            </div>
                                        </component>
                                    </div>
                                </div>
                            </Transition>
                        </section>
                    </nav>
                </div>

            </div>
        </Modal>
    </div>
</template>
