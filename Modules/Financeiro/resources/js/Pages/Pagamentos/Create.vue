<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import DistribuicaoResumo from '../../Components/Pagamentos/DistribuicaoResumo.vue';
import { MODO_DISTRIBUICAO } from '../../Models/Pagamento';
import { formatarDinheiro, unidadesMenoresParaDecimal } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    metodos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    hoje: { type: String, required: true },
    matriculaInicial: { type: Object, default: null },
});
defineOptions({ layout: AppLayout });

const PASSOS = ['Matrícula', 'Valor e método', 'Distribuição', 'Resumo'];
const passo = ref(1);

const termo = ref('');
const resultados = ref([]);
const matricula = ref(null);
const situacao = ref(null);

const form = reactive({
    metodo_pagamento_id: '', // nada pré-seleccionado: o utilizador escolhe
    valor: '',
    data_pagamento: props.hoje,
    referencia: '',
    observacao: '',
    valor_credito: '',
});
const opcoesMetodo = computed(() => props.metodos.map((m) => ({ value: m.id, label: m.nome })));

const modo = ref(null); // nenhum modo pré-seleccionado: o utilizador escolhe;
const manual = reactive({}); // propina_id → texto decimal

const plano = ref(null);
const avisos = ref([]); // O1: avisos não bloqueantes devolvidos pela pré-visualização
const confirmaTudoCredito = ref(false);
const errors = ref({});
const ordemEnviada = ref([]); // propina_id de cada posição de `distribuicao` no último pedido: o servidor indexa os erros por posição (R-PA.4)
const aPrever = ref(false);
const processing = ref(false);

const tudoParaCredito = computed(() => plano.value !== null && plano.value.linhas.length === 0 && plano.value.excedente > 0);
const podeConfirmar = computed(() => plano.value !== null && !processing.value && (!tudoParaCredito.value || confirmaTudoCredito.value));
const bloqueadaPorAntecipado = (propina) => propina.futura && !situacao.value?.regras.permite_pagamento_antecipado;
const erroDaPropina = (propinaId) => {
    const posicao = ordemEnviada.value.indexOf(propinaId);

    return posicao < 0 ? undefined : (errors.value[`distribuicao.${posicao}.valor`] ?? errors.value[`distribuicao.${posicao}.propina_id`]);
};
// Erros de linhas que já não existem na tabela (ex.: propina que entretanto saiu da dívida): vão para o aviso geral.
const errosDeLinhasForaDaTabela = computed(() => Object.entries(errors.value)
    .filter(([campo]) => /^distribuicao\.(\d+)\./.test(campo))
    .filter(([campo]) => !situacao.value?.propinas.some((p) => ordemEnviada.value.indexOf(p.id) === Number(campo.match(/^distribuicao\.(\d+)\./)[1])))
    .map(([, mensagem]) => mensagem));
const primeiroErro = (erros) => Object.fromEntries(Object.entries(erros ?? {}).map(([campo, msgs]) => [campo, Array.isArray(msgs) ? msgs[0] : msgs]));

let debounceId = null;
watch(termo, (valor) => {
    clearTimeout(debounceId);

    if (valor.trim().length < 2) {
        resultados.value = [];
        return;
    }

    debounceId = setTimeout(async () => {
        const { data } = await axios.get('/financeiro/pagamentos/matriculas', { params: { q: valor.trim() } });
        resultados.value = data.matriculas;
    }, 300);
});

async function escolher(escolhida) {
    const { data } = await axios.get(`/financeiro/pagamentos/matriculas/${escolhida.id}/situacao`);
    matricula.value = escolhida;
    situacao.value = data.situacao;
    Object.keys(manual).forEach((chave) => delete manual[chave]);
    plano.value = null;
    passo.value = 2;
}

onMounted(() => {
    if (props.matriculaInicial) escolher(props.matriculaInicial);
});

function base() {
    return {
        metodo_pagamento_id: form.metodo_pagamento_id,
        valor: form.valor,
        data_pagamento: form.data_pagamento,
        referencia: form.referencia,
        observacao: form.observacao,
        valor_credito: form.valor_credito || '0',
    };
}

function pedidoDaEdicao() {
    if (modo.value === MODO_DISTRIBUICAO.AUTOMATICA) {
        return { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.AUTOMATICA };
    }

    const distribuicao = Object.entries(manual)
        .filter(([, valor]) => String(valor ?? '').trim() !== '')
        .map(([id, valor]) => ({ propina_id: Number(id), valor: String(valor).trim() }));
    ordemEnviada.value = distribuicao.map((l) => l.propina_id);

    return { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao };
}

async function preVisualizar() {
    if (aPrever.value || !modo.value) return;
    aPrever.value = true;
    errors.value = {};
    plano.value = null;
    avisos.value = [];
    confirmaTudoCredito.value = false;

    try {
        const { data } = await axios.post(`/financeiro/pagamentos/matriculas/${matricula.value.id}/pre-visualizar`, pedidoDaEdicao());
        plano.value = data.plano;
        avisos.value = data.avisos ?? [];
        passo.value = 4;
    } catch (erro) {
        if (erro.response?.status !== 422) {
            toast.error('Não foi possível calcular a distribuição. Tente de novo.');
            return;
        }

        errors.value = primeiroErro(erro.response.data.errors);
        toast.error(Object.values(errors.value)[0]);
        passo.value = ['metodo_pagamento_id', 'valor', 'data_pagamento', 'referencia', 'observacao', 'valor_credito'].some((c) => errors.value[c]) ? 2 : 3;
    } finally {
        aPrever.value = false;
    }
}

/**
 * Confirma EXACTAMENTE o que o resumo mostrou (D19): distribuição explícita (crédito + dinheiro por propina), mesmo
 * vazia. O servidor revalida tudo sob bloqueio; se os dados mudaram entretanto, recusa e volta-se à distribuição.
 */
function confirmar() {
    if (!podeConfirmar.value) return;
    processing.value = true;
    const distribuicao = plano.value.linhas.map((l) => ({ propina_id: l.propina_id, valor: unidadesMenoresParaDecimal(l.credito + l.dinheiro, props.moeda) }));
    ordemEnviada.value = distribuicao.map((l) => l.propina_id);

    router.post(`/financeiro/pagamentos/matriculas/${matricula.value.id}`, { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao }, {
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
            passo.value = 3;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/financeiro/pagamentos" />
        <h1 class="fs-2 fw-bold mb-6">Registar pagamento</h1>

        <div class="d-flex flex-wrap gap-3 mb-6">
            <span v-for="(nome, i) in PASSOS" :key="nome" class="badge fs-7" :class="passo === i + 1 ? 'badge-primary' : 'badge-light'">{{ i + 1 }}. {{ nome }}</span>
        </div>

        <!-- 1. Matrícula -->
        <div v-if="passo === 1" class="card">
            <div class="card-body">
                <label class="form-label required fw-semibold">Matrícula</label>
                <input v-model="termo" type="text" class="form-control form-control-solid mb-4" placeholder="Nome do aluno, nº do aluno ou nº da matrícula (mín. 2 caracteres)" autofocus />
                <p v-if="termo.trim().length >= 2 && resultados.length === 0" class="text-muted fs-7">Nenhuma matrícula encontrada.</p>
                <div class="list-group">
                    <button v-for="m in resultados" :key="m.id" type="button" class="list-group-item list-group-item-action" @click="escolher(m)">
                        <strong>{{ m.aluno_nome ?? '—' }}</strong> <span class="text-muted">· nº {{ m.aluno_numero ?? '—' }}</span>
                        <div class="fs-7 text-muted">Matrícula {{ m.numero }} · {{ m.turma_nome ?? '—' }} · {{ m.ano_lectivo_nome ?? '—' }} · {{ m.estado_descricao }}</div>
                    </button>
                </div>
            </div>
        </div>

        <!-- Cabeçalho da matrícula escolhida (passos 2–4) -->
        <div v-if="passo > 1 && matricula" class="card bg-body-secondary mb-6">
            <div class="card-body py-4 d-flex justify-content-between align-items-center">
                <div>
                    <strong>{{ matricula.aluno_nome }}</strong> · matrícula {{ matricula.numero }} · {{ matricula.turma_nome ?? '—' }} · {{ matricula.ano_lectivo_nome ?? '—' }}
                    <div class="fs-7 text-muted">
                        Crédito disponível do aluno: {{ formatarDinheiro(situacao?.credito_disponivel ?? 0, moeda) }} ·
                        Pagamento parcial {{ situacao?.regras.permite_pagamento_parcial ? 'permitido' : 'não permitido' }} ·
                        Antecipado {{ situacao?.regras.permite_pagamento_antecipado ? 'permitido' : 'não permitido' }}
                    </div>
                </div>
                <button type="button" class="btn btn-light btn-sm" @click="passo = 1">Trocar</button>
            </div>
        </div>

        <!-- 2. Valor e método -->
        <div v-if="passo === 2" class="card">
            <div class="card-body">
                <div class="row g-5">
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Valor recebido ({{ moeda.simbolo }})</label>
                        <input v-model="form.valor" type="text" inputmode="decimal" class="form-control form-control-solid" :class="{ 'is-invalid': errors.valor }" placeholder="Ex.: 25000,00" />
                        <div class="text-danger fs-7">{{ errors.valor }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Método</label>
                        <SelectSolid v-model="form.metodo_pagamento_id" :options="opcoesMetodo" />
                        <div class="text-danger fs-7">{{ errors.metodo_pagamento_id }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Data do pagamento</label>
                        <input v-model="form.data_pagamento" type="date" :min="situacao?.matricula?.data_matricula" :max="hoje" class="form-control form-control-solid" :class="{ 'is-invalid': errors.data_pagamento }" />
                        <div class="text-danger fs-7">{{ errors.data_pagamento }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Referência</label>
                        <input v-model="form.referencia" type="text" maxlength="100" class="form-control form-control-solid" :class="{ 'is-invalid': errors.referencia }" placeholder="Nº da transferência, do talão…" />
                        <div class="text-danger fs-7">{{ errors.referencia }}</div>
                    </div>
                    <div v-if="(situacao?.credito_disponivel ?? 0) > 0" class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Usar crédito do aluno ({{ moeda.simbolo }})</label>
                        <input v-model="form.valor_credito" type="text" inputmode="decimal" class="form-control form-control-solid" :class="{ 'is-invalid': errors.valor_credito }" placeholder="0" />
                        <div class="text-muted fs-8">Usado antes do dinheiro novo. Disponível: {{ formatarDinheiro(situacao.credito_disponivel, moeda) }}</div>
                        <div class="text-danger fs-7">{{ errors.valor_credito }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Observação</label>
                        <textarea v-model="form.observacao" rows="2" maxlength="1000" class="form-control form-control-solid"></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-6">
                    <button type="button" class="btn btn-primary" :disabled="!form.valor || !form.metodo_pagamento_id" @click="passo = 3">Continuar</button>
                </div>
            </div>
        </div>

        <!-- 3. Distribuição -->
        <div v-if="passo === 3" class="card">
            <div class="card-body">
                <div class="d-flex gap-6 mb-5">
                    <label class="form-check form-check-custom form-check-solid">
                        <input v-model="modo" class="form-check-input" type="radio" :value="MODO_DISTRIBUICAO.AUTOMATICA" />
                        <span class="form-check-label">Automática (da mais antiga para a mais recente)</span>
                    </label>
                    <label class="form-check form-check-custom form-check-solid">
                        <input v-model="modo" class="form-check-input" type="radio" :value="MODO_DISTRIBUICAO.EXPLICITA" />
                        <span class="form-check-label">Escolher por propina</span>
                    </label>
                </div>
                <div v-if="!modo" class="text-muted fs-7 mb-4">Escolha como distribuir o valor para ver o resumo.</div>
                <div v-if="errors.distribuicao" class="alert alert-danger fs-7 py-3">{{ errors.distribuicao }}</div>
                <div v-if="errosDeLinhasForaDaTabela.length" class="alert alert-danger fs-7 py-3">
                    <div v-for="(mensagem, indice) in errosDeLinhasForaDaTabela" :key="indice">{{ mensagem }}</div>
                </div>
                <p v-if="!situacao?.propinas.length" class="text-muted">Esta matrícula não tem propinas em dívida: o valor ficará como crédito do aluno.</p>
                <div v-else class="table-responsive">
                    <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                        <thead>
                            <tr class="text-muted fw-bold text-uppercase">
                                <th>Período</th><th>Vencimento</th><th>Estado</th><th class="text-end">Em dívida</th>
                                <th v-if="modo === MODO_DISTRIBUICAO.EXPLICITA" class="text-end min-w-150px">Pagar ({{ moeda.simbolo }})</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="propina in situacao.propinas" :key="propina.id">
                                <td>
                                    {{ propina.periodo }}
                                    <span v-if="bloqueadaPorAntecipado(propina)" class="badge badge-light-warning ms-1" title="A escola não permite pagamento antecipado.">Futura</span>
                                </td>
                                <td>{{ dataCurta(propina.data_vencimento) }}</td>
                                <td><EstadoCobrancaBadge :estado="propina.estado" :estado-descricao="propina.estado_descricao" /></td>
                                <td class="text-end">{{ formatarDinheiro(propina.saldo, moeda) }}</td>
                                <td v-if="modo === MODO_DISTRIBUICAO.EXPLICITA" class="text-end">
                                    <input v-model="manual[propina.id]" type="text" inputmode="decimal" class="form-control form-control-sm form-control-solid text-end"
                                        :disabled="bloqueadaPorAntecipado(propina)"
                                        :placeholder="situacao.regras.permite_pagamento_parcial ? '0' : unidadesMenoresParaDecimal(propina.saldo, moeda)" />
                                    <div class="text-danger fs-8">{{ erroDaPropina(propina.id) }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between mt-6">
                    <button type="button" class="btn btn-light" @click="passo = 2">Voltar</button>
                    <button type="button" class="btn btn-primary" :disabled="aPrever || !modo" @click="preVisualizar">{{ aPrever ? 'A calcular…' : 'Ver resumo' }}</button>
                </div>
            </div>
        </div>

        <!-- 4. Resumo -->
        <div v-if="passo === 4 && plano" class="card">
            <div class="card-body">
                <DistribuicaoResumo :plano="plano" :moeda="moeda" />
                <div v-for="aviso in avisos" :key="aviso" class="alert alert-warning fs-7 mt-5 mb-0">{{ aviso }}</div>
                <div v-if="tudoParaCredito" class="alert alert-warning fs-7 mt-5">
                    <strong>Nada a distribuir: o valor fica como crédito do aluno.</strong>
                    Se esperava pagar uma propina, verifique se já não foi paga (por exemplo, um registo em duplicado).
                    <label class="form-check form-check-custom form-check-solid mt-3">
                        <input v-model="confirmaTudoCredito" class="form-check-input" type="checkbox" />
                        <span class="form-check-label">Confirmo que o valor fica todo como crédito do aluno.</span>
                    </label>
                </div>
                <div class="d-flex justify-content-between mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="passo = 3">Voltar</button>
                    <button type="button" class="btn btn-primary" :disabled="!podeConfirmar" @click="confirmar">{{ processing ? 'A registar…' : 'Confirmar pagamento' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
