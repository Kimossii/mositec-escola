import { onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Vista de uma lista (`lista` por defeito, ou `cards`) e estado de carregamento.
 * - A escolha fica guardada no browser, por utilizador e por lista (`chave`).
 * - `carregando` fica verdadeiro quando uma pesquisa, filtro ou página (visita GET completa a `prefixo…`) demora mais de
 *   150 ms, para a lista mostrar esqueletos sem piscar nos pedidos rápidos. O carregamento diferido (`only`) não conta.
 */
export function useVistaDeLista(chave, prefixo) {
    const chaveGuardada = `mosi:vista:${chave}:${usePage().props.auth.user?.id ?? 'anon'}`;

    let inicial = 'lista';
    try {
        inicial = localStorage.getItem(chaveGuardada) === 'cards' ? 'cards' : 'lista';
    } catch {
        // sem armazenamento (modo privado): lista
    }

    const vista = ref(inicial);
    watch(vista, (valor) => {
        try {
            localStorage.setItem(chaveGuardada, valor);
        } catch {
            // a escolha vale só nesta página
        }
    });

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
