<script setup>
import { computed } from 'vue';

// Espelha Modules/Core/app/Tenancy/Enums/EstadoTenant.php (1 Activa, 2 Suspensa, 3 Encerrada).
// Só apresentação; a autoridade do estado é o backend.
const props = defineProps({
    estado: { type: Number, required: true },
});

const ESTADOS = {
    1: { texto: 'Activa', classe: 'badge-light-success' },
    2: { texto: 'Suspensa', classe: 'badge-light-warning' },
    3: { texto: 'Encerrada', classe: 'badge-light-secondary' },
};

const apresentacao = computed(() => ESTADOS[props.estado] ?? { texto: 'Desconhecido', classe: 'badge-light-secondary' });
</script>

<template>
    <span class="badge fw-bold" :class="apresentacao.classe">{{ apresentacao.texto }}</span>
</template>
