import { usePage } from '@inertiajs/vue3';

export function can(permissao) {
    return usePage().props.permissoes.includes(permissao);
}
