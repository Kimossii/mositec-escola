import { usePage } from '@inertiajs/vue3'

export function useActiveMenu() {
    const page = usePage()

    function isActive(href) {
        if (!href || !href.startsWith('/')) return false
        const path = page.url.split('?')[0]
        return href === '/' ? path === '/' : path === href || path.startsWith(href + '/')
    }

    function isGroupActive(items) {
        return items.some((item) => isActive(item.href))
    }

    return { isActive, isGroupActive }
}
