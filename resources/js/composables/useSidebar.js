import { ref } from 'vue';

const STORAGE_KEY = 'sidebar_collapsed';
const isCollapsed = ref(false);
let initialized = false;

const applyState = (value) => {
    isCollapsed.value = value;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
    }
};

const ensureInit = () => {
    if (initialized) {
        return;
    }

    initialized = true;

    if (typeof window === 'undefined') {
        return;
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored === '1' || stored === '0') {
        isCollapsed.value = stored === '1';
    }
};

export const useSidebar = () => {
    ensureInit();

    const collapseSidebar = () => applyState(true);
    const expandSidebar = () => applyState(false);
    const toggleSidebar = () => applyState(!isCollapsed.value);

    return {
        isCollapsed,
        toggleSidebar,
        collapseSidebar,
        expandSidebar,
    };
};
