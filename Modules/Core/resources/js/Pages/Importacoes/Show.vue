<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    importacao: { type: Object, required: true },
    amostra: { type: Array, default: () => [] }, // [{ linha, ignorada, erros }]
});
defineOptions({ layout: AppLayout });

const ESTADO = { 0: 'badge-light-warning', 1: 'badge-light-warning', 2: 'badge-light-primary', 3: 'badge-light-warning', 4: 'badge-light-success', 5: 'badge-light-danger', 6: 'badge-light-secondary' };

// Enquanto valida ou importa, pergunta ao servidor de 3 em 3 segundos.
let relogio = null;
onMounted(() => {
    relogio = setInterval(() => {
        if (props.importacao.em_curso) router.reload({ only: ['importacao', 'amostra'] });
    }, 3000);
});
onBeforeUnmount(() => clearInterval(relogio));

const confirmar = () => router.post(`/importacoes/${props.importacao.id}/confirmar`, {}, { preserveScroll: true });
const cancelar = () => router.post(`/importacoes/${props.importacao.id}/cancelar`, {}, { preserveScroll: true });
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <Link href="/importacoes" class="text-muted fs-7">← Importações</Link>
            <h1 class="fs-2 fw-bold mb-1 mt-2">Importação de {{ importacao.titulo }}</h1>
            <div class="text-muted">{{ importacao.nome_ficheiro }} <span class="badge ms-2" :class="ESTADO[importacao.estado]">{{ importacao.estado_texto }}</span></div>
        </div>

        <div v-if="importacao.erro_geral" class="alert alert-danger">{{ importacao.erro_geral }}</div>

        <div v-if="importacao.estado === 4 && importacao.importadas > 0 && !importacao.com_erro" class="d-flex flex-column align-items-center text-center mb-6">
            <img src="/images/mosi/mosi-sucesso.svg" width="130" alt="" />
            <div class="fs-4 fw-bold mt-2">Importação concluída</div>
            <div class="text-muted">{{ importacao.importadas }} registo(s) importado(s).</div>
        </div>

        <div class="row g-4 mb-6">
            <div v-for="[rotulo, valor] in [['Linhas no ficheiro', importacao.total], ['Válidas', importacao.validas], ['Já existiam (ignoradas)', importacao.ignoradas], ['Com erro', importacao.com_erro], ['Importadas', importacao.importadas]]" :key="rotulo" class="col-6 col-md">
                <div class="card card-flush h-100"><div class="card-body py-4"><div class="text-muted fs-8">{{ rotulo }}</div><div class="fs-2 fw-bold">{{ valor }}</div></div></div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-3 mb-6">
            <button v-if="importacao.estado === 2 && importacao.validas > 0" class="btn btn-primary" @click="confirmar">Importar {{ importacao.validas }} registo(s) válido(s)</button>
            <button v-if="importacao.estado === 5 && importacao.validas > 0" class="btn btn-primary" @click="confirmar">Retomar importação</button>
            <button v-if="importacao.estado === 2 || importacao.estado === 5" class="btn btn-light" @click="cancelar">Cancelar</button>
            <a v-if="importacao.com_erro || importacao.ignoradas" :href="`/importacoes/${importacao.id}/erros`" class="btn btn-light-primary">Descarregar linhas com problema</a>
            <Link :href="`/importacoes/${importacao.entidade}/nova`" class="btn btn-light">Nova importação</Link>
        </div>

        <div v-if="amostra.length" class="card card-flush">
            <div class="card-header"><h3 class="card-title fs-5 fw-bold">Linhas com problema (primeiras {{ amostra.length }})</h3></div>
            <div class="card-body pt-0 table-responsive">
                <table class="table align-middle table-row-dashed fs-7 mb-0">
                    <thead><tr class="text-muted fw-bold text-uppercase"><th style="width: 6rem">Linha</th><th>Problema</th></tr></thead>
                    <tbody>
                        <tr v-for="a in amostra" :key="a.linha">
                            <td>{{ a.linha }}</td>
                            <td><span :class="a.ignorada ? 'text-muted' : 'text-danger'">{{ (a.erros ?? []).join(' · ') }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
