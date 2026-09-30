<script setup>
import { estadoSalaBadgeClass } from '../../Models/Sala';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoSalaBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
