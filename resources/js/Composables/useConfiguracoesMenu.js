import { computed } from 'vue';
import { podeVer } from './usePermissoes';

// Fonte única dos grupos de "Configurações" — usada tanto pelo conteúdo do
// dropdown (ConfiguracoesMenu.vue) como pelo item do Header que o envolve
// (HeaderMenu.vue), para os dois concordarem sobre quando não há nada a
// mostrar. Duplicar esta lista nos dois sítios é como o item do Header
// acabou por ficar sempre visível mesmo com o dropdown vazio.
const gruposConfiguracoes = [
    {
        title: 'Utilizadores',
        links: [
            { href: '/usuarios', label: 'Todos os Utilizadores', permissao: 'usuario.ver' },
            { href: '/usuarios/alunos', label: 'Alunos', permissao: 'usuario.ver' },
            { href: '/usuarios/professores', label: 'Professores', permissao: 'usuario.ver' },
            { href: '/usuarios/funcionarios', label: 'Funcionários', permissao: 'usuario.ver' },
            { href: '/usuarios/administradores', label: 'Administradores', permissao: 'autorizacao.ver' },
            { href: '/usuarios/encarregados', label: 'Encarregados', permissao: 'usuario.ver' },
        ],
    },
    {
        title: 'Perfis & Permissões',
        links: [
            { href: '/permissoes/perfis', label: 'Perfis e Permissões', permissao: 'autorizacao.ver' },
        ],
    },
    {
        title: 'Estabelecimento',
        links: [
            { href: '/estabelecimento', label: 'Dados da Escola', permissao: 'estabelecimento.ver' },
            { href: '/estabelecimento/aparencia', label: 'Logótipo & Aparência', permissao: 'estabelecimento.ver' },
        ],
    },
    {
        title: 'Ano Lectivo',
        links: [
            { href: '/ano-lectivos', label: 'Anos Lectivos', permissao: 'ano-lectivo.ver' },
        ],
    },
    // Por implementar — descomentar quando o módulo Sistema existir:
    // {
    //     title: 'Sistema',
    //     links: [
    //         { href: '#', label: 'Parâmetros Gerais' },
    //         { href: '#', label: 'Sessões Activas' },
    //         { href: '#', label: 'Histórico de Logins' },
    //         { href: '#', label: 'Backups' },
    //         { href: '#', label: 'Registos de Actividade' },
    //     ],
    // },
];

export function useConfiguracoesMenu() {
    const gruposVisiveis = computed(() =>
        gruposConfiguracoes
            .map((group) => ({ ...group, links: group.links.filter(podeVer) }))
            .filter((group) => group.links.length > 0)
    );

    return { gruposVisiveis };
}
