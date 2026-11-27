import { computed } from 'vue';
import { podeVer } from './usePermissoes';

// Fonte única do dropdown "Sistema" (o espelho do grupo «Sistema» da sidebar) — usada tanto pelo próprio
// conteúdo (SistemaMenu.vue) como pelo item do Header que o envolve (HeaderMenu.vue).
const seccoesSistema = [
    {
        title: 'Sistema',
        links: [
            { href: '/licenca', label: 'Licença', soAdministrador: true },
            { href: '/acessos-documentos', label: 'Acessos a documentos', permissao: 'acesso-documento-pessoa.ver' },
            { href: '/auditoria', label: 'Auditoria', permissao: 'auditoria.ver' },
            { href: '/envios-email', label: 'Envios de email', permissao: 'envio-email.ver' },
        ],
    },
];

export function useSistemaMenu() {
    const seccoesVisiveis = computed(() =>
        seccoesSistema
            .map((s) => ({ ...s, links: s.links.filter(podeVer) }))
            .filter((s) => s.links.length > 0)
    );

    return { seccoesVisiveis };
}
