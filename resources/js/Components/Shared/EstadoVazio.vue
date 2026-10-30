<script setup>
// Lista ou secção sem conteúdo: o Mosi e uma frase. As animações do SVG já respeitam `prefers-reduced-motion`.
defineProps({
    titulo: { type: String, required: true },
    descricao: { type: String, default: null },
    imagem: { type: String, default: 'mosi-sem-dados' },
    tamanho: { type: Number, default: 110 },
});
</script>

<template>
    <div class="d-flex flex-column align-items-center text-center py-6">
        <img :src="`/images/mosi/${imagem}.svg`" :width="tamanho" alt="" class="mb-3" />
        <div class="text-gray-700 fw-semibold fs-6">{{ titulo }}</div>
        <div v-if="descricao" class="text-muted fs-7 mt-1">{{ descricao }}</div>
    </div>
</template>
