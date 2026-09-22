import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

const normalizeBasePath = (value = '') => {
    const trimmed = String(value).trim().replace(/^\/+|\/+$/g, '');
    return trimmed ? `/${trimmed}` : '';
};

const resolveBuildBase = (value = '') => {
    const basePath = normalizeBasePath(value);
    return `${basePath}/build/`.replace(/\/{2,}/g, '/');
};

export default defineConfig(({ command, mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appBasePath = mode === 'beta' ? '' : env.VITE_APP_BASE_PATH || env.APP_BASE_PATH || '';

    return {
        // Public beta web calls its own origin. Never bake a local .env URL.
        define: mode === 'beta' ? { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('') } : {},
        base: command === 'build' ? resolveBuildBase(appBasePath) : '/',
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/select-enhancer-entry.js'],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
    };
});
