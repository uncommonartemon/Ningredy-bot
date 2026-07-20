import './bootstrap';
import '../css/app.css';
import '@fontsource-variable/manrope';
import '@fontsource-variable/space-grotesk';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';

createInertiaApp({
    title: (title) => (title ? `${title} — Ningredy` : 'Ningredy'),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: {
        color: '#5eead4',
        showSpinner: false,
    },
});
