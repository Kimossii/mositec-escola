import { computed } from 'vue';
import { podeVer } from './usePermissoes';

// Fonte única do conteúdo do dropdown "Académico" — usada tanto pelo
// próprio conteúdo (AcademicoMenu.vue) como pelo item do Header que o
// envolve (HeaderMenu.vue), para os dois concordarem sobre quando não há
// nada a mostrar (mesmo padrão do useConfiguracoesMenu.js).
const seccoesAcademico = [
    {
        title: 'Académico',
        links: [
            { href: '#', label: 'Alunos' },
            { href: '#', label: 'Encarregados de Educação' },
            { href: '#', label: 'Turmas' },
            { href: '#', label: 'Matrículas' },
            { href: '#', label: 'Transferências' },
            { href: '#', label: 'Histórico Escolar' },
            { href: '#', label: 'Ficha do Aluno' },
        ],
    },
];

export function useAcademicoMenu() {
    const seccoesVisiveis = computed(() =>
        seccoesAcademico
            .map((s) => ({ ...s, links: s.links.filter(podeVer) }))
            .filter((s) => s.links.length > 0)
    );

    return { seccoesVisiveis };
}
