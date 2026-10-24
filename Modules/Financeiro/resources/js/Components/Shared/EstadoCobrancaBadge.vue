<script setup>
import { estadoCobrancaBadgeClass } from '../../Models/Propina';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoCobrancaBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
