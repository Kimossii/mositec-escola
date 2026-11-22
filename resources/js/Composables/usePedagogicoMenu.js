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
            { href: '/disciplinas', label: 'Disciplinas', permissao: 'disciplina.ver' },
            { href: '/horarios', label: 'Horários', permissao: 'horario.ver' },
            { href: '/grelha-horaria', label: 'Grelha de horários', permissao: 'grelha-horaria.ver' },
            // { href: '#', label: 'Planos de Aula' },
            { href: '/avaliacao/notas', label: 'Avaliações / Notas', modulo: 'avaliacao', permissao: 'nota.ver' },
            { href: '/avaliacao/configuracao', label: 'Configuração da avaliação', modulo: 'avaliacao', permissao: 'nota.configurar' },
            { href: '/avaliacao/pautas', label: 'Pautas', modulo: 'avaliacao', permissao: 'nota.ver-pauta' },
            { href: '/minhas-notas', label: 'As minhas notas', modulo: 'avaliacao', permissao: 'nota.ver-proprias' },
            // { href: '#', label: 'Conselho de Turma' },
            // { href: '#', label: 'Exames / Recuperações' },
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
