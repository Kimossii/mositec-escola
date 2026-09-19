<script setup>
import { estadoMatriculaBadgeClass, estadoMatriculaLabel } from '../../Models/Estado';

const props = defineProps({
    estado: { type: Number, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoMatriculaBadgeClass(props.estado)">
        {{ estadoMatriculaLabel(props.estado) }}
    </div>
</template>
