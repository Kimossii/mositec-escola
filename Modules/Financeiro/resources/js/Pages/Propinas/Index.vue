<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
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
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (valor) => (valor !== undefined && valor !== null && valor !== '' && Number.isFinite(Number(valor)) ? Number(valor) : '');

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


        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Aluno, nº do aluno ou nº da matrícula" />
                    </div>
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

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-175px">Aluno</th>
                                <th class="min-w-100px">Matrícula</th>
                                <th class="min-w-100px">Turma</th>
                                <th class="min-w-125px">Plano</th>
                                <th class="min-w-100px">Período</th>
                                <th class="min-w-100px">Vencimento</th>
                                <th class="text-end min-w-100px">Valor</th>
                                <th class="text-end min-w-100px">Pago</th>
                                <th class="text-end min-w-100px">Saldo</th>
                                <th class="text-end min-w-100px">Multa</th>
                                <th class="min-w-125px">Estado</th>
                                <th class="text-end min-w-75px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="propinas.data.length === 0">
                                <td colspan="12" class="text-center text-muted py-6">Nenhuma propina encontrada.</td>
                            </tr>
                            <tr v-for="propina in propinas.data" :key="propina.id">
                                <td class="text-gray-800">
                                    {{ propina.aluno_nome ?? '—' }}
                                    <div class="text-muted fs-7">nº {{ propina.aluno_numero ?? '—' }}</div>
                                </td>
                                <td>{{ propina.matricula_numero ?? '—' }}</td>
                                <td>{{ propina.turma_nome ?? '—' }}</td>
                                <td>{{ propina.plano_nome ?? '—' }}</td>
                                <td>{{ propina.periodo }}</td>
                                <td>
                                    {{ dataCurta(propina.data_vencimento) }}
                                    <div class="text-muted fs-8">limite {{ dataCurta(propina.data_limite) }}</div>
                                </td>
                                <td class="text-end">{{ formatarDinheiro(propina.valor, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(propina.valor_pago, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(propina.saldo, moeda) }}</td>
                                <td class="text-end">{{ propina.multa?.pendente ? formatarDinheiro(propina.multa.pendente, moeda) : '—' }}</td>
                                <td>
                                    <EstadoCobrancaBadge :estado="propina.estado" :estado-descricao="propina.estado_descricao" />
                                    <div class="mt-1">
                                        <span v-if="propina.origem_geracao === ORIGEM_GERACAO.RETROACTIVA" class="badge badge-light-warning" :title="propina.motivo_geracao ?? ''">Retroactiva</span>
                                        <span v-else-if="propina.geracao_tardia" class="badge badge-light-warning" title="Gerada depois do vencimento.">Geração tardia</span>
                                        <span v-else class="badge badge-light fs-8">{{ propina.origem_geracao_descricao }}</span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <Link :href="`${BASE}/${propina.id}`" class="btn btn-light btn-active-light-primary btn-sm">
                                        <AcaoIcone acao="visualizar" class="me-1" /> Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="propinas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="propinas.links" />
            </div>
        </div>

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
