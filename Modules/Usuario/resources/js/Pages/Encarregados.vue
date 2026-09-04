<script setup>
import { onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePageScripts } from '@/composables/usePageScripts';
import UsuarioListLayout from '../Components/UsuarioListLayout.vue';
import EncarregadoForm from '../Forms/Encarregados/EncarregadoForm.vue';

defineProps({
    usuarios: { type: Array, required: true },
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
});

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
        title="Encarregados"
        icon="ki-people"
        accent="info"
        :usuarios="usuarios"
        :form-component="EncarregadoForm"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
    />
</template>
