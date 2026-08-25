<script setup>
import { ESTADO, estadoLabel } from '../Models/Usuario';

defineProps({
    estado: {
        type: Number,
        required: true,
    },
});
</script>

<template>
    <div class="badge fw-bold" :class="estado === ESTADO.ATIVO ? 'badge-light-success' : 'badge-light-danger'">
        {{ estadoLabel(estado) }}
    </div>
</template>
