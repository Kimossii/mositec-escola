import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Vista de uma lista (`lista` por defeito, ou `cards`) e estado de carregamento.
 * - A escolha fica guardada no browser, por utilizador e por lista (`chave`).
 * - `carregando` fica verdadeiro quando uma pesquisa, filtro ou página (visita GET completa a `prefixo…`) demora mais de
 *   150 ms, para a lista mostrar esqueletos sem piscar nos pedidos rápidos. O carregamento diferido (`only`) não conta.
 */
export function useVistaDeLista(chave, prefixo) {
    const chaveGuardada = `mosi:vista:${chave}:${usePage().props.auth.user?.id ?? 'anon'}`;

    // Sem escolha guardada: cards no telemóvel (cabem no ecrã), lista nos restantes.
    let inicial = window.matchMedia?.('(max-width: 767.98px)').matches ? 'cards' : 'lista';
    try {
        const guardada = localStorage.getItem(chaveGuardada);
        if (guardada === 'cards' || guardada === 'lista') inicial = guardada;
    } catch {
        // sem armazenamento (modo privado): vale o defeito
    }

    const vista = ref(inicial);
    watch(vista, (valor) => {
        try {
            localStorage.setItem(chaveGuardada, valor);
        } catch {
            // a escolha vale só nesta página
        }
    });
    // Trocar de vista recria os elementos da lista: os menus «Ações» novos ficam sem o clique ligado pelo KTMenu (e o
    // clique cairia no `href="#"`, que leva ao topo) até serem reinicializados. Seguro repetir: ignora os já ligados.
    watch(vista, () => nextTick(() => window.KTMenu?.init()), { flush: 'post' });

    const carregando = ref(false);
    let temporizador = null;
    const removerInicio = router.on('start', (evento) => {
        const visita = evento.detail.visit;
        if (!visita.url.pathname.startsWith(prefixo) || visita.method !== 'get' || visita.only?.length) return;
        temporizador = setTimeout(() => { carregando.value = true; }, 150);
    });
    const removerFim = router.on('finish', () => {
        clearTimeout(temporizador);
        carregando.value = false;
    });
    onBeforeUnmount(() => {
        removerInicio();
        removerFim();
        clearTimeout(temporizador);
    });

    return { vista, carregando };
}
