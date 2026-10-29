import { computed } from 'vue';
import { podeVer } from './usePermissoes';

// Fonte única do conteúdo do dropdown "Pessoal" (o espelho do grupo «Pessoal» da sidebar) — usada tanto pelo
// próprio conteúdo (PessoalMenu.vue) como pelo item do Header que o envolve (HeaderMenu.vue).
const seccoesPessoal = [
    {
        title: 'Pessoal',
        links: [
            { href: '/docentes', label: 'Docentes', exact: true, permissao: 'docente.ver' },
            { href: '/docentes/atribuicoes', label: 'Atribuições docentes', permissao: 'atribuicao-docente.ver' },
            // { href: '#', label: 'Funcionários' },
            // { href: '#', label: 'Assiduidade do Pessoal' },
            // { href: '#', label: 'Horários do Pessoal' },
        ],
    },
];

export function usePessoalMenu() {
    const seccoesVisiveis = computed(() =>
        seccoesPessoal
            .map((s) => ({ ...s, links: s.links.filter(podeVer) }))
            .filter((s) => s.links.length > 0)
    );

    return { seccoesVisiveis };
}
