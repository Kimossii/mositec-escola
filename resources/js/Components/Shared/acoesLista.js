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
    editar: { icone: 'ki-user-edit', paths: 3, cor: 'primary', texto: 'Editar' },
    permissoes: { icone: 'ki-shield-tick', paths: 2, cor: 'success', texto: 'Permissões' },
    eliminar: { icone: 'ki-trash', paths: 5, cor: 'danger', texto: 'Eliminar' },
    ativar: { icone: 'ki-toggle-on-circle', paths: 2, cor: 'success', texto: 'Ativar' },
    desativar: { icone: 'ki-toggle-off-circle', paths: 2, cor: 'muted', texto: 'Desativar' },
    encerrar: { icone: 'ki-lock-2', paths: 5, cor: 'dark', texto: 'Encerrar' },
});
