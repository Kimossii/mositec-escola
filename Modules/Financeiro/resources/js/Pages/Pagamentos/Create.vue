<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import DistribuicaoResumo from '../../Components/Pagamentos/DistribuicaoResumo.vue';
import { MODO_DISTRIBUICAO } from '../../Models/Pagamento';
import { decimalParaUnidadesMenores, formatarDinheiro, unidadesMenoresParaDecimal } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    metodos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    hoje: { type: String, required: true },
    matriculaInicial: { type: Object, default: null },
    voltarPara: { type: String, default: null },
});
defineOptions({ layout: AppLayout });

const PASSOS = ['Matrícula', 'Valor e método', 'Distribuição', 'Resumo'];
const passo = ref(1);
const passoMaximo = ref(1); // o passo mais avançado a que já se chegou: a barra de passos deixa voltar e avançar até aí

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
    ate: '', // «Pagar até [mês]» (YYYY-MM), opcional
});
const opcoesMetodo = computed(() => props.metodos.map((m) => ({ value: m.id, label: m.nome })));

const TIPO_NUMERARIO = 1;
const TIPO_TPA = 3;

// Comprovativo: só para métodos que não sejam Numerário nem TPA; obrigatório se o método tiver exige_comprovativo.
const comprovativo = ref(null);
const inputComprovativo = ref(null);
const metodoEscolhido = computed(() => props.metodos.find((m) => m.id === form.metodo_pagamento_id) ?? null);
const mostraComprovativo = computed(() => {
    const tipo = metodoEscolhido.value?.tipo;
    return tipo !== undefined && Number(tipo) !== TIPO_NUMERARIO && Number(tipo) !== TIPO_TPA;
});
const exigeComprovativo = computed(() => !!metodoEscolhido.value?.exige_comprovativo);

function aoEscolherComprovativo(event) {
    comprovativo.value = event.target.files?.[0] ?? null;
}

// Trocar de método descarta o comprovativo escolhido (o novo método pode não o aceitar).
watch(() => form.metodo_pagamento_id, () => {
    comprovativo.value = null;
    if (inputComprovativo.value) inputComprovativo.value.value = '';
});

// O método escolhe-se em dois passos: primeiro o TIPO (Multicaixa, Transferência, Numerário…), depois, numa mini
// tabela, o método concreto desse tipo com os seus dados (banco, IBAN, telefone…).
const tipoMetodo = ref('');
const opcoesTipo = computed(() => [...new Map(props.metodos.map((m) => [m.tipo, { value: m.tipo, label: m.tipo_descricao }])).values()]);
const metodosDoTipo = computed(() => props.metodos.filter((m) => m.tipo === tipoMetodo.value));
const COLUNAS_METODO = [
    { chave: 'titular', rotulo: 'Titular' },
    { chave: 'banco', rotulo: 'Banco' },
    { chave: 'iban', rotulo: 'IBAN' },
    { chave: 'numero_conta', rotulo: 'Nº conta' },
    { chave: 'telefone', rotulo: 'Telefone' },
];
// Só mostra as colunas que algum método do tipo escolhido tem preenchidas.
const colunasDoTipo = computed(() => COLUNAS_METODO.filter((c) => metodosDoTipo.value.some((m) => m[c.chave])));
watch(tipoMetodo, () => {
    form.metodo_pagamento_id = metodosDoTipo.value.length === 1 ? metodosDoTipo.value[0].id : '';
});

const modo = ref(MODO_DISTRIBUICAO.EXPLICITA); // «Escolher por propina» é o modo por defeito (decisão do dono)
const manual = reactive({}); // propina_id → texto decimal

const plano = ref(null);
const avisos = ref([]); // O1: avisos não bloqueantes devolvidos pela pré-visualização
const confirmaTudoCredito = ref(false);
const errors = ref({});
const ordemEnviada = ref([]); // propina_id de cada posição de `distribuicao` no último pedido: o servidor indexa os erros por posição (R-PA.4)
const aPrever = ref(false);
const processing = ref(false);
const aConfirmar = ref(false);

watch(passo, (novo) => {
    passoMaximo.value = Math.max(passoMaximo.value, novo);
});

// Mexer em qualquer dado invalida o resumo calculado: só se volta a avançar para lá com «Continuar».
watch([form, manual, modo], () => {
    plano.value = null;
    passoMaximo.value = Math.min(passoMaximo.value, passo.value);
}, { deep: true });

const passoAcessivel = (n) => n !== passo.value && !processing.value && !aPrever.value
    && !(n === 3 && (valorFixo.value || temAte.value)) // sem distribuição manual, o passo 3 é saltado
    && (n < passo.value || (matricula.value !== null && n <= passoMaximo.value && (n < 4 || plano.value !== null)));

function irParaPasso(n) {
    if (passoAcessivel(n)) passo.value = n;
} // modal «Registar este pagamento?» antes de gravar

const mensagemDeConfirmacao = computed(() => {
    const unidades = decimalParaUnidadesMenores(form.valor, props.moeda);
    const valor = unidades === null ? form.valor : formatarDinheiro(unidades, props.moeda);
    const metodo = opcoesMetodo.value.find((m) => m.value === form.metodo_pagamento_id)?.label ?? '—';
    const credito = decimalParaUnidadesMenores(form.valor_credito || '0', props.moeda) ?? 0n;
    const comCredito = credito > 0n ? ` e usar ${formatarDinheiro(credito, props.moeda)} de crédito do aluno` : '';
    const cobre = periodosAPagar.value.length > 0 ? ` Cobre: ${periodosAPagar.value.join(' · ')}.` : '';

    return `Registar o pagamento de ${valor} por ${metodo}${comCredito}?${cobre} O pagamento fica registado e só pode ser corrigido anulando-o.`;
});

function confirmarNoModal() {
    aConfirmar.value = false;
    confirmar();
}

const tudoParaCredito = computed(() => plano.value !== null && plano.value.linhas.length === 0 && plano.value.excedente > 0);
const podeConfirmar = computed(() => plano.value !== null && !processing.value && (!tudoParaCredito.value || confirmaTudoCredito.value));
const bloqueadaPorAntecipado = (propina) => propina.futura && !situacao.value?.regras.permite_pagamento_antecipado;
const temAte = computed(() => (form.ate ?? '').trim() !== '');

// Meses já em dívida (do mais antigo para o mais recente): o operador escolhe quantos paga, sempre do mais antigo para a frente.
// Só meses com algo por pagar (capital ou multa): um mês já pago não aparece.
const mesesEmDivida = computed(() => (situacao.value?.propinas ?? []).filter((p) => !p.futura && p.total_em_divida > 0));
const opcoesMesesAPagar = computed(() => {
    const meses = mesesEmDivida.value;

    return meses.map((p, i) => ({
        value: i === meses.length - 1 ? '' : p.periodo_inicio,
        label: i === meses.length - 1
            ? `Todos os ${meses.length} meses em dívida (até ${p.periodo})`
            : (i === 0 ? `Apenas ${p.periodo}` : `De ${meses[0].periodo} a ${p.periodo} (${i + 1} meses)`),
    }));
});
const mesesAPagar = computed({
    get: () => (opcoesMesesAPagar.value.some((o) => o.value !== '' && o.value === form.ate) ? form.ate : ''),
    set: (valor) => { form.ate = valor; },
});

// Que mês(es) este pagamento cobre, em grande no topo: com o resumo calculado, os períodos que recebem valor; antes
// disso, os períodos em dívida (e, com «Adiantar meses», o mês até onde se avança).
const periodosAPagar = computed(() => {
    if (plano.value !== null) {
        return plano.value.linhas
            .filter((l) => l.credito + l.dinheiro + l.multa_credito + l.multa_dinheiro > 0)
            .map((l) => l.periodo);
    }

    const emDivida = mesesEmDivida.value
        .filter((p) => !temAte.value || p.periodo_inicio.slice(0, 7) <= form.ate.slice(0, 7))
        .map((p) => p.periodo);
    const ultimaEmDivida = mesesEmDivida.value.at(-1)?.periodo_inicio.slice(0, 7) ?? '';

    // «Até» além do último mês em dívida = adiantamento; dentro da dívida só limita quantos meses se pagam.
    return temAte.value && form.ate.slice(0, 7) > ultimaEmDivida ? [...emDivida, `até ${dataCurta(form.ate).slice(3)}`] : emDivida;
});
const minMes = computed(() => (situacao.value?.matricula?.data_matricula ?? props.hoje).slice(0, 10));
const ateAnoMes = () => (temAte.value ? form.ate.split('-') : null);

// O valor a pagar vem das propinas (capital + multas de hoje), não se escreve: com pagamento parcial desligado fica
// fixo no total em dívida; com parcial ligado vem sugerido e pode ser alterado.
const totalDevido = ref(0); // unidades menores
const totalPronto = ref(false);
const valorEditado = ref(false);
// Fixo no total só quando há algo em dívida; sem dívida e com pagamento antecipado o valor escreve-se (vira adiantamento).
const valorFixo = computed(() => situacao.value !== null && !situacao.value.regras.permite_pagamento_parcial && !(totalPronto.value && totalDevido.value === 0));
// Nada a regularizar e a escola não aceita adiantamentos: não há nada para registar.
const semNadaAPagar = computed(() => totalPronto.value && totalDevido.value === 0 && situacao.value !== null && !situacao.value.regras.permite_pagamento_antecipado);
// Sem pagamento antecipado, o «Pagar até» não passa do fim do mês corrente (o servidor revalida).
const maxAte = computed(() => {
    if (situacao.value === null || situacao.value.regras.permite_pagamento_antecipado) return undefined;
    const [ano, mes] = props.hoje.split('-').map(Number);

    return `${props.hoje.slice(0, 8)}${String(new Date(ano, mes, 0).getDate()).padStart(2, '0')}`;
});

// A razão concreta (vem dos dados da matrícula, não de suposições).
const motivoSemPagamento = computed(() => {
    const c = situacao.value?.cobrancas;
    const nome = matricula.value?.aluno_nome ?? 'O aluno';

    if (!c || c.geradas === 0) {
        return `Ainda não foram geradas propinas para esta matrícula. Gere-as em Financeiro → Propinas → Gerar propinas.`;
    }
    if (c.proxima) {
        return `${nome} não tem propinas nem multas em dívida. A próxima propina (${c.proxima.periodo}) só pode ser paga a partir de ${dataCurta(c.proxima.inicio)}, porque a escola não permite pagamento antecipado.`;
    }

    return `${nome} está em dia: as ${c.geradas} propina(s) geradas para esta matrícula estão pagas e não há multas por liquidar.`;
});

function sincronizarValor() {
    if (!totalPronto.value || (!valorFixo.value && valorEditado.value)) return;

    const credito = decimalParaUnidadesMenores(form.valor_credito || '0', props.moeda) ?? 0n;
    const aPagar = BigInt(totalDevido.value) - credito;
    form.valor = aPagar > 0n ? unidadesMenoresParaDecimal(aPagar, props.moeda) : '';
}

async function carregarTotal() {
    if (matricula.value === null) return;
    const ate = ateAnoMes();

    try {
        const { data } = await axios.get(`/financeiro/pagamentos/matriculas/${matricula.value.id}/total-devido`, {
            params: ate ? { ate_ano: Number(ate[0]), ate_mes: Number(ate[1]) } : {},
        });
        totalDevido.value = data.total_devido;
        totalPronto.value = true;
        errors.value = { ...errors.value, ate_mes: undefined };
        sincronizarValor();
    } catch (erro) {
        if (erro.response?.status === 422) {
            errors.value = { ...errors.value, ...primeiroErro(erro.response.data.errors) };
            toast.error(Object.values(primeiroErro(erro.response.data.errors))[0]);
        } else {
            toast.error('Não foi possível calcular o total em dívida.');
        }
    }
}
watch(() => form.ate, carregarTotal);
watch(() => form.valor_credito, sincronizarValor);
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

const marcada = (propina) => String(manual[propina.id] ?? '').trim() !== '';

// Sem saltos: marcar um mês marca (com o saldo inteiro) todos os anteriores ainda em dívida; desmarcar um mês desmarca os seguintes.
// `situacao.propinas` vem da mais antiga para a mais recente.
function pagarAteAqui(propina, marcar) {
    const propinas = situacao.value?.propinas ?? [];
    const posicao = propinas.findIndex((p) => p.id === propina.id);

    propinas.forEach((p, i) => {
        if (marcar && i <= posicao && !bloqueadaPorAntecipado(p) && !marcada(p)) {
            manual[p.id] = unidadesMenoresParaDecimal(p.total_em_divida, props.moeda);
        } else if (!marcar && i >= posicao) {
            manual[p.id] = '';
        }
    });
}

async function escolher(escolhida) {
    const { data } = await axios.get(`/financeiro/pagamentos/matriculas/${escolhida.id}/situacao`);
    matricula.value = escolhida;
    situacao.value = data.situacao;
    Object.keys(manual).forEach((chave) => delete manual[chave]);
    plano.value = null;
    valorEditado.value = false;
    totalPronto.value = false;
    form.valor = '';
    await carregarTotal();
    passo.value = 2;
}

onMounted(() => {
    if (props.matriculaInicial) escolher(props.matriculaInicial);
});

function base() {
    const ate = ateAnoMes();

    return {
        metodo_pagamento_id: form.metodo_pagamento_id,
        valor: form.valor,
        data_pagamento: form.data_pagamento,
        referencia: form.referencia,
        observacao: form.observacao,
        valor_credito: form.valor_credito || '0',
        ...(ate ? { ate_ano: Number(ate[0]), ate_mes: Number(ate[1]) } : {}),
    };
}

function pedidoDaEdicao() {
    // «Pagar até [mês]» é sempre automático: as propinas a gerar ainda não têm id para uma distribuição explícita.
    if (modo.value === MODO_DISTRIBUICAO.AUTOMATICA || temAte.value) {
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
        passo.value = ['metodo_pagamento_id', 'valor', 'data_pagamento', 'referencia', 'observacao', 'valor_credito', 'ate_ano', 'ate_mes', 'comprovativo'].some((c) => errors.value[c]) ? 2 : 3;
    } finally {
        aPrever.value = false;
    }
}

/**
 * Confirma EXACTAMENTE o que o resumo mostrou (D19): distribuição explícita (crédito + dinheiro por propina), mesmo
 * vazia. O servidor revalida tudo sob bloqueio; se os dados mudaram entretanto, recusa e volta-se à distribuição.
 * Com «Pagar até [mês]» a confirmação é automática (as propinas a gerar ainda não têm id).
 */
function confirmar() {
    if (!podeConfirmar.value) return;
    processing.value = true;

    const opcoes = (aoErro) => ({
        forceFormData: comprovativo.value !== null,
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
            aoErro();
        },
        onFinish: () => {
            processing.value = false;
        },
    });

    const comComprovativo = (payload) => (comprovativo.value !== null ? { ...payload, comprovativo: comprovativo.value } : payload);

    if (temAte.value) {
        router.post(
            `/financeiro/pagamentos/matriculas/${matricula.value.id}`,
            comComprovativo({ ...base(), modo_distribuicao: MODO_DISTRIBUICAO.AUTOMATICA }),
            opcoes(() => { passo.value = 2; }),
        );
        return;
    }

    const distribuicao = plano.value.linhas.map((l) => ({ propina_id: l.propina_id, valor: unidadesMenoresParaDecimal(l.credito + l.dinheiro + l.multa_credito + l.multa_dinheiro, props.moeda) }));
    ordemEnviada.value = distribuicao.map((l) => l.propina_id);

    router.post(
        `/financeiro/pagamentos/matriculas/${matricula.value.id}`,
        comComprovativo({ ...base(), modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao }),
        opcoes(() => { passo.value = errors.value.comprovativo ? 2 : 3; }),
    );
}

/**
 * Do passo 2 para a distribuição ou, directamente, para o resumo automático: com «Pagar até [mês]» e também quando o
 * pagamento parcial não é permitido (o valor é o total em dívida, não há nada a repartir à mão).
 */
function continuar() {
    if (temAte.value || valorFixo.value) {
        modo.value = MODO_DISTRIBUICAO.AUTOMATICA;
        preVisualizar();
    } else {
        passo.value = 3;
    }
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar :href="voltarPara ?? '/financeiro/pagamentos'" />
        <h1 class="fs-2 fw-bold mb-6">Registar pagamento</h1>

        <div class="d-flex flex-wrap gap-3 mb-6">
            <button
                v-for="(nome, i) in PASSOS"
                :key="nome"
                type="button"
                class="badge fs-7 border-0"
                :class="[passo === i + 1 ? 'badge-primary' : 'badge-light', passoAcessivel(i + 1) ? 'cursor-pointer' : '']"
                :disabled="passo !== i + 1 && !passoAcessivel(i + 1)"
                @click="irParaPasso(i + 1)"
            >{{ i + 1 }}. {{ nome }}</button>
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
                        <div class="fs-7 text-muted">Matrícula {{ m.numero }} · {{ m.turma_rotulo ?? '—' }} · {{ m.ano_lectivo_nome ?? '—' }} · {{ m.estado_descricao }}</div>
                    </button>
                </div>
            </div>
        </div>

        <!-- Cabeçalho da matrícula escolhida (passos 2–4) -->
        <div v-if="passo > 1 && matricula" class="card bg-body-secondary mb-6">
            <div class="card-body py-4 d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase fs-5 mb-1"><strong>{{ matricula.aluno_nome }}</strong> · nº de estudante {{ matricula.aluno_numero ?? '—' }} · matrícula {{ matricula.numero }} · {{ matricula.turma_rotulo ?? '—' }} · {{ matricula.ano_lectivo_nome ?? '—' }}</div>
                    <div class="fs-7 text-muted">
                        Crédito disponível do aluno: {{ formatarDinheiro(situacao?.credito_disponivel ?? 0, moeda) }} ·
                        Pagamento parcial {{ situacao?.regras.permite_pagamento_parcial ? 'permitido' : 'não permitido' }} ·
                        Antecipado {{ situacao?.regras.permite_pagamento_antecipado ? 'permitido' : 'não permitido' }}
                    </div>
                </div>
                <button type="button" class="btn btn-light btn-sm" @click="passo = 1">Trocar</button>
            </div>
        </div>

        <div v-if="passo >= 2 && situacao?.acordo" class="alert alert-primary d-flex flex-wrap justify-content-between align-items-center mb-6">
            <div>
                <strong>Acordo de pagamento activo (#{{ situacao.acordo.id }}).</strong>
                Pode pagar qualquer valor: abate a dívida do mês mais antigo para a frente.
                <span v-if="situacao.acordo.proxima">Próxima prestação: {{ situacao.acordo.proxima.ordem }}.ª, {{ dataCurta(situacao.acordo.proxima.data_vencimento) }} (em falta até lá: {{ formatarDinheiro(situacao.acordo.proxima.em_falta, moeda) }}).</span>
            </div>
            <a :href="`/financeiro/acordos/${situacao.acordo.id}`" class="btn btn-sm btn-light-primary">Ver acordo</a>
        </div>

        <div v-if="passo >= 2 && matricula !== null && periodosAPagar.length > 0" class="rounded bg-light-primary px-6 py-5 mb-6">
            <div class="text-muted fs-7 fw-semibold text-uppercase mb-1">{{ plano !== null ? 'Este pagamento cobre' : 'Em dívida' }}</div>
            <h1 class="fs-1 fw-bold text-gray-900 mb-0">{{ periodosAPagar.join(' · ') }}</h1>
        </div>

        <!-- 2. Valor e método -->
        <div v-if="passo === 2 && semNadaAPagar" class="card">
            <div class="card-body text-center py-12">
                <div class="mb-5"><i class="ki-duotone ki-shield-tick fs-5x text-success"><span class="path1"></span><span class="path2"></span></i></div>
                <h3 class="fw-bold text-gray-900 mb-3">Sem valores por regularizar</h3>
                <p class="text-gray-700 fs-6 mx-auto mb-8" style="max-width: 560px;">{{ motivoSemPagamento }}</p>
                <div class="d-flex justify-content-center gap-3">
                    <button v-if="voltarPara" type="button" class="btn btn-light" @click="router.visit(voltarPara)">Voltar ao aluno</button>
                    <button v-else type="button" class="btn btn-light" @click="passo = 1">Escolher outra matrícula</button>
                    <a href="/financeiro/propinas" class="btn btn-primary" @click.prevent="router.visit('/financeiro/propinas')">Ver propinas</a>
                </div>
            </div>
        </div>

        <div v-if="passo === 2 && !semNadaAPagar" class="card">
            <div class="card-body">
                <div class="row g-5">
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Valor recebido ({{ moeda.simbolo }})</label>
                        <input v-model="form.valor" type="text" inputmode="decimal" :readonly="valorFixo" class="form-control form-control-solid" :class="{ 'is-invalid': errors.valor }" placeholder="Ex.: 25000,00" @input="valorEditado = true" />
                        <div v-if="totalPronto && totalDevido === 0" class="text-muted fs-8">Não há valores em dívida: o valor recebido fica como pagamento adiantado.</div>
                        <div v-else-if="valorFixo && totalPronto" class="text-muted fs-8">Pagamento parcial não permitido: assume o total {{ temAte ? 'dos meses escolhidos' : 'em dívida' }} ({{ formatarDinheiro(totalDevido, moeda) }}, capital e multas).</div>
                        <div v-else-if="totalPronto" class="text-muted fs-8">Sugerido: total em dívida ({{ formatarDinheiro(totalDevido, moeda) }}). Pode alterar.</div>
                        <div class="text-danger fs-7">{{ errors.valor }}</div>
                    </div>
                    <div v-if="mesesEmDivida.length > 1" class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Meses a pagar</label>
                        <SelectSolid v-model="mesesAPagar" :options="opcoesMesesAPagar" />
                        <div class="text-muted fs-8">Paga-se sempre do mês mais antigo para a frente, sem saltar meses.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Método</label>
                        <SelectSolid v-model="tipoMetodo" :options="opcoesTipo" placeholder="Selecione o tipo" />
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
                    <div v-if="mostraComprovativo" class="col-12 col-md-4">
                        <label class="form-label fw-semibold" :class="{ required: exigeComprovativo }">Comprovativo</label>
                        <input ref="inputComprovativo" type="file" accept="image/*,application/pdf" capture="environment" class="form-control form-control-solid" :class="{ 'is-invalid': errors.comprovativo }" @change="aoEscolherComprovativo" />
                        <div class="text-muted fs-8">{{ exigeComprovativo ? 'Obrigatório. ' : 'Opcional. ' }}PDF, JPG, JPEG, PNG ou WEBP, até 5 MB.</div>
                        <div class="text-danger fs-7">{{ errors.comprovativo }}</div>
                    </div>
                    <div v-if="(situacao?.credito_disponivel ?? 0) > 0" class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Usar crédito do aluno ({{ moeda.simbolo }})</label>
                        <input v-model="form.valor_credito" type="text" inputmode="decimal" class="form-control form-control-solid" :class="{ 'is-invalid': errors.valor_credito }" placeholder="0" />
                        <div class="text-muted fs-8">Usado antes do dinheiro novo. Disponível: {{ formatarDinheiro(situacao.credito_disponivel, moeda) }}</div>
                        <div class="text-danger fs-7">{{ errors.valor_credito }}</div>
                    </div>
                    <div v-if="situacao?.regras.permite_pagamento_antecipado" class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Adiantar meses até (data)</label>
                        <input v-model="form.ate" type="date" :min="minMes" :max="maxAte" class="form-control form-control-solid" :class="{ 'is-invalid': errors.ate_mes }" />
                        <div class="text-muted fs-8">Opcional. Por omissão paga o que já está em dívida. Escolha uma data para adiantar também os meses seguintes (as propinas em falta são geradas; o mês da data conta por inteiro).</div>
                        <div class="text-danger fs-7">{{ errors.ate_mes }}</div>
                    </div>
                    <div v-if="tipoMetodo !== ''" class="col-12">
                        <div class="table-responsive border rounded">
                            <table class="table table-row-dashed align-middle fs-7 gy-3 mb-0">
                                <thead>
                                    <tr class="text-muted fw-bold text-uppercase fs-8">
                                        <th class="w-30px"></th>
                                        <th>Nome</th>
                                        <th v-for="c in colunasDoTipo" :key="c.chave">{{ c.rotulo }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="m in metodosDoTipo"
                                        :key="m.id"
                                        role="button"
                                        :class="{ 'bg-body-secondary': form.metodo_pagamento_id === m.id }"
                                        @click="form.metodo_pagamento_id = m.id"
                                    >
                                        <td><input type="radio" class="form-check-input" :checked="form.metodo_pagamento_id === m.id" @click.stop="form.metodo_pagamento_id = m.id" /></td>
                                        <td class="fw-semibold">{{ m.nome }}</td>
                                        <td v-for="c in colunasDoTipo" :key="c.chave">{{ m[c.chave] ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Observação</label>
                        <textarea v-model="form.observacao" rows="2" maxlength="1000" class="form-control form-control-solid"></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-6">
                    <button type="button" class="btn btn-primary" :disabled="!form.valor || !form.metodo_pagamento_id || (exigeComprovativo && !comprovativo)" @click="continuar">{{ temAte || valorFixo ? 'Ver resumo' : 'Continuar' }}</button>
                </div>
            </div>
        </div>

        <!-- 3. Distribuição -->
        <div v-if="passo === 3" class="card">
            <div class="card-body">
                <div class="d-flex gap-6 mb-5">
                    <label class="form-check form-check-custom form-check-solid">
                        <input v-model="modo" class="form-check-input" type="radio" :value="MODO_DISTRIBUICAO.EXPLICITA" />
                        <span class="form-check-label">Escolher por propina</span>
                    </label>
                    <label class="form-check form-check-custom form-check-solid">
                        <input v-model="modo" class="form-check-input" type="radio" :value="MODO_DISTRIBUICAO.AUTOMATICA" />
                        <span class="form-check-label">Automática (da mais antiga para a mais recente)</span>
                    </label>
                </div>
                <div v-if="modo === MODO_DISTRIBUICAO.EXPLICITA && situacao?.propinas.length" class="text-muted fs-7 mb-4">
                    Marque o último mês a pagar: os meses anteriores em dívida ficam marcados com o saldo inteiro (não se saltam meses). Pode ajustar os valores.
                </div>
                <div v-if="errors.distribuicao" class="alert alert-danger fs-7 py-3">{{ errors.distribuicao }}</div>
                <div v-if="errosDeLinhasForaDaTabela.length" class="alert alert-danger fs-7 py-3">
                    <div v-for="(mensagem, indice) in errosDeLinhasForaDaTabela" :key="indice">{{ mensagem }}</div>
                </div>
                <p v-if="!situacao?.propinas.length" class="text-muted">Esta matrícula não tem propinas em dívida: o valor ficará como crédito do aluno.</p>
                <div v-else class="table-responsive">
                    <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                        <thead>
                            <tr class="text-muted fw-bold text-uppercase">
                                <th>Período</th><th>Vencimento</th><th>Estado</th><th class="text-end">Capital</th><th class="text-end">Multa</th><th class="text-end">Total</th>
                                <th v-if="modo === MODO_DISTRIBUICAO.EXPLICITA" class="w-30px"></th>
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
                                <td class="text-end">{{ propina.multa_pendente ? formatarDinheiro(propina.multa_pendente, moeda) : '—' }}</td>
                                <td class="text-end fw-semibold">{{ formatarDinheiro(propina.total_em_divida, moeda) }}</td>
                                <td v-if="modo === MODO_DISTRIBUICAO.EXPLICITA">
                                    <input type="checkbox" class="form-check-input" :checked="marcada(propina)" :disabled="bloqueadaPorAntecipado(propina)" :aria-label="`Pagar até ${propina.periodo}`"
                                        @change="pagarAteAqui(propina, $event.target.checked)" />
                                </td>
                                <td v-if="modo === MODO_DISTRIBUICAO.EXPLICITA" class="text-end">
                                    <input v-model="manual[propina.id]" type="text" inputmode="decimal" class="form-control form-control-sm form-control-solid text-end"
                                        :disabled="bloqueadaPorAntecipado(propina)"
                                        :placeholder="situacao.regras.permite_pagamento_parcial ? '0' : unidadesMenoresParaDecimal(propina.total_em_divida, moeda)" />
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
                    <button type="button" class="btn btn-primary" :disabled="!podeConfirmar" @click="aConfirmar = true">{{ processing ? 'A registar…' : 'Confirmar pagamento' }}</button>
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="aConfirmar"
            titulo="Registar pagamento"
            :mensagem="mensagemDeConfirmacao"
            texto-confirmar="Registar pagamento"
            variante="primary"
            :processando="processing"
            @confirmar="confirmarNoModal"
            @cancelar="aConfirmar = false"
        />
    </div>
</template>
