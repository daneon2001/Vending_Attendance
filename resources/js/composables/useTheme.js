import { ref } from 'vue';

const THEME_KEY = 'theme';
const theme = ref('light');

const applyTheme = (value) => {
    theme.value = value;

    if (typeof document !== 'undefined') {
        const html = document.documentElement;
        if (value === 'dark') {
            html.classList.add('dark');
        } else {
            html.classList.remove('dark');
        }
    }

    if (typeof window !== 'undefined') {
        window.localStorage.setItem(THEME_KEY, value);
    }
};

export const initTheme = () => {
    if (typeof window === 'undefined') {
        return;
    }

    const savedTheme = window.localStorage.getItem(THEME_KEY);
    if (savedTheme === 'dark' || savedTheme === 'light') {
        applyTheme(savedTheme);
        return;
    }

    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(prefersDark ? 'dark' : 'light');
};

export const useTheme = () => {
    const toggleTheme = () => {
        applyTheme(theme.value === 'dark' ? 'light' : 'dark');
    };

    const setTheme = (value) => {
        if (value === 'dark' || value === 'light') {
            applyTheme(value);
        }
    };

    return {
        theme,
        toggleTheme,
        setTheme,
    };
};
