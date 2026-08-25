<script setup>
import { onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePageScripts } from '@/composables/usePageScripts';
import { useUsuarios } from '../Composables/useUsuarios';
import { TIPO_PESSOA } from '../Models/Usuario';
import UsuarioListLayout from '../Components/UsuarioListLayout.vue';
import ProfessorForm from '../Forms/Professores/ProfessorForm.vue';

const { usuarios } = useUsuarios({ tipoPessoa: TIPO_PESSOA.PROFESSOR });

const { loadAll } = usePageScripts([
    '/themes/metronic/assets/plugins/custom/datatables/datatables.bundle.js',
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/table.js',
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
        :form-component="ProfessorForm"
    />
</template>
