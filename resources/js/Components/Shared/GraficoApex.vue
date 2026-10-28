<script setup>
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { ref } from 'vue';

/**
 * Gráfico ApexCharts (já carregado pelo bundle do tema) que acompanha o tema claro/escuro.
 * `opcoes` são as opções do Apex; o gráfico actualiza-se quando mudam.
 */
const props = defineProps({
    opcoes: { type: Object, required: true },
    altura: { type: Number, default: 280 },
});

const alvo = ref(null);
let grafico = null;

const escuro = () => document.documentElement.getAttribute('data-bs-theme') === 'dark';

const completas = () => ({
    ...props.opcoes,
    chart: { ...props.opcoes.chart, height: props.altura, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent', foreColor: escuro() ? '#CBD5E1' : '#64748B' },
    theme: { mode: escuro() ? 'dark' : 'light' },
    grid: { borderColor: escuro() ? '#334155' : '#E2E8F0', ...props.opcoes.grid },
});

onMounted(() => {
    grafico = new window.ApexCharts(alvo.value, completas());
    grafico.render();
});

watch(() => props.opcoes, () => grafico?.updateOptions(completas()), { deep: true });
onBeforeUnmount(() => grafico?.destroy());
</script>

<template>
    <div ref="alvo"></div>
</template>
