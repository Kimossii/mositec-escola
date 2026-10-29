<script setup>
import { vigenciaBadgeClass, vigenciaLabel } from '../../Models/PapelDocente';

defineProps({
    estado: { type: String, required: true }, // agendada | em_curso | terminada
});
</script>

<template>
    <span class="badge fw-bold" :class="vigenciaBadgeClass(estado)">{{ vigenciaLabel(estado) }}</span>
</template>
