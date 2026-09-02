<script setup>
import { estadoAnoLectivoBadgeClass } from '../Models/AnoLectivo';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoAnoLectivoBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
