<script setup>
import { computed } from 'vue';

/** Erros do servidor sem campo correspondente no modal (ex.: `turma_id`, `atribuicao`, `docente_id` no «Criar acesso»): mostrados num aviso no topo. */
const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    campos: { type: Array, required: true }, // chaves que o modal já mostra junto ao campo
});

const outros = computed(() => Object.entries(props.errors).filter(([chave]) => !props.campos.includes(chave)).map(([, msg]) => msg));
</script>

<template>
    <div v-if="outros.length" class="alert alert-danger py-3 px-4 fs-7 mb-6">
        <div v-for="(msg, i) in outros" :key="i">{{ msg }}</div>
    </div>
</template>
