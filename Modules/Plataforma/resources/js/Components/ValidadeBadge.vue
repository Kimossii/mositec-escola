<script setup>
import { computed } from 'vue';

// A validade é DERIVADA das datas (e do estado comercial quando a licença já não está activa); vem do backend
// (LicencasConsultaService). O estado comercial guardado mostra-se à parte.
const props = defineProps({
    validade: { type: String, required: true },
});

const VALIDADES = {
    valida: { texto: 'Válida', classe: 'badge-light-success' },
    a_expirar: { texto: 'A expirar', classe: 'badge-light-warning' },
    em_tolerancia: { texto: 'Em tolerância', classe: 'badge-light-warning' },
    expirada: { texto: 'Expirada', classe: 'badge-light-danger' },
    nao_iniciada: { texto: 'Ainda não iniciada', classe: 'badge-light-info' },
    suspensa: { texto: 'Suspensa', classe: 'badge-light-warning' },
    cancelada: { texto: 'Cancelada', classe: 'badge-light-danger' },
    substituida: { texto: 'Substituída', classe: 'badge-light-secondary' },
};

const apresentacao = computed(() => VALIDADES[props.validade] ?? { texto: 'Desconhecida', classe: 'badge-light-secondary' });
</script>

<template>
    <span class="badge fw-bold" :class="apresentacao.classe">{{ apresentacao.texto }}</span>
</template>
