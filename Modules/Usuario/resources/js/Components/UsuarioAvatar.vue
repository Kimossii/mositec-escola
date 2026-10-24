<script setup>
import { corDoAvatar } from '../Models/Usuario';

defineProps({
    usuario: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div class="symbol symbol-circle symbol-50px me-3 position-relative">
        <div v-if="usuario.avatar" class="symbol-label overflow-hidden">
            <img :src="usuario.avatar" :alt="usuario.name" class="w-100 h-100 object-fit-cover" />
        </div>
        <div v-else class="symbol-label fs-3 fw-bold" :class="`bg-light-${corDoAvatar(usuario.name)} text-${corDoAvatar(usuario.name)}`">
            {{ usuario.name.charAt(0).toUpperCase() }}
        </div>
        <!-- Ponto verde: sessão com actividade nos últimos 5 minutos -->
        <span v-if="usuario.online" class="position-absolute bottom-0 end-0 rounded-circle bg-success border border-2 border-body w-15px h-15px" title="Online agora"></span>
    </div>
</template>
