import { computed } from 'vue';
import { podeVer } from './usePermissoes';

// Fonte única do conteúdo do dropdown "Financeiro" (operação) — usada tanto pelo próprio conteúdo
// (FinanceiroMenu.vue) como pelo item do Header que o envolve (HeaderMenu.vue), para os dois
// concordarem sobre quando não há nada a mostrar (mesmo padrão do useAcademicoMenu.js). A configuração
// financeira continua em useConfiguracoesMenu.js. Manter igual à `const financeiro` da sidebar.
const seccoesFinanceiro = [
    {
        title: 'Financeiro',
        links: [
            { href: '/financeiro/propinas', label: 'Propinas', permissao: 'propina.ver' },
            { href: '/financeiro/pagamentos', label: 'Pagamentos', permissao: 'pagamento.ver' },
            // { href: '#', label: 'Dívidas' },
        ],
    },
];

export function useFinanceiroMenu() {
    const seccoesVisiveis = computed(() =>
        seccoesFinanceiro
            .map((s) => ({ ...s, links: s.links.filter(podeVer) }))
            .filter((s) => s.links.length > 0)
    );

    return { seccoesVisiveis };
}
