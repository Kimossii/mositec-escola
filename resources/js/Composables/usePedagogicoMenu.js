import { computed } from 'vue';
import { podeVer } from './usePermissoes';

// Fonte única do conteúdo do dropdown "Pedagógico" — usada tanto pelo
// próprio conteúdo (PedagogicoMenu.vue) como pelo item do Header que o
// envolve (HeaderMenu.vue), para os dois concordarem sobre quando não há
// nada a mostrar (mesmo padrão do useConfiguracoesMenu.js).
const seccoesPedagogico = [
    {
        title: 'Pedagógico',
        links: [
            { href: '#', label: 'Disciplinas' },
            { href: '/horarios', label: 'Horários', permissao: 'horario.ver' },
            { href: '#', label: 'Planos de Aula' },
            { href: '#', label: 'Avaliações / Notas' },
            { href: '#', label: 'Pautas' },
            { href: '#', label: 'Conselho de Turma' },
            { href: '#', label: 'Exames / Recuperações' },
        ],
    },
];

export function usePedagogicoMenu() {
    const seccoesVisiveis = computed(() =>
        seccoesPedagogico
            .map((s) => ({ ...s, links: s.links.filter(podeVer) }))
            .filter((s) => s.links.length > 0)
    );

    return { seccoesVisiveis };
}
