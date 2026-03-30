import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initTheme } from './composables/useTheme';
import { resolveZiggyConfig } from './utils/url';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

initTheme();

const ziggyConfig = resolveZiggyConfig();

if (ziggyConfig) {
    globalThis.Ziggy = ziggyConfig;
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin);

        if (ziggyConfig) {
            app.use(ZiggyVue, ziggyConfig);
        } else {
            app.use(ZiggyVue);
        }

        return app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
