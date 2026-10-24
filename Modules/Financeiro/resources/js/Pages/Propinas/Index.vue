<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, usePage, Deferred } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import GerarPropinasModal from '../../Components/Propinas/GerarPropinasModal.vue';
import { ORIGEM_GERACAO } from '../../Models/Propina';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const BASE = '/financeiro/propinas';

// Linhas com o detalhe aberto (matrícula, turma, plano, vencimento): a tabela fica estreita e as acções mais perto.
const abertas = ref([]);
const estaAberta = (id) => abertas.value.includes(id);
const alternar = (id) => {
    abertas.value = estaAberta(id) ? abertas.value.filter((x) => x !== id) : [...abertas.value, id];
};
// 'todos' distingue "sem filtro" de "filtro ausente" (que aplica o ano lectivo activo no servidor).
const TODOS = 'todos';

const props = defineProps({
    propinas: { type: Object, required: true }, // paginador: { data, links, total, ... }
    filtros: { type: Object, default: () => ({}) },
    anosLectivos: { type: Array, required: true },
    anoLectivoActualId: { type: Number, default: null },
    planos: { type: Array, required: true },
    turmas: { type: Array, required: true },
    niveis: { type: Array, required: true },
    estados: { type: Array, required: true },
    origens: { type: Array, required: true },
    moeda: { type: Object, required: true },
    indicadores: { type: Object, default: null }, // diferido: chega depois da lista
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (valor) => (valor !== undefined && valor !== null && valor !== '' && Number.isFinite(Number(valor)) ? Number(valor) : '');

// Filtros avançados escondidos por omissão (a pesquisa fica sempre à vista); abrem sozinhos se algum estiver activo.
const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const percentagemRecebida = computed(() => (props.indicadores?.emitido ? Math.min(100, Math.round((props.indicadores.recebido / props.indicadores.emitido) * 100)) : 0));

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    ano_lectivo_id: numeroOuVazio(props.filtros.ano_lectivo_id) || TODOS,
    estado: numeroOuVazio(props.filtros.estado),
    plano_propina_id: numeroOuVazio(props.filtros.plano_propina_id),
    matricula_id: numeroOuVazio(props.filtros.matricula_id),
    origem_geracao: numeroOuVazio(props.filtros.origem_geracao),
    vencimento_de: props.filtros.vencimento_de ?? '',
    vencimento_ate: props.filtros.vencimento_ate ?? '',
});

const opcoesAno = computed(() => [{ value: TODOS, label: 'Todos os anos lectivos' }, ...props.anosLectivos.map((a) => ({ value: a.id, label: a.nome }))]);
const opcoesEstado = computed(() => [{ value: '', label: 'Todos os estados' }, ...props.estados]);
const opcoesOrigem = computed(() => [{ value: '', label: 'Todas as origens' }, ...props.origens]);
const opcoesPlano = computed(() => [
    { value: '', label: 'Todos os planos' },
    ...props.planos
        .filter((p) => filtros.ano_lectivo_id === TODOS || p.ano_lectivo_id === filtros.ano_lectivo_id)
        .map((p) => ({ value: p.id, label: p.nome })),
]);

let debounceId = null;
const filtrosAtivos = computed(() => [
    filtros.estado !== '', filtros.plano_propina_id !== '', filtros.origem_geracao !== '', filtros.vencimento_de !== '', filtros.vencimento_ate !== '',
    filtros.ano_lectivo_id !== (props.anoLectivoActualId ?? filtros.ano_lectivo_id),
].filter(Boolean).length);
const mostrarFiltros = ref(filtrosAtivos.value > 0);
function limparFiltros() {
    Object.assign(filtros, { estado: '', plano_propina_id: '', origem_geracao: '', vencimento_de: '', vencimento_ate: '', ano_lectivo_id: props.anoLectivoActualId ?? TODOS });
}

watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const page = usePage();
const geracaoAberta = ref(false);
const geracaoProcessing = ref(false);
const geracaoErrors = ref({});
const resultadoGeracao = ref(null);
const anoParaGerar = computed(() => (filtros.ano_lectivo_id === TODOS ? props.anoLectivoActualId ?? props.anosLectivos[0]?.id ?? null : filtros.ano_lectivo_id));

function abrirGeracao() {
    geracaoErrors.value = {};
    resultadoGeracao.value = null;
    geracaoAberta.value = true;
}

function gerar(payload) {
    geracaoProcessing.value = true;
    geracaoErrors.value = {};

    router.post(`${BASE}/geracao`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            resultadoGeracao.value = page.props.flash?.geracao_propinas ?? null;
            const aviso = page.props.flash?.warning;
            if (aviso) toast.warning(aviso);
            else toast.success(page.props.flash?.success ?? 'Propinas geradas.');
        },
        onError: (erros) => {
            geracaoErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            geracaoProcessing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Propinas</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Cobranças de propina das matrículas, geradas a partir dos planos de propina. O estado é calculado para o dia de hoje
                    na escola: Pendente antes do período começar, Em Atraso depois do vencimento e da tolerância.
                </p>
            </div>
            <div v-if="can('propina.criar')">
                <button class="btn btn-primary" @click="abrirGeracao">Gerar propinas</button>
            </div>
        </div>


        <!-- Indicadores -->
        <Deferred data="indicadores">
            <template #fallback>
                <div class="row g-4 mb-6">
                    <div class="col-12 col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div>
                    <div class="col-12 col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div>
                    <div class="col-12 col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div>
                    <div class="col-12 col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body py-5 placeholder-glow"><span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span></div></div></div>
                </div>
            </template>
        <div class="row g-4 mb-6">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card h-100 border-start border-4 border-success"><div class="card-body py-5">
                    <div class="text-muted fs-8 fw-bold text-uppercase mb-1">Recebido</div>
                    <div class="fs-2 fw-bolder text-success lh-1">{{ dinheiro(indicadores.recebido) }}</div>
                    <div class="progress h-6px mt-3 mb-1"><div class="progress-bar bg-success" :style="{ width: `${percentagemRecebida}%` }"></div></div>
                    <div class="text-muted fs-7">{{ percentagemRecebida }}% de {{ dinheiro(indicadores.emitido) }} emitidos</div>
                </div></div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card h-100 border-start border-4 border-danger"><div class="card-body py-5">
                    <div class="text-muted fs-8 fw-bold text-uppercase mb-1">Em atraso</div>
                    <div class="fs-2 fw-bolder text-danger lh-1">{{ dinheiro(indicadores.em_atraso_valor) }}</div>
                    <div class="text-muted fs-7 mt-3">{{ indicadores.em_atraso_n }} propina(s) vencida(s)</div>
                </div></div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card h-100 border-start border-4 border-primary"><div class="card-body py-5">
                    <div class="text-muted fs-8 fw-bold text-uppercase mb-1">A vencer</div>
                    <div class="fs-2 fw-bolder text-primary lh-1">{{ dinheiro(indicadores.a_vencer_valor) }}</div>
                    <div class="text-muted fs-7 mt-3">{{ indicadores.a_vencer_n }} propina(s) ainda no prazo</div>
                </div></div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card h-100 border-start border-4 border-warning"><div class="card-body py-5">
                    <div class="text-muted fs-8 fw-bold text-uppercase mb-1">Multas por cobrar</div>
                    <div class="fs-2 fw-bolder text-warning lh-1">{{ dinheiro(indicadores.multas_valor) }}</div>
                    <div class="text-muted fs-7 mt-3">{{ indicadores.multas_n }} multa(s) em aberto</div>
                </div></div>
            </div>
        </div>
        </Deferred>

        <!-- Pesquisa e filtros (os avançados recolhem) -->
        <div class="card mb-6">
            <div class="card-body py-4">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid flex-grow-1 mw-md-450px" placeholder="Pesquisar aluno, nº do aluno ou nº da matrícula" />
                    <button type="button" class="btn btn-light" :aria-expanded="mostrarFiltros" @click="mostrarFiltros = !mostrarFiltros">
                        Filtros<span v-if="filtrosAtivos" class="badge badge-circle badge-primary ms-2">{{ filtrosAtivos }}</span>
                        <span class="ms-1">{{ mostrarFiltros ? '▴' : '▾' }}</span>
                    </button>
                    <button v-if="filtrosAtivos" type="button" class="btn btn-light-danger" @click="limparFiltros">Limpar filtros</button>
                </div>
                <div v-show="mostrarFiltros" class="row g-4 mt-1">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                        <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAno" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Plano</label>
                        <SelectSolid v-model="filtros.plano_propina_id" :options="opcoesPlano" searchable />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Origem</label>
                        <SelectSolid v-model="filtros.origem_geracao" :options="opcoesOrigem" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Vencimento de</label>
                        <input v-model="filtros.vencimento_de" type="date" class="form-control form-control-solid" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Vencimento até</label>
                        <input v-model="filtros.vencimento_ate" type="date" class="form-control form-control-solid" />
                    </div>
                </div>
            </div>
        </div>

        <ListaComVistas chave="financeiro-propinas" prefixo="/financeiro/propinas">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="w-40px"></th>
                                <th class="min-w-175px">Aluno</th>
                                <th class="min-w-100px">Período</th>
                                <th class="text-end min-w-100px">Valor</th>
                                <th class="text-end min-w-100px">Pago</th>
                                <th class="text-end min-w-100px">Saldo</th>
                                <th class="text-end min-w-100px">Multa</th>
                                <th class="min-w-125px">Estado</th>
                                <th class="text-end min-w-100px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="propinas.data.length === 0">
                                <td colspan="9" class="text-center text-muted py-6">Nenhuma propina encontrada.</td>
                            </tr>
                            <template v-for="propina in propinas.data" :key="propina.id">
                            <tr>
                                <td>
                                    <button type="button" class="btn btn-icon btn-sm btn-light" :title="estaAberta(propina.id) ? 'Recolher detalhes' : 'Ver detalhes'" :aria-expanded="estaAberta(propina.id)" @click="alternar(propina.id)">
                                        <i class="ki-duotone ki-down fs-5" :style="{ transform: estaAberta(propina.id) ? 'none' : 'rotate(-90deg)' }"></i>
                                    </button>
                                </td>
                                <td class="text-gray-800">
                                    {{ propina.aluno_nome ?? '—' }}
                                    <div class="text-muted fs-7">nº {{ propina.aluno_numero ?? '—' }}</div>
                                </td>
                                <td>{{ propina.periodo }}</td>
                                <td class="text-end">{{ formatarDinheiro(propina.valor, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(propina.valor_pago, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(propina.saldo, moeda) }}</td>
                                <td class="text-end">
                                    <template v-if="propina.multa && propina.multa.estado !== 4 && propina.multa.valor > 0">
                                        {{ formatarDinheiro(propina.multa.valor, moeda) }}
                                        <div v-if="propina.multa.pendente > 0" class="fs-8 text-danger">pendente {{ formatarDinheiro(propina.multa.pendente, moeda) }}</div>
                                        <div v-else class="fs-8 text-success">paga</div>
                                    </template>
                                    <template v-else>—</template>
                                </td>
                                <td>
                                    <EstadoCobrancaBadge :estado="propina.estado" :estado-descricao="propina.estado_descricao" />
                                    <div class="mt-1">
                                        <span v-if="propina.origem_geracao === ORIGEM_GERACAO.RETROACTIVA" class="badge badge-light-warning" :title="propina.motivo_geracao ?? ''">Retroactiva</span>
                                        <span v-else-if="propina.geracao_tardia" class="badge badge-light-warning" title="Gerada depois do vencimento.">Geração tardia</span>
                                        <span v-else class="badge badge-light fs-8">{{ propina.origem_geracao_descricao }}</span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                        Ações
                                        <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                    </a>
                                    <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                        <div class="menu-item px-3">
                                            <Link :href="`${BASE}/${propina.id}`" class="menu-link px-3">
                                                <AcaoIcone acao="visualizar" class="me-2" /> Ver
                                            </Link>
                                        </div>
                                        <div v-if="propina.ultimo_pagamento_id && can('pagamento.ver')" class="menu-item px-3">
                                            <a :href="`/financeiro/pagamentos/${propina.ultimo_pagamento_id}/recibo/propinas/${propina.id}`" target="_blank" class="menu-link px-3">
                                                Imprimir recibo
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="estaAberta(propina.id)">
                                <td colspan="9" class="bg-body-secondary py-4 px-8">
                                    <div class="row g-4 fs-7">
                                        <div class="col-6 col-md-3"><div class="text-muted">Matrícula</div><div class="fw-bold text-gray-800">{{ propina.matricula_numero ?? '—' }}</div></div>
                                        <div class="col-6 col-md-3"><div class="text-muted">Turma</div><div class="fw-bold text-gray-800">{{ propina.turma_nome ?? '—' }}</div></div>
                                        <div class="col-6 col-md-3"><div class="text-muted">Plano</div><div class="fw-bold text-gray-800">{{ propina.plano_nome ?? '—' }}</div></div>
                                        <div class="col-6 col-md-3"><div class="text-muted">Vencimento</div><div class="fw-bold text-gray-800">{{ dataCurta(propina.data_vencimento) }} <span class="text-muted fw-normal">· limite {{ dataCurta(propina.data_limite) }}</span></div></div>
                                    </div>
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="propinas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="propinas.links" />
            </div>
        </div>
        </ListaComVistas>

        <GerarPropinasModal
            :show="geracaoAberta"
            :anos-lectivos="anosLectivos"
            :ano-lectivo-id="anoParaGerar"
            :turmas="turmas"
            :niveis="niveis"
            :planos="planos"
            :moeda="moeda"
            :pode-incluir-anteriores="can('propina.ajustar')"
            :resultado="resultadoGeracao"
            :processing="geracaoProcessing"
            :errors="geracaoErrors"
            @confirmar="gerar"
            @cancelar="geracaoAberta = false"
        />
    </div>
</template>
