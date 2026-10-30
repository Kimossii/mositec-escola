import { usePage } from '@inertiajs/vue3';

export function can(permissao) {
    return usePage().props.permissoes.includes(permissao);
}

// Filtro único para listas de links de menu: um link sem `permissao`
// é sempre visível (ex.: placeholders "#"); um link com `permissao`
// só aparece se can() a conceder. Mesma fonte de verdade do backend,
// nunca um segundo sistema de autorização no frontend.
// `soComCursos` esconde o link numa escola sem cursos (só Creche, Pré-Escolar ou Primário).
export function podeVer(link) {
    if (link.soComCursos && usePage().props.usa_cursos === false) return false;

    return !link.permissao || can(link.permissao);
}
