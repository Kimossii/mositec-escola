/**
 * A que menu do sistema pertence cada módulo de permissões (espelha o menu superior/lateral: Académico, Pedagógico,
 * Financeiro, Configurações). A chave é o `slug` do módulo (o `nome` na BD é o inteiro do enum). Módulo sem entrada aqui cai em «Outros».
 */
export const ORDEM_DOS_MENUS = ['Académico', 'Pedagógico', 'Financeiro', 'Infraestrutura', 'Configurações', 'Sistema', 'Outros'];

export const MENU_DE = {
    aluno: { menu: 'Académico', entrada: 'Alunos' },
    curso: { menu: 'Académico', entrada: 'Cursos' },
    'plano-curricular': { menu: 'Académico', entrada: 'Planos curriculares' },
    turmas: { menu: 'Académico', entrada: 'Turmas' },
    matricula: { menu: 'Académico', entrada: 'Matrículas' },
    professor: { menu: 'Académico', entrada: 'Professores' },

    disciplina: { menu: 'Pedagógico', entrada: 'Disciplinas' },
    horario: { menu: 'Pedagógico', entrada: 'Horários' },
    nota: { menu: 'Pedagógico', entrada: 'Avaliações / Notas' },

    propina: { menu: 'Financeiro', entrada: 'Propinas' },
    pagamento: { menu: 'Financeiro', entrada: 'Pagamentos' },
    cobranca: { menu: 'Financeiro', entrada: 'Cobranças' },
    bolsa: { menu: 'Financeiro', entrada: 'Bolsas' },
    acordo: { menu: 'Financeiro', entrada: 'Acordos de dívida' },

    usuario: { menu: 'Configurações', secao: 'Utilizadores', entrada: 'Todos os Utilizadores' },
    'senha-utilizador': { menu: 'Configurações', secao: 'Utilizadores', entrada: 'Redefinir senha' },
    'documento-pessoa': { menu: 'Configurações', secao: 'Utilizadores', entrada: 'Documentos pessoais' },
    autorizacao: { menu: 'Configurações', secao: 'Perfis & Permissões', entrada: 'Perfis e Permissões' },
    estabelecimento: { menu: 'Configurações', secao: 'Estabelecimento', entrada: 'Dados da Escola' },
    'ano-lectivo': { menu: 'Configurações', secao: 'Ano Lectivo', entrada: 'Anos Lectivos' },
    'moeda-cambio': { menu: 'Configurações', secao: 'Financeiro', entrada: 'Moeda e Câmbio' },
    'plano-propina': { menu: 'Configurações', secao: 'Financeiro', entrada: 'Planos de Propina' },
    'pausa-cobranca': { menu: 'Configurações', secao: 'Financeiro', entrada: 'Pausas de Cobrança' },
    'regra-cobranca': { menu: 'Configurações', secao: 'Financeiro', entrada: 'Regras de Cobrança' },
    'metodo-pagamento': { menu: 'Configurações', secao: 'Financeiro', entrada: 'Métodos de Pagamento' },
    'catalogo-financeiro': { menu: 'Configurações', secao: 'Financeiro', entrada: 'Produtos / Serviços' },

    // Menu próprio na sidebar (Salas hoje; equipamentos, inventário e manutenção entram depois no mesmo módulo de permissões).
    infraestrutura: { menu: 'Infraestrutura', entrada: 'Salas' },

    licenca: { menu: 'Sistema', entrada: 'Licenças' },
};

/**
 * Agrupa os módulos por menu (e, em Configurações, por secção), pela ordem do menu.
 *
 * @returns {Array<{ menu: string, secoes: Array<{ titulo: string|null, modulos: Array }> }>}
 */
export function agruparPorMenu(modulos) {
    const menus = new Map(ORDEM_DOS_MENUS.map((m) => [m, new Map()]));

    for (const modulo of modulos) {
        const info = MENU_DE[modulo.slug] ?? { menu: 'Outros', entrada: modulo.descricao };
        const secoes = menus.get(info.menu);
        const chave = info.secao ?? '';

        if (!secoes.has(chave)) secoes.set(chave, []);
        secoes.get(chave).push({ ...modulo, entrada: info.entrada, caminho: [info.menu, info.secao, info.entrada].filter(Boolean).join(' › ') });
    }

    return [...menus.entries()]
        .filter(([, secoes]) => secoes.size > 0)
        .map(([menu, secoes]) => ({
            menu,
            secoes: [...secoes.entries()].map(([titulo, mods]) => ({ titulo: titulo || null, modulos: mods })),
        }));
}
