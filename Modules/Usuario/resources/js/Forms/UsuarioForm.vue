<script setup>
defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :utilizador="utilizador"
        rota-criar="/usuarios/cadastrarUsuario"
    />
</template>

<script>
import UsuarioWizardForm from '../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
