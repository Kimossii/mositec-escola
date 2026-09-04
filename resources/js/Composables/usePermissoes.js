import { usePage } from '@inertiajs/vue3';

export function can(permissao) {
    return usePage().props.permissoes.includes(permissao);
}

// Filtro único para listas de links de menu: um link sem `permissao`
// é sempre visível (ex.: placeholders "#"); um link com `permissao`
// só aparece se can() a conceder. Mesma fonte de verdade do backend,
// nunca um segundo sistema de autorização no frontend.
export function podeVer(link) {
    return !link.permissao || can(link.permissao);
}
