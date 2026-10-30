<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';
import FiltrosRecolhiveis from '@/Components/Shared/FiltrosRecolhiveis.vue';
import IndicadoresDiferidos from '@/Components/Shared/IndicadoresDiferidos.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';

const BASE = '/financeiro/cobrancas';

const props = defineProps({
    cobrancas: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    estados: { type: Array, required: true },
    moeda: { type: Object, required: true },
    indicadores: { type: Object, default: null }, // diferido: chega depois da lista
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (v) => (v !== undefined && v !== null && v !== '' && Number.isFinite(Number(v)) ? Number(v) : '');
const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: numeroOuVazio(props.filtros.estado),
    origem: numeroOuVazio(props.filtros.origem),
});

const opcoesEstado = computed(() => [{ value: '', label: 'Todos os estados' }, ...props.estados]);
const opcoesOrigem = [
    { value: '', label: 'Todas as origens' },
    { value: 1, label: 'Manual' },
    { value: 2, label: 'Obrigatório' },
];

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const montarIndicadores = (i) => [
    { rotulo: 'Por pagar', valor: dinheiro(i.abertas_valor), nota: `${i.abertas_n} cobrança(s) em aberto`, cor: 'danger' },
    { rotulo: 'Pagas', valor: dinheiro(i.pagas_valor), nota: `${i.pagas_n} cobrança(s)`, cor: 'success' },
    { rotulo: 'Obrigatórias por pagar', valor: dinheiro(i.obrigatorias_valor), nota: `${i.obrigatorias_n} em aberto`, cor: 'warning' },
    { rotulo: 'Canceladas', valor: String(i.canceladas_n), nota: `${dinheiro(i.canceladas_valor)} cancelados`, cor: 'info' },
];
const filtrosAtivos = computed(() => [filtros.estado !== '', filtros.origem !== ''].filter(Boolean).length);
const limparFiltros = () => Object.assign(filtros, { estado: '', origem: '' });

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
                <h1 class="fs-2 fw-bold mb-1">Cobranças</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Produtos e serviços cobrados às matrículas. As obrigatórias nascem ao matricular; as restantes cobram-se a pedido.
                </p>
            </div>
            <Link v-if="can('cobranca.criar')" href="/financeiro/cobrancas/nova" class="btn btn-primary">Nova cobrança</Link>
        </div>

        <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" />

        <FiltrosRecolhiveis v-model="filtros.pesquisa" placeholder="Pesquisar aluno, nº do aluno ou da matrícula" :ativos="filtrosAtivos" @limpar="limparFiltros">
                    <div class="col-6 col-md-3">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="fw-semibold fs-7 text-muted mb-1">Origem</label>
                        <SelectSolid v-model="filtros.origem" :options="opcoesOrigem" />
                    </div>
        </FiltrosRecolhiveis>

        <ListaComVistas chave="financeiro-cobrancas" prefixo="/financeiro/cobrancas">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-75px">Nº</th>
                                <th class="min-w-100px">Data</th>
                                <th class="min-w-175px">Aluno</th>
                                <th class="min-w-100px">Matrícula</th>
                                <th class="min-w-100px">Origem</th>
                                <th class="text-end min-w-100px">Total</th>
                                <th class="text-end min-w-100px">Em dívida</th>
                                <th class="min-w-100px">Estado</th>
                                <th class="text-end min-w-75px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="cobrancas.data.length === 0">
                                <td colspan="9"><EstadoVazio titulo="Nenhuma cobrança encontrada." /></td>
                            </tr>
                            <tr v-for="c in cobrancas.data" :key="c.id">
                                <td class="text-gray-800">#{{ c.id }}</td>
                                <td>{{ dataCurta(c.criada_em) }}</td>
                                <td class="text-gray-800">
                                    {{ c.aluno_nome ?? '—' }}
                                    <div class="text-muted fs-7">nº {{ c.aluno_numero ?? '—' }}</div>
                                </td>
                                <td>{{ c.matricula_numero ?? '—' }}</td>
                                <td><span class="badge" :class="c.origem === 2 ? 'badge-light-warning' : 'badge-light'">{{ c.origem_descricao }}</span></td>
                                <td class="text-end">{{ formatarDinheiro(c.total, moeda) }}</td>
                                <td class="text-end">{{ c.estado === 4 ? '—' : formatarDinheiro(c.saldo, moeda) }}</td>
                                <td><EstadoCobrancaBadge :estado="c.estado" :estado-descricao="c.estado_descricao" /></td>
                                <td class="text-end">
                                    <Link :href="`/financeiro/cobrancas/${c.id}`" class="btn btn-light btn-active-light-primary btn-sm">
                                        <AcaoIcone acao="visualizar" class="me-1" /> Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="cobrancas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="cobrancas.links" />
            </div>
        </div>
        </ListaComVistas>
    </div>
</template>
