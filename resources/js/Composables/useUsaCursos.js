import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// A escola trabalha com cursos? Vem do servidor (etapas de ensino declaradas); só um `false` explícito esconde,
// por isso uma página sem a prop continua a mostrar tudo.
export function useUsaCursos() {
    const page = usePage();

    return computed(() => page.props.usa_cursos !== false);
}
