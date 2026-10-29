<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    importacoes: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const data = (iso) => (iso ? new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' }) : '');
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Importações</h1>
            <div class="text-muted">As suas importações em massa. Para começar, use o botão «Importar» na lista de Cursos ou Disciplinas.</div>
        </div>

        <div class="card card-flush">
            <div class="card-body py-4">
                <div v-if="!importacoes.length" class="text-center text-muted py-10">Ainda não fez nenhuma importação.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead><tr class="text-muted fw-bold fs-7 text-uppercase"><th>Entidade</th><th>Ficheiro</th><th>Linhas</th><th>Importadas</th><th>Estado</th><th>Quando</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="i in importacoes" :key="i.id">
                                <td class="fw-semibold">{{ i.titulo }}</td>
                                <td>{{ i.nome_ficheiro }}</td>
                                <td>{{ i.total }}</td>
                                <td>{{ i.importadas }}</td>
                                <td>{{ i.estado_texto }}</td>
                                <td>{{ data(i.criado_em) }}</td>
                                <td class="text-end"><Link :href="`/importacoes/${i.id}`" class="btn btn-sm btn-light-primary">Abrir</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
