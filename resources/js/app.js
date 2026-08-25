import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';

const pages = {
    ...import.meta.glob('./Pages/**/*.vue'),
    ...import.meta.glob('/Modules/*/resources/js/Pages/**/*.vue'),
};

createInertiaApp({
    resolve: (name) => {
        const [module, ...rest] = name.split('/');
        const modulePath = `/Modules/${module}/resources/js/Pages/${rest.join('/')}.vue`;
        return resolvePageComponent([`./Pages/${name}.vue`, modulePath], pages);
    },
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => [
                h(App, props),
                h(Toaster, { position: 'top-right', richColors: true, closeButton: true }),
            ],
        })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
