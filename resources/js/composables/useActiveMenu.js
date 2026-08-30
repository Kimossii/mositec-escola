import { usePage } from '@inertiajs/vue3'

export function useActiveMenu() {
    const page = usePage()

    // exact: true evita que uma rota "índice" (ex: /usuarios) fique marcada
    // como ativa quando na verdade estamos numa rota filha (/usuarios/alunos)
    // — sem isso, /usuarios é prefixo literal de todas as outras do grupo.
    function isActive(href, { exact = false } = {}) {
        if (!href || !href.startsWith('/')) return false
        const path = page.url.split('?')[0]
        if (href === '/' || exact) return path === href
        return path === href || path.startsWith(href + '/')
    }

    function isGroupActive(items) {
        return items.some((item) => isActive(item.href))
    }

    return { isActive, isGroupActive }
}
