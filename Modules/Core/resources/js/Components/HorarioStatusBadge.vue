<script setup>
import { estadoBadgeClass } from '../Models/Horario';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
