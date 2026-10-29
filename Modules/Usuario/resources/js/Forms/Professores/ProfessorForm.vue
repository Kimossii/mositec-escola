<script setup>
import { can } from '@/Composables/usePermissoes';

defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        perfil-fixo="professor"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
        :utilizador="utilizador"
        rota-criar="/usuarios/professores/cadastrar"
        :permitir-docente="!utilizador && can('usuario.criar') && can('docente.editar')"
    />
</template>

<script>
import UsuarioWizardForm from '../../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
