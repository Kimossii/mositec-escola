/**
 * Fonte única de verdade para as ações comuns às listas da app (Editar,
 * Permissões, Eliminar, ...). Cada entrada define o ícone Keenicons (e
 * quantos `path` — o número de camadas do duotone varia por ícone), a cor
 * semântica e o texto. Usado por `AcaoIcone.vue`; o `cor` também pode ser
 * lido diretamente por quem quiser colorir o botão/link à volta do ícone
 * (ex: `btn-light-${ACOES_LISTA.editar.cor}`).
 */
export const ACOES_LISTA = Object.freeze({
    visualizar: { icone: 'ki-eye', paths: 3, cor: 'info', texto: 'Visualizar' },
    editar: { icone: 'ki-pencil', paths: 2, cor: 'primary', texto: 'Editar' },
    'alterar-preco': { icone: 'ki-dollar', paths: 3, cor: 'warning', texto: 'Alterar preço' },
    permissoes: { icone: 'ki-shield-tick', paths: 2, cor: 'success', texto: 'Permissões' },
    redefinirSenha: { icone: 'ki-key', paths: 2, cor: 'warning', texto: 'Redefinir senha' },
    remover: { icone: 'ki-trash', paths: 5, cor: 'danger', texto: 'Remover' },
    eliminar: { icone: 'ki-trash', paths: 5, cor: 'danger', texto: 'Eliminar' },
    ativar: { icone: 'ki-toggle-on-circle', paths: 2, cor: 'success', texto: 'Ativar' },
    desativar: { icone: 'ki-toggle-off-circle', paths: 2, cor: 'muted', texto: 'Desativar' },
    encerrar: { icone: 'ki-lock-2', paths: 5, cor: 'dark', texto: 'Encerrar' },
    trancar: { icone: 'ki-lock', paths: 3, cor: 'warning', texto: 'Trancar' },
    reactivar: { icone: 'ki-arrows-circle', paths: 2, cor: 'success', texto: 'Reactivar' },
    renovar: { icone: 'ki-arrows-circle', paths: 2, cor: 'primary', texto: 'Renovar' },
    disciplinas: { icone: 'ki-book', paths: 4, cor: 'info', texto: 'Disciplinas' },
    concluir: { icone: 'ki-check-circle', paths: 2, cor: 'success', texto: 'Concluir' },
    cancelar: { icone: 'ki-cross-circle', paths: 2, cor: 'danger', texto: 'Cancelar' },
    manutencao: { icone: 'ki-setting-3', paths: 5, cor: 'warning', texto: 'Colocar em Manutenção' },
    descarregar: { icone: 'ki-cloud-download', paths: 2, cor: 'primary', texto: 'Descarregar' },
    partilhar: { icone: 'ki-share', paths: 3, cor: 'primary', texto: 'Partilhar' },
    exportar: { icone: 'ki-exit-up', paths: 2, cor: 'primary', texto: 'Exportar' },
    fechar: { icone: 'ki-cross', paths: 2, cor: 'muted', texto: 'Fechar' },
    adicionar: { icone: 'ki-plus', paths: 0, cor: 'primary', texto: 'Adicionar' },
    pesquisar: { icone: 'ki-magnifier', paths: 2, cor: 'muted', texto: 'Pesquisar' },
});
