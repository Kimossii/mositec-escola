import { usePage } from '@inertiajs/vue3'

export function useActiveMenu() {
    const page = usePage()

    // exact: true evita que uma rota "índice" (ex: /usuarios) fique marcada
    // como ativa quando na verdade estamos numa rota filha (/usuarios/alunos)
    // — sem isso, /usuarios é prefixo literal de todas as outras do grupo.
    //
    // prefix: usa outro caminho (não o href de navegação) para decidir se a
    // rota actual "pertence" a este link — ex: "Perfis e Permissões" navega
    // para /permissoes/perfis, mas /permissoes/utilizadores/{id}/permissoes
    // (ver overrides de um utilizador) é a mesma área e devia acender o
    // mesmo link, mesmo não sendo prefixo literal do href.
    function isActive(href, { exact = false, prefix = null } = {}) {
        if (!href || !href.startsWith('/')) return false
        const path = page.url.split('?')[0]
        const base = prefix ?? href
        if (href === '/' || exact) return path === href
        return path === base || path.startsWith(base + '/')
    }

    function isGroupActive(items) {
        return items.some((item) => isActive(item.href, { exact: item.exact, prefix: item.activePrefix }))
    }

    return { isActive, isGroupActive }
}
