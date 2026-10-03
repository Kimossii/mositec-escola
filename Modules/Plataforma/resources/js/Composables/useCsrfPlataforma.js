import { onBeforeUnmount } from 'vue';
import { http, usePage } from '@inertiajs/vue3';

// Envia SEMPRE o token CSRF do painel em X-CSRF-TOKEN (partilhado como prop `csrf_token` pelo
// HandleInertiaPlataforma). O Laravel verifica X-CSRF-TOKEN antes de X-XSRF-TOKEN: com SESSION_DOMAIN
// de domínio-pai, o cookie XSRF-TOKEN da escola chega ao host do painel e o cliente HTTP do Inertia
// (que envia o cookie como X-XSRF-TOKEN) podia usar o errado, dando 419 intermitente.
//
// http.onRequest corre em TODOS os pedidos do Inertia (visitas, router.post/put, formulários). O token
// lê-se da página actual no momento do pedido, por isso acompanha as renovações (login, logout).
// Chamar uma só vez por árvore de páginas: no LayoutPlataforma e na página de Login (que não o usa).
export function useCsrfPlataforma() {
    const page = usePage();

    const deixarDeEnviar = http.onRequest((config) => {
        const token = page.props.csrf_token;

        if (token) {
            config.headers = { ...config.headers, 'X-CSRF-TOKEN': token };
        }

        return config;
    });

    onBeforeUnmount(deixarDeEnviar);
}
