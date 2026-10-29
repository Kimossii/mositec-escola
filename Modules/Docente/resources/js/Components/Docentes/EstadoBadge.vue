<script setup>
import { estadoBadgeClass, estadoLabel } from '../../Models/Docente';

defineProps({
    estado: { type: Number, required: true }, // 0 | 1
});
</script>

<template>
    <span class="badge fw-bold" :class="estadoBadgeClass(estado)">{{ estadoLabel(estado) }}</span>
</template>
