<script setup>
import { computed, reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';
import FiltrosRecolhiveis from '@/Components/Shared/FiltrosRecolhiveis.vue';
import IndicadoresDiferidos from '@/Components/Shared/IndicadoresDiferidos.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { estadoPagamentoBadgeClass } from '../../Models/Pagamento';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const BASE = '/financeiro/recibos';

const props = defineProps({
    recibos: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    metodos: { type: Array, required: true },
    estados: { type: Array, required: true },
    moeda: { type: Object, required: true },
    indicadores: { type: Object, default: null }, // diferido: chega depois da lista
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (v) => (v !== undefined && v !== null && v !== '' && Number.isFinite(Number(v)) ? Number(v) : '');
const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    metodo_pagamento_id: numeroOuVazio(props.filtros.metodo_pagamento_id),
    estado: numeroOuVazio(props.filtros.estado),
    data_de: props.filtros.data_de ?? '',
    data_ate: props.filtros.data_ate ?? '',
});
const opcoesMetodo = computed(() => [{ value: '', label: 'Todos os métodos' }, ...props.metodos.map((m) => ({ value: m.id, label: m.nome }))]);
const opcoesEstado = computed(() => [{ value: '', label: 'Todos os estados' }, ...props.estados]);

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const montarIndicadores = (i) => [
    { rotulo: 'Recibos emitidos', valor: String(i.pagamentos), nota: `média ${dinheiro(i.media)} por recibo`, cor: 'primary' },
    { rotulo: 'Valor total', valor: dinheiro(i.recebido), nota: 'em recibos válidos', cor: 'success' },
    { rotulo: 'Último recibo', valor: i.ultimo_numero ? `nº ${i.ultimo_numero}` : '—', nota: i.ultima_data ? dataCurta(i.ultima_data) : 'ainda sem recibos', cor: 'info' },
    { rotulo: 'Anulados', valor: String(i.anulados), nota: `${dinheiro(i.anulados_valor)} anulados`, cor: 'danger' },
];
const filtrosAtivos = computed(() => [filtros.metodo_pagamento_id !== '', filtros.estado !== '', filtros.data_de !== '', filtros.data_ate !== ''].filter(Boolean).length);
const limparFiltros = () => Object.assign(filtros, { metodo_pagamento_id: '', estado: '', data_de: '', data_ate: '' });

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true }), 300);
});
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Recibos</h1>
            <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                Um recibo por pagamento, com o mesmo número. Abra para ver ou imprimir as duas vias; os pagamentos anulados ficam marcados.
            </p>
        </div>

        <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" />

        <FiltrosRecolhiveis v-model="filtros.pesquisa" placeholder="Pesquisar nº do recibo, aluno, nº do aluno ou da matrícula" :ativos="filtrosAtivos" @limpar="limparFiltros">
                    <div class="col-6 col-md-3"><label class="fw-semibold fs-7 text-muted mb-1">Método</label><SelectSolid v-model="filtros.metodo_pagamento_id" :options="opcoesMetodo" /></div>
                    <div class="col-6 col-md-3"><label class="fw-semibold fs-7 text-muted mb-1">Estado</label><SelectSolid v-model="filtros.estado" :options="opcoesEstado" /></div>
                    <div class="col-6 col-md-1"><label class="fw-semibold fs-7 text-muted mb-1">De</label><input v-model="filtros.data_de" type="date" class="form-control form-control-solid px-2" /></div>
                    <div class="col-6 col-md-1"><label class="fw-semibold fs-7 text-muted mb-1">Até</label><input v-model="filtros.data_ate" type="date" class="form-control form-control-solid px-2" /></div>
        </FiltrosRecolhiveis>

        <ListaComVistas chave="financeiro-recibos" prefixo="/financeiro/recibos">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-100px">Recibo</th>
                                <th class="min-w-100px">Data</th>
                                <th class="min-w-200px">Aluno</th>
                                <th class="min-w-125px">Método</th>
                                <th class="text-end min-w-110px">Valor</th>
                                <th class="min-w-100px">Estado</th>
                                <th class="text-end min-w-150px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="recibos.data.length === 0"><td colspan="7" class="text-center text-muted py-6">Nenhum recibo encontrado.</td></tr>
                            <tr v-for="r in recibos.data" :key="r.id">
                                <td class="text-gray-800">{{ r.numero }}</td>
                                <td>{{ dataCurta(r.data_pagamento) }}</td>
                                <td class="text-gray-800"><span class="text-uppercase">{{ r.aluno_nome ?? '—' }}</span><div class="text-muted fs-7">nº {{ r.aluno_numero ?? '—' }} · matrícula {{ r.matricula_numero ?? '—' }}</div></td>
                                <td>{{ r.metodo_nome ?? '—' }}<div v-if="r.referencia" class="text-muted fs-8">{{ r.referencia }}</div></td>
                                <td class="text-end">{{ formatarDinheiro(r.valor, moeda) }}</td>
                                <td><span class="badge" :class="estadoPagamentoBadgeClass(r.estado)">{{ r.estado_descricao }}</span></td>
                                <td class="text-end text-nowrap">
                                    <a :href="`/financeiro/pagamentos/${r.id}/recibo`" target="_blank" class="btn btn-sm btn-light-primary me-1">Ver recibo</a>
                                    <a :href="`/financeiro/pagamentos/${r.id}`" class="btn btn-sm btn-light">Pagamento</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="recibos.data.length" class="card-footer d-flex justify-content-end"><Pagination :links="recibos.links" /></div>
        </div>
        </ListaComVistas>
    </div>
</template>
