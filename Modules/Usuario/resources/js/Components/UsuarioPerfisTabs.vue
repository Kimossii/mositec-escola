<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { can } from '@/Composables/usePermissoes';

/** Separadores por perfil com a contagem de cada um (as contagens chegam diferidas e entram quando prontas). */
const props = defineProps({
    contagens: { type: Object, default: null }, // indicadores.por_perfil (inclui `todos`)
});

const pagina = usePage();
const caminho = computed(() => new URL(pagina.url, window.location.origin).pathname.replace(/\/$/, ''));

const separadores = computed(() => [
    { texto: 'Todos', href: '/usuarios', chave: null, icone: 'ki-people' },
    { texto: 'Alunos', href: '/usuarios/alunos', chave: 'alunos', icone: 'ki-profile-user' },
    { texto: 'Professores', href: '/usuarios/professores', chave: 'professores', icone: 'ki-teacher' },
    { texto: 'Funcionários', href: '/usuarios/funcionarios', chave: 'funcionarios', icone: 'ki-briefcase' },
    { texto: 'Encarregados', href: '/usuarios/encarregados', chave: 'encarregados', icone: 'ki-shield-tick' },
    { texto: 'Administradores', href: '/usuarios/administradores', chave: 'administradores', icone: 'ki-security-user', permissao: 'autorizacao.ver' },
].filter((s) => !s.permissao || can(s.permissao)));

const quantidade = (s) => props.contagens?.[s.chave ?? 'todos'] ?? null;
</script>

<template>
    <div class="d-flex flex-wrap gap-2 mb-6">
        <Link
            v-for="s in separadores"
            :key="s.href"
            :href="s.href"
            class="btn btn-sm d-flex align-items-center"
            :class="caminho === s.href ? 'btn-success' : 'btn-light'"
        >
            <i class="ki-duotone fs-4 me-2" :class="s.icone"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
            {{ s.texto }}
            <span v-if="quantidade(s) !== null" class="badge ms-2" :class="caminho === s.href ? 'badge-light-success bg-white text-success' : 'badge-light'">{{ quantidade(s) }}</span>
            <span v-else class="placeholder-glow ms-2"><span class="placeholder rounded px-3"></span></span>
        </Link>
    </div>
</template>
