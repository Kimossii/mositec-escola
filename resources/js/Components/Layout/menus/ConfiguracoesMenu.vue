<template>
    <div>
        <div
            v-for="group in gruposVisiveis"
            :key="group.title"
            data-kt-menu-trigger="click"
            class="menu-item menu-accordion menu-sub-indention"
        >
            <span class="menu-link">
                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                <span class="menu-title">{{ group.title }}</span>
                <span class="menu-arrow"></span>
            </span>
            <div class="menu-sub menu-sub-accordion">
                <div v-for="link in group.links" :key="link.href" class="menu-item">
                    <a class="menu-link" :href="link.href">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">{{ link.label }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { podeVer } from '@/Composables/usePermissoes'

const userManagementGroups = [
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
]

// Esconde o grupo todo quando nenhum dos seus links sobra depois do filtro
// (ex: Funcionário sem autorizacao.ver não vê o grupo "Perfis" de todo).
const gruposVisiveis = computed(() =>
    userManagementGroups
        .map((group) => ({ ...group, links: group.links.filter(podeVer) }))
        .filter((group) => group.links.length > 0)
)
</script>
