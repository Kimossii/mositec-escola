import './bootstrap';
import { createApp, h, nextTick } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';

const pages = {
    ...import.meta.glob('./Pages/**/*.vue'),
    ...import.meta.glob('/Modules/*/resources/js/Pages/**/*.vue'),
};

// O KTMenu (dropdowns "Ações") só liga o clique do trigger aos elementos
// presentes no DOM no momento em que é chamado. Qualquer conteúdo que apareça
// depois — navegação Inertia para outra página, ou apenas uma linha de
// tabela recriada por reatividade após um post/put/delete — fica sem essa
// ligação, e o clique cai no comportamento nativo do <a href="#">. Reinicializar
// aqui após cada visita Inertia é seguro: createInstances()/initHandlers() já
// ignoram elementos já ligados.
function reinitMetronicJs() {
    nextTick(() => {
        if (window.KTMenu) window.KTMenu.init();
        if (window.KTDrawer) window.KTDrawer.init();
        if (window.KTToggle) window.KTToggle.init();
        if (window.KTScroll) window.KTScroll.init();
    });
}

router.on('finish', reinitMetronicJs);

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
