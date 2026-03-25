import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

const normalizeBasePath = (value = '') => {
    const raw = String(value ?? '').trim();

    if (raw === '' || raw === '/') {
        return '/';
    }

    const trimmed = raw.replace(/^\/+|\/+$/g, '');

    return trimmed === '' ? '/' : `/${trimmed}/`;
};

const resolveBasePath = (env) => {
    if (env.VITE_APP_BASE_PATH) {
        return normalizeBasePath(env.VITE_APP_BASE_PATH);
    }

    if (! env.APP_URL) {
        return '/';
    }

    try {
        return normalizeBasePath(new URL(env.APP_URL).pathname);
    } catch {
        return normalizeBasePath(env.APP_URL);
    }
};

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        base: resolveBasePath(env),
        plugins: [
            laravel({
                input: 'resources/js/app.js',
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
