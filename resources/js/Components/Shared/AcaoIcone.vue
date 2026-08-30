<script setup>
import { computed } from 'vue';
import { ACOES_LISTA } from './acoesLista';

const props = defineProps({
    /** chave em ACOES_LISTA — ex: "editar", "permissoes", "eliminar" */
    acao: {
        type: String,
        required: true,
        validator: (value) => Object.keys(ACOES_LISTA).includes(value),
    },
});

const config = computed(() => ACOES_LISTA[props.acao]);
</script>

<template>
    <i class="ki-duotone fs-4" :class="[config.icone, `text-${config.cor}`]">
        <span v-for="n in config.paths" :key="n" :class="`path${n}`"></span>
    </i>
</template>
