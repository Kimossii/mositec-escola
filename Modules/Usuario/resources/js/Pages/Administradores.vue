<script setup>
import { onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePageScripts } from '@/composables/usePageScripts';
import { useUsuarios } from '../Composables/useUsuarios';
import UsuarioListLayout from '../Components/UsuarioListLayout.vue';
import AdministradorForm from '../Forms/Administradores/AdministradorForm.vue';

// "Administrador" não é um tipo_pessoa no banco (só existe Aluno/Professor/
// Funcionário/Outro, ver Models/Usuario.js). Até existir integração real com
// roles/permissoes (módulo Permissao), a lista usa o placeholder
// `isAdministrador` do mock — não plugar em tipo_pessoa quando o backend
// chegar, e sim em alguma relação de papel/role.
const { usuarios } = useUsuarios({ apenasAdministradores: true });

const { loadAll } = usePageScripts([
    '/themes/metronic/assets/plugins/custom/datatables/datatables.bundle.js',
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/table.js',
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/export-users.js',
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/add.js',
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
        title="Administradores"
        icon="ki-shield-tick"
        accent="danger"
        :usuarios="usuarios"
        :form-component="AdministradorForm"
    />
</template>
