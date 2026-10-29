<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    pedidos: { type: Array, required: true }, // [{ id, titulo, formato, nome, linhas, estado (0 a preparar | 1 pronto | 2 erro), erro, criado_em }]
});
defineOptions({ layout: AppLayout });

const ESTADO = {
    0: { texto: 'A preparar…', classe: 'badge-light-warning' },
    1: { texto: 'Pronto', classe: 'badge-light-success' },
    2: { texto: 'Falhou', classe: 'badge-light-danger' },
};
const aPreparar = computed(() => props.pedidos.some((p) => p.estado === 0));
const tamanho = (bytes) => (!bytes ? '—' : bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`);
const data = (iso) => (iso ? new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' }) : '');

// Enquanto houver ficheiros a preparar, volta a perguntar ao servidor de 4 em 4 segundos.
let relogio = null;
onMounted(() => {
    relogio = setInterval(() => {
        if (aPreparar.value) router.reload({ only: ['pedidos'] });
    }, 4000);
});
onBeforeUnmount(() => clearInterval(relogio));
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Os meus ficheiros</h1>
            <div class="text-muted">Exportações grandes preparadas em segundo plano. Ficam disponíveis durante 7 dias e guardamos os seus últimos 20.</div>
        </div>

        <div class="card card-flush">
            <div class="card-body py-4">
                <div v-if="!pedidos.length" class="text-center text-muted py-10">Ainda não pediu nenhuma exportação grande.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase">
                                <th>Exportação</th>
                                <th>Formato</th>
                                <th>Linhas</th>
                                <th>Tamanho</th>
                                <th>Pedido em</th>
                                <th>Estado</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in pedidos" :key="p.id">
                                <td class="fw-semibold">{{ p.titulo }}</td>
                                <td class="text-uppercase">{{ p.formato }}</td>
                                <td>{{ p.linhas }}</td>
                                <td>{{ tamanho(p.tamanho) }}</td>
                                <td>{{ data(p.criado_em) }}</td>
                                <td>
                                    <span class="badge" :class="ESTADO[p.estado].classe">{{ ESTADO[p.estado].texto }}</span>
                                    <div v-if="p.estado === 2 && p.erro" class="text-muted fs-8 mt-1">{{ p.erro }}</div>
                                </td>
                                <td class="text-end">
                                    <a v-if="p.estado === 1" :href="`/ficheiros/${p.id}/descarregar`" class="btn btn-sm btn-light-primary">Descarregar</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
