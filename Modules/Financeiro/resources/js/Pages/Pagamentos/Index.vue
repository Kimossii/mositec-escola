<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltrosRecolhiveis from '../../Components/Shared/FiltrosRecolhiveis.vue';
import IndicadoresDiferidos from '../../Components/Shared/IndicadoresDiferidos.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { estadoPagamentoBadgeClass } from '../../Models/Pagamento';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const BASE = '/financeiro/pagamentos';

const props = defineProps({
    pagamentos: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    metodos: { type: Array, required: true },
    estados: { type: Array, required: true },
    moeda: { type: Object, required: true },
    indicadores: { type: Object, default: null }, // diferido: chega depois da lista
    podeRegistar: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (valor) => (valor !== undefined && valor !== null && valor !== '' && Number.isFinite(Number(valor)) ? Number(valor) : '');

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    metodo_pagamento_id: numeroOuVazio(props.filtros.metodo_pagamento_id),
    estado: numeroOuVazio(props.filtros.estado),
    referencia: props.filtros.referencia ?? '',
    data_de: props.filtros.data_de ?? '',
    data_ate: props.filtros.data_ate ?? '',
});

const opcoesMetodo = computed(() => [{ value: '', label: 'Todos os métodos' }, ...props.metodos.map((m) => ({ value: m.id, label: m.nome }))]);
const opcoesEstado = computed(() => [{ value: '', label: 'Todos os estados' }, ...props.estados]);

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const montarIndicadores = (i) => [
    { rotulo: 'Recebido', valor: dinheiro(i.recebido), nota: `${i.pagamentos} pagamento(s) · média ${dinheiro(i.media)}`, cor: 'success' },
    { rotulo: 'Pagamentos', valor: String(i.pagamentos), nota: i.ultima_data ? `último: nº ${i.ultimo_numero}, ${dataCurta(i.ultima_data)}` : 'sem pagamentos', cor: 'primary' },
    { rotulo: 'Crédito gerado', valor: dinheiro(i.credito_gerado), nota: 'adiantamentos e excedentes', cor: 'warning' },
    { rotulo: 'Anulados', valor: String(i.anulados), nota: `${dinheiro(i.anulados_valor)} anulados`, cor: 'danger' },
];
const filtrosAtivos = computed(() => [filtros.metodo_pagamento_id !== '', filtros.estado !== '', filtros.referencia !== '', filtros.data_de !== '', filtros.data_ate !== ''].filter(Boolean).length);
const limparFiltros = () => Object.assign(filtros, { metodo_pagamento_id: '', estado: '', referencia: '', data_de: '', data_ate: '' });

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true }), 300);
});
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Pagamentos</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Dinheiro recebido das matrículas, distribuído pelas propinas. O que sobra fica como crédito do aluno.
                    Um pagamento nunca se edita: anula-se com motivo e regista-se de novo.
                </p>
            </div>
            <Link v-if="podeRegistar" href="/financeiro/pagamentos/novo" class="btn btn-primary">Registar pagamento</Link>
        </div>

        <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" />

        <FiltrosRecolhiveis v-model="filtros.pesquisa" placeholder="Pesquisar nº do pagamento, aluno, nº do aluno ou da matrícula" :ativos="filtrosAtivos" @limpar="limparFiltros">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Método</label>
                        <SelectSolid v-model="filtros.metodo_pagamento_id" :options="opcoesMetodo" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Referência</label>
                        <input v-model="filtros.referencia" type="text" class="form-control form-control-solid" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Data de</label>
                        <input v-model="filtros.data_de" type="date" class="form-control form-control-solid" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Data até</label>
                        <input v-model="filtros.data_ate" type="date" class="form-control form-control-solid" />
                    </div>
        </FiltrosRecolhiveis>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-100px">Nº</th>
                                <th class="min-w-100px">Data</th>
                                <th class="min-w-175px">Aluno</th>
                                <th class="min-w-100px">Matrícula</th>
                                <th class="min-w-125px">Método</th>
                                <th class="text-end min-w-100px">Valor</th>
                                <th class="text-end min-w-100px">Propinas</th>
                                <th class="text-end min-w-100px">Crédito</th>
                                <th class="min-w-100px">Estado</th>
                                <th class="text-end min-w-75px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="pagamentos.data.length === 0">
                                <td colspan="10" class="text-center text-muted py-6">Nenhum pagamento encontrado.</td>
                            </tr>
                            <tr v-for="pagamento in pagamentos.data" :key="pagamento.id">
                                <td class="text-gray-800">{{ pagamento.numero }}</td>
                                <td>{{ dataCurta(pagamento.data_pagamento) }}</td>
                                <td class="text-gray-800">
                                    {{ pagamento.aluno_nome ?? '—' }}
                                    <div class="text-muted fs-7">nº {{ pagamento.aluno_numero ?? '—' }}</div>
                                </td>
                                <td>{{ pagamento.matricula_numero ?? '—' }}</td>
                                <td>
                                    {{ pagamento.metodo_nome ?? '—' }}
                                    <div v-if="pagamento.referencia" class="text-muted fs-8">{{ pagamento.referencia }}</div>
                                </td>
                                <td class="text-end">{{ formatarDinheiro(pagamento.valor, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(pagamento.total_distribuido, moeda) }}</td>
                                <td class="text-end">{{ pagamento.credito_gerado ? formatarDinheiro(pagamento.credito_gerado, moeda) : '—' }}</td>
                                <td><span class="badge" :class="estadoPagamentoBadgeClass(pagamento.estado)">{{ pagamento.estado_descricao }}</span></td>
                                <td class="text-end">
                                    <Link :href="`/financeiro/pagamentos/${pagamento.id}`" class="btn btn-light btn-active-light-primary btn-sm">
                                        <AcaoIcone acao="visualizar" class="me-1" /> Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="pagamentos.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="pagamentos.links" />
            </div>
        </div>
    </div>
</template>
