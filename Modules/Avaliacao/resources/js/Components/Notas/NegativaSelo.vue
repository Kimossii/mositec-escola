<script setup>
// O selo da negativa de um aluno na mini-pauta: «Negativa» onde a negativa decide; «Tratar negativa» / «Tratada» onde o aluno tem de transitar.
defineProps({
    aluno: { type: Object, required: true },
    trata: { type: Boolean, default: false },
    podeTratar: { type: Boolean, default: false },
    consequencia: { type: String, default: null },
});
const emit = defineEmits(['tratar']);
</script>

<template>
    <template v-if="trata">
        <component :is="podeTratar ? 'a' : 'span'" v-if="aluno.medida_negativa" href="#" class="badge badge-light-success ms-2" :title="aluno.medida_negativa" @click.prevent="podeTratar && emit('tratar')">Tratada</component>
        <component :is="podeTratar ? 'a' : 'span'" v-else href="#" class="badge badge-light-danger ms-2" :title="consequencia" @click.prevent="podeTratar && emit('tratar')">{{ podeTratar ? 'Tratar negativa' : 'Por tratar' }}</component>
    </template>
    <span v-else class="badge badge-light-danger ms-2" :title="consequencia">Negativa</span>
</template>
