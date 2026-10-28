<script setup>
import { reactive } from 'vue';
import { router, Deferred } from '@inertiajs/vue3';
import MenuExportar from '@/Components/Shared/MenuExportar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatarDinheiro } from '../../Support/dinheiro';

const props = defineProps({
    relatorio: { type: Object, default: null }, // diferido: agregações pesadas chegam depois
    periodo: { type: Object, required: true },
    moeda: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const intervalo = reactive({ de: props.periodo.de, ate: props.periodo.ate });
const aplicar = () => router.get('/financeiro/relatorios', { ...intervalo }, { preserveScroll: true });
const mesPT = (ym) => { const [a, m] = ym.split('-'); return `${['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'][Number(m) - 1]}/${a}`; };
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-4 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Relatórios financeiros</h1>
                <p class="text-muted fs-6 mb-0">O que entrou no período e a dívida actual. Só pagamentos confirmados.</p>
            </div>
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <div><label class="fw-semibold fs-7 text-muted mb-1 d-block">De</label><input v-model="intervalo.de" type="date" class="form-control form-control-solid" /></div>
                <div><label class="fw-semibold fs-7 text-muted mb-1 d-block">Até</label><input v-model="intervalo.ate" type="date" class="form-control form-control-solid" /></div>
                <button type="button" class="btn btn-primary" @click="aplicar">Aplicar</button>
                <MenuExportar url="/financeiro/relatorios/recebimentos/exportar" :params="intervalo" rotulo="Exportar recebimentos" />
            </div>
        </div>

        <Deferred data="relatorio">
            <template #fallback>
                <div class="row g-4 mb-6"><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div><div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div></div>
                <div class="row g-6 mb-6"><div class="col-12 col-lg-6"><div class="card h-200px"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div><div class="col-12 col-lg-6"><div class="card h-200px"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div></div>
            </template>
        <h3 class="fs-5 fw-bold text-uppercase text-gray-600 mb-3">Recebido no período</h3>
        <div class="row g-4 mb-6">
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Total recebido</div><div class="fs-2 fw-bold text-success">{{ dinheiro(relatorio.recebido.total) }}</div><div class="text-muted fs-7">{{ relatorio.recebido.pagamentos }} pagamento(s)</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Propinas</div><div class="fs-4 fw-bold">{{ dinheiro(relatorio.recebido.propinas) }}</div></div></div></div>
            <div class="col-6 col-md-2"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Multas</div><div class="fs-4 fw-bold">{{ dinheiro(relatorio.recebido.multas) }}</div></div></div></div>
            <div class="col-6 col-md-2"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Produtos/serv.</div><div class="fs-4 fw-bold">{{ dinheiro(relatorio.recebido.cobrancas) }}</div></div></div></div>
            <div class="col-12 col-md-2"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Crédito gerado</div><div class="fs-4 fw-bold">{{ dinheiro(relatorio.recebido.credito_gerado) }}</div></div></div></div>
        </div>

        <div class="row g-6 mb-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">Por método de pagamento</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="relatorio.por_metodo.length === 0" class="text-muted mb-0">Sem pagamentos no período.</p>
                        <table v-else class="table table-row-dashed align-middle fs-6 gy-3 mb-0">
                            <thead><tr class="text-muted fw-bold fs-7 text-uppercase"><th>Método</th><th class="text-center">Pagamentos</th><th class="text-end">Valor</th></tr></thead>
                            <tbody class="fw-semibold text-gray-800">
                                <tr v-for="m in relatorio.por_metodo" :key="m.metodo"><td>{{ m.metodo }}</td><td class="text-center">{{ m.pagamentos }}</td><td class="text-end">{{ dinheiro(m.valor) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">Por mês</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="relatorio.por_mes.length === 0" class="text-muted mb-0">Sem pagamentos no período.</p>
                        <table v-else class="table table-row-dashed align-middle fs-6 gy-3 mb-0">
                            <thead><tr class="text-muted fw-bold fs-7 text-uppercase"><th>Mês</th><th class="text-center">Pagamentos</th><th class="text-end">Valor</th></tr></thead>
                            <tbody class="fw-semibold text-gray-800">
                                <tr v-for="m in relatorio.por_mes" :key="m.mes"><td>{{ mesPT(m.mes) }}</td><td class="text-center">{{ m.pagamentos }}</td><td class="text-end">{{ dinheiro(m.valor) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <h3 class="fs-5 fw-bold text-uppercase text-gray-600 mb-3">Dívida actual</h3>
        <div class="row g-4 mb-4">
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Total em dívida</div><div class="fs-2 fw-bold text-danger">{{ dinheiro(relatorio.divida.total) }}</div><div class="text-muted fs-7">{{ relatorio.divida.matriculas }} matrícula(s)</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Propinas</div><div class="fs-4 fw-bold">{{ dinheiro(relatorio.divida.propinas) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Multas</div><div class="fs-4 fw-bold">{{ dinheiro(relatorio.divida.multas) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Produtos e serviços</div><div class="fs-4 fw-bold">{{ dinheiro(relatorio.divida.cobrancas) }}</div></div></div></div>
        </div>
        <div class="card">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-5 fw-bold">Dívida por turma</h3>
                <div class="card-toolbar"><a href="/financeiro/dividas" class="btn btn-sm btn-light-primary">Ver dívidas</a></div>
            </div>
            <div class="card-body pt-0">
                <p v-if="relatorio.divida.por_turma.length === 0" class="text-muted mb-0">Sem dívidas.</p>
                <table v-else class="table table-row-dashed align-middle fs-6 gy-3 mb-0">
                    <thead><tr class="text-muted fw-bold fs-7 text-uppercase"><th>Turma</th><th class="text-center">Matrículas</th><th class="text-end">Em dívida</th></tr></thead>
                    <tbody class="fw-semibold text-gray-800">
                        <tr v-for="t in relatorio.divida.por_turma" :key="t.turma"><td>{{ t.turma }}</td><td class="text-center">{{ t.matriculas }}</td><td class="text-end">{{ dinheiro(t.total) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        </Deferred>
    </div>
</template>
