<script setup>
/** Foto do utilizador, ou as iniciais sobre fundo verde-claro quando não há foto. `tamanho` é a classe `symbol-*` do tema. */
defineProps({
    foto: { type: String, default: null },
    iniciais: { type: String, default: '' },
    tamanho: { type: String, default: 'symbol-50px' },
    circulo: { type: Boolean, default: false },
});
</script>

<template>
    <span class="symbol" :class="[tamanho, { 'symbol-circle': circulo }]">
        <img v-if="foto" :src="foto" alt="Foto de perfil" style="object-fit: cover" />
        <span v-else class="symbol-label bg-light-success text-success fw-bold">{{ iniciais }}</span>
    </span>
</template>
