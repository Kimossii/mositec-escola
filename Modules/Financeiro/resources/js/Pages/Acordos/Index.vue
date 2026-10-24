<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltrosRecolhiveis from '../../Components/Shared/FiltrosRecolhiveis.vue';
import IndicadoresDiferidos from '../../Components/Shared/IndicadoresDiferidos.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    acordos: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    moeda: { type: Object, required: true },
    indicadores: { type: Object, default: null }, // diferido: chega depois da lista
});
defineOptions({ layout: AppLayout });

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const filtros = reactive({ pesquisa: props.filtros.pesquisa ?? '', estado: props.filtros.estado === undefined || props.filtros.estado === '' ? '' : Number(props.filtros.estado) });
const opcoesEstado = [{ value: '', label: 'Todos os estados' }, { value: 1, label: 'Activos' }, { value: 2, label: 'Cancelados' }, { value: 3, label: 'Quebrados' }];
const montarIndicadores = (i) => [
    { rotulo: 'Acordos activos', valor: String(i.activos), nota: `${i.cancelados} cancelado(s) ou quebrado(s)`, cor: 'primary' },
    { rotulo: 'Em atraso', valor: String(i.em_atraso), nota: 'com prestação vencida', cor: 'danger' },
    { rotulo: 'Cumpridos', valor: String(i.cumpridos), nota: 'dívida totalmente paga', cor: 'success' },
    { rotulo: 'Em falta', valor: dinheiro(i.em_falta), nota: `${dinheiro(i.pago)} já recuperados`, cor: 'warning' },
];
const filtrosAtivos = computed(() => [filtros.estado !== ''].filter(Boolean).length);
const limparFiltros = () => Object.assign(filtros, { estado: '' });

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => router.get('/financeiro/acordos', valor, { preserveState: true, preserveScroll: true, replace: true }), 300);
});
const badge = (a) => ({ Cumprido: 'badge-light-success', 'Em atraso': 'badge-light-danger', Activo: 'badge-light-primary' }[a.estado_efectivo] ?? 'badge-light-dark');
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Acordos de dívida</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Dívidas pagas em prestações. Enquanto o acordo está activo, as multas ficam congeladas e o pagamento parcial é permitido nas propinas incluídas.
                </p>
            </div>
            <Link v-if="can('acordo.criar')" href="/financeiro/acordos/novo" class="btn btn-primary">Novo acordo</Link>
        </div>

        <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" />

        <FiltrosRecolhiveis v-model="filtros.pesquisa" placeholder="Pesquisar aluno, nº do aluno ou da matrícula" :ativos="filtrosAtivos" @limpar="limparFiltros">
                    <div class="col-12 col-md-4"><label class="fw-semibold fs-7 text-muted mb-1">Estado</label><SelectSolid v-model="filtros.estado" :options="opcoesEstado" /></div>
        </FiltrosRecolhiveis>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-75px">Nº</th>
                                <th class="min-w-200px">Aluno</th>
                                <th class="text-end min-w-110px">Total</th>
                                <th class="text-end min-w-110px">Pago</th>
                                <th class="text-end min-w-110px">Em falta</th>
                                <th class="min-w-150px">Próxima prestação</th>
                                <th class="min-w-110px">Estado</th>
                                <th class="text-end min-w-75px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="acordos.data.length === 0"><td colspan="8" class="text-center text-muted py-6">Nenhum acordo encontrado.</td></tr>
                            <tr v-for="a in acordos.data" :key="a.id">
                                <td class="text-gray-800">#{{ a.id }}</td>
                                <td class="text-gray-800"><span class="text-uppercase">{{ a.aluno_nome ?? '—' }}</span><div class="text-muted fs-7">nº {{ a.aluno_numero ?? '—' }} · matrícula {{ a.matricula_numero }}</div></td>
                                <td class="text-end">{{ dinheiro(a.total) }}</td>
                                <td class="text-end text-success">{{ dinheiro(a.pago) }}</td>
                                <td class="text-end">{{ dinheiro(a.em_falta) }}</td>
                                <td><template v-if="a.proxima">{{ dataCurta(a.proxima.data_vencimento) }}<div class="text-muted fs-7">{{ a.proxima.ordem }}.ª prestação</div></template><span v-else>—</span></td>
                                <td><span class="badge" :class="badge(a)">{{ a.estado_efectivo }}</span></td>
                                <td class="text-end"><Link :href="`/financeiro/acordos/${a.id}`" class="btn btn-sm btn-light btn-active-light-primary">Ver</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="acordos.data.length" class="card-footer d-flex justify-content-end"><Pagination :links="acordos.links" /></div>
        </div>
    </div>
</template>
