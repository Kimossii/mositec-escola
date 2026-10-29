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
    /** classe de tamanho Keenicons (fs-1…fs-5); o padrão das listas é fs-4 */
    tamanho: { type: String, default: 'fs-4' },
    /** herda a cor do contexto (botões preenchidos, inputs) em vez da cor semântica */
    herdaCor: { type: Boolean, default: false },
});

const config = computed(() => ACOES_LISTA[props.acao]);
</script>

<template>
    <i class="ki-duotone" :class="[tamanho, config.icone, herdaCor ? null : `text-${config.cor}`]">
        <span v-for="n in config.paths" :key="n" :class="`path${n}`"></span>
    </i>
</template>
