<script setup>
import { onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePageScripts } from '@/composables/usePageScripts';
import UsuarioListLayout from '../Components/UsuarioListLayout.vue';
import ProfessorForm from '../Forms/Professores/ProfessorForm.vue';

defineProps({
    usuarios: { type: Object, required: true }, // paginator: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
});

const { loadAll } = usePageScripts([
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/export-users.js',
    '/themes/metronic/assets/js/widgets.bundle.js',
    '/themes/metronic/assets/js/components/custom/widgets.js',
    '/themes/metronic/assets/js/components/custom/apps/chat/chat.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/upgrade-plan.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/create-app.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/users-search.js',
]);

onMounted(() => {
    loadAll();
});
defineOptions({ layout: AppLayout })
</script>

<template>
    <UsuarioListLayout
        title="Professores"
        icon="ki-teacher"
        accent="success"
        :usuarios="usuarios"
        :filtros="filtros"
        :form-component="ProfessorForm"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
</template>
