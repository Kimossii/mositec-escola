<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router, Deferred } from '@inertiajs/vue3';
import MenuExportar from '@/Components/Shared/MenuExportar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';
import FiltrosRecolhiveis from '@/Components/Shared/FiltrosRecolhiveis.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const BASE = '/financeiro/dividas';

const props = defineProps({
    dividas: { type: Object, required: true },
    totais: { type: Object, default: null }, // diferido: chega depois da lista
    filtros: { type: Object, default: () => ({}) },
    anosLectivos: { type: Array, required: true },
    turmas: { type: Array, required: true },
    moeda: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const numeroOuVazio = (v) => (v !== undefined && v !== null && v !== '' && Number.isFinite(Number(v)) ? Number(v) : '');
const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    ano_lectivo_id: numeroOuVazio(props.filtros.ano_lectivo_id),
    turma_id: numeroOuVazio(props.filtros.turma_id),
    tipo: props.filtros.tipo ?? '',
});

const opcoesAno = computed(() => [{ value: '', label: 'Todos os anos lectivos' }, ...props.anosLectivos.map((a) => ({ value: a.id, label: a.nome }))]);
const opcoesTurma = computed(() => [
    { value: '', label: 'Todas as turmas' },
    ...props.turmas.filter((t) => filtros.ano_lectivo_id === '' || t.ano_lectivo_id === filtros.ano_lectivo_id).map((t) => ({ value: t.id, label: t.nome })),
]);
const opcoesTipo = [
    { value: '', label: 'Todos os tipos' },
    { value: 'propinas', label: 'Com propinas em dívida' },
    { value: 'multas', label: 'Com multas por pagar' },
    { value: 'cobrancas', label: 'Com produtos/serviços por pagar' },
];

const filtrosAtivos = computed(() => [filtros.ano_lectivo_id !== '', filtros.turma_id !== '', filtros.tipo !== ''].filter(Boolean).length);
const limparFiltros = () => Object.assign(filtros, { ano_lectivo_id: '', turma_id: '', tipo: '' });

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
                <h1 class="fs-2 fw-bold mb-1">Dívidas</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Matrículas com valores por pagar: propinas de períodos já iniciados, multas e produtos ou serviços. Da maior dívida para a menor.
                </p>
            </div>
            <MenuExportar v-if="can('pagamento.exportar')" :url="`${BASE}/exportar`" :params="filtros" />
        </div>

        <Deferred data="totais">
            <template #fallback><div class="row g-4 mb-6"><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div></div></template>
        <div class="row g-4 mb-6">
            <div class="col-6 col-md-3"><div class="card"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Total em dívida</div><div class="fs-3 fw-bold text-danger">{{ dinheiro(totais.total) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Propinas</div><div class="fs-4 fw-bold">{{ dinheiro(totais.propinas) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Multas</div><div class="fs-4 fw-bold">{{ dinheiro(totais.multas) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Produtos e serviços</div><div class="fs-4 fw-bold">{{ dinheiro(totais.cobrancas) }}</div></div></div></div>
        </div>
        </Deferred>

        <FiltrosRecolhiveis v-model="filtros.pesquisa" placeholder="Pesquisar aluno, nº do aluno ou da matrícula" :ativos="filtrosAtivos" @limpar="limparFiltros">
                    <div class="col-6 col-md-3"><label class="fw-semibold fs-7 text-muted mb-1">Ano lectivo</label><SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAno" /></div>
                    <div class="col-6 col-md-2"><label class="fw-semibold fs-7 text-muted mb-1">Turma</label><SelectSolid v-model="filtros.turma_id" :options="opcoesTurma" /></div>
                    <div class="col-12 col-md-3"><label class="fw-semibold fs-7 text-muted mb-1">Tipo</label><SelectSolid v-model="filtros.tipo" :options="opcoesTipo" /></div>
        </FiltrosRecolhiveis>

        <ListaComVistas chave="financeiro-dividas" prefixo="/financeiro/dividas">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-200px">Aluno</th>
                                <th class="min-w-150px">Turma</th>
                                <th class="text-end min-w-100px">Propinas</th>
                                <th class="text-end min-w-100px">Multas</th>
                                <th class="text-end min-w-100px">Prod./serv.</th>
                                <th class="text-end min-w-110px">Total</th>
                                <th class="min-w-125px">Em dívida desde</th>
                                <th class="text-end min-w-150px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="dividas.data.length === 0"><td colspan="8" class="text-center text-muted py-6">Sem dívidas para os filtros escolhidos.</td></tr>
                            <tr v-for="d in dividas.data" :key="d.matricula_id">
                                <td class="text-gray-800">
                                    <span class="text-uppercase">{{ d.aluno_nome ?? '—' }}</span>
                                    <div class="text-muted fs-7">nº {{ d.aluno_numero ?? '—' }} · matrícula {{ d.matricula_numero }} · {{ d.matricula_estado }}</div>
                                    <a v-if="d.acordo" :href="`/financeiro/acordos/${d.acordo.id}`" class="badge fs-8 mt-1" :class="d.acordo.em_atraso ? 'badge-light-danger' : 'badge-light-primary'">
                                        Em acordo{{ d.acordo.em_atraso ? ' · em atraso' : '' }}<span v-if="d.acordo.proxima"> · próxima {{ dataCurta(d.acordo.proxima.data_vencimento) }}</span>
                                    </a>
                                </td>
                                <td>{{ d.turma_nome ?? '—' }}<div class="text-muted fs-7">{{ d.ano_lectivo_nome ?? '—' }}</div></td>
                                <td class="text-end">{{ d.propinas ? dinheiro(d.propinas) : '—' }}<div v-if="d.meses" class="text-muted fs-8">{{ d.meses }} mês(es)</div></td>
                                <td class="text-end">{{ d.multas ? dinheiro(d.multas) : '—' }}</td>
                                <td class="text-end">{{ d.cobrancas ? dinheiro(d.cobrancas) : '—' }}</td>
                                <td class="text-end fw-bold text-danger">{{ dinheiro(d.total) }}</td>
                                <td>{{ d.desde ? dataCurta(d.desde) : '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <a v-if="can('pagamento.criar')" :href="`/financeiro/pagamentos/novo?matricula=${d.matricula_id}`" class="btn btn-sm btn-light-primary me-1">Pagar</a>
                                    <Link :href="`/alunos/${d.aluno_id}`" class="btn btn-sm btn-light">Ficha</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="dividas.data.length" class="card-footer d-flex justify-content-end"><Pagination :links="dividas.links" /></div>
        </div>
        </ListaComVistas>
    </div>
</template>
