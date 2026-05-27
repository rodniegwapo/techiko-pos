import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue, route as ziggyRoute } from '../../vendor/tightenco/ziggy';

import AntDesignVue from 'ant-design-vue';
import 'ant-design-vue/dist/antd.css'

import printNb from 'vue3-print-nb';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/** Ziggy defaults to absolute URLs; same document on 127.0.0.1:port with routes to APP_URL drops cookies on XHR → 419. */
function routeRelative(...args) {
    if (args.length === 0) {
        return ziggyRoute();
    }
    const [name, params, absolute, ziggyOverride] = args;
    const abs = typeof absolute !== 'undefined' ? absolute : false;

    return ziggyRoute(name, params, abs, ziggyOverride);
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(AntDesignVue)
            .use(ZiggyVue)
            .use(printNb);

        app.config.globalProperties.route = routeRelative;
        app.provide('route', routeRelative);

        return app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
