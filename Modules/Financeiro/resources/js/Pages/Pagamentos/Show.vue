<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { can } from '@/Composables/usePermissoes';
import AnularRegistoModal from '../../Components/Pagamentos/AnularRegistoModal.vue';
import { ESTADO_UTILIZACAO, estadoCreditoBadgeClass, estadoPagamentoBadgeClass } from '../../Models/Pagamento';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    pagamento: { type: Object, required: true },
    recibo: { type: Object, required: true },
    moeda: { type: Object, required: true },
    podeAnular: { type: Boolean, required: true },
    podeAnularUtilizacoes: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const page = usePage();
const anularAberto = ref(false);
const utilizacaoAnular = ref(null);
const processing = ref(false);
const errors = ref({});

const anexando = ref(false);
const errosComprovativo = ref({});
const inputComprovativo = ref(null);

function anexarComprovativo(event) {
    const ficheiro = event.target.files?.[0] ?? null;
    if (!ficheiro || anexando.value) return;
    anexando.value = true;
    errosComprovativo.value = {};

    router.post(`/financeiro/pagamentos/${props.pagamento.id}/comprovativo`, { comprovativo: ficheiro }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            toast.success(page.props.flash?.success ?? 'Comprovativo anexado.');
        },
        onError: (erros) => {
            errosComprovativo.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            anexando.value = false;
            if (inputComprovativo.value) inputComprovativo.value.value = '';
        },
    });
}

// O1: o registo redirecciona para aqui com um aviso (referência já usada num pagamento anulado).
onMounted(() => {
    const aviso = page.props.flash?.warning;
    if (aviso) toast.warning(aviso);
});

// O8: as utilizações de crédito do mesmo acto não se anulam com o pagamento; o modal di-lo e lista as que ficam Activas.
const itensDaAnulacao = computed(() => props.pagamento.utilizacoes_combinadas
    .filter((u) => u.estado === ESTADO_UTILIZACAO.ACTIVA)
    .map((u) => `${u.periodo} — ${formatarDinheiro(u.valor, props.moeda)} (${dataCurta(u.data_utilizacao)})`));

const instante = (valor) => (valor ? `${dataCurta(valor.slice(0, 10))} ${valor.slice(11, 16)}` : '—');

function anular(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/pagamentos/${props.pagamento.id}/anular`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            anularAberto.value = false;
            toast.success(page.props.flash?.success ?? 'Pagamento anulado.');
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function anularUtilizacao(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/creditos/utilizacoes/${utilizacaoAnular.value.id}/anular`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            utilizacaoAnular.value = null;
            toast.success(page.props.flash?.success ?? 'Utilização de crédito anulada.');
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
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

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-4 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Pagamento nº {{ pagamento.numero }}</h1>
                <p class="text-muted fs-6 mb-2">
                    {{ pagamento.matricula?.aluno_nome ?? '—' }} · matrícula nº {{ pagamento.matricula?.numero ?? '—' }}
                </p>
                <span class="badge" :class="estadoPagamentoBadgeClass(pagamento.estado)">{{ pagamento.estado_descricao }}</span>
            </div>
            <div class="text-end">
                <a :href="`/financeiro/pagamentos/${pagamento.id}/recibo`" target="_blank" class="btn btn-light-primary me-2">
                    {{ recibo.periodos.length > 1 ? 'Recibo geral' : 'Imprimir recibo' }}
                </a>
                <a
                    v-for="periodo in recibo.periodos.length > 1 ? recibo.periodos : []"
                    :key="periodo.propina_id"
                    :href="`/financeiro/pagamentos/${pagamento.id}/recibo/propinas/${periodo.propina_id}`"
                    target="_blank"
                    class="btn btn-light me-2"
                >
                    Recibo de {{ periodo.periodo }}
                </a>
                <button v-if="podeAnular" type="button" class="btn btn-light-danger" @click="errors = {}; anularAberto = true">
                    Anular pagamento
                </button>
                <div v-else-if="can('pagamento.anular') && pagamento.anulacao_bloqueada" class="text-muted fs-7" style="max-width: 360px">
                    {{ pagamento.anulacao_bloqueada }}
                </div>
            </div>
        </div>

        <div class="row g-6 mb-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Pagamento</h3></div>
                    <div class="card-body pt-0">
                        <table class="table table-row-dashed fs-6 gy-3 mb-0">
                            <tbody>
                                <tr><td class="text-muted">Valor recebido</td><td class="text-end fw-bold">{{ formatarDinheiro(pagamento.valor, moeda) }}</td></tr>
                                <tr><td class="text-muted">Data do pagamento</td><td class="text-end">{{ dataCurta(pagamento.data_pagamento) }}</td></tr>
                                <tr><td class="text-muted">Método</td><td class="text-end">{{ pagamento.metodo?.nome ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Referência</td><td class="text-end">{{ pagamento.referencia ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Câmbio (1 USD)</td><td class="text-end">{{ pagamento.cambio_usd ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Registado</td><td class="text-end">{{ instante(pagamento.registado?.em) }} · {{ pagamento.registado?.por ?? 'Sistema' }}</td></tr>
                                <tr v-if="pagamento.observacao"><td class="text-muted">Observação</td><td class="text-end">{{ pagamento.observacao }}</td></tr>
                                <tr>
                                    <td class="text-muted">Comprovativo</td>
                                    <td class="text-end">
                                        <a v-if="pagamento.comprovativo" :href="`/financeiro/pagamentos/${pagamento.id}/comprovativo`" target="_blank" class="btn btn-sm btn-light-primary">
                                            Ver comprovativo
                                        </a>
                                        <template v-else-if="can('pagamento.criar')">
                                            <button type="button" class="btn btn-sm btn-light" :disabled="anexando" @click="inputComprovativo.click()">
                                                {{ anexando ? 'A anexar…' : 'Anexar comprovativo' }}
                                            </button>
                                            <input ref="inputComprovativo" type="file" accept="image/*,application/pdf" capture="environment" class="d-none" @change="anexarComprovativo" />
                                            <div v-if="errosComprovativo.comprovativo" class="text-danger fs-8 mt-1">{{ errosComprovativo.comprovativo }}</div>
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Matrícula</h3></div>
                    <div class="card-body pt-0">
                        <table class="table table-row-dashed fs-6 gy-3 mb-0">
                            <tbody>
                                <tr><td class="text-muted">Aluno</td><td class="text-end">{{ pagamento.matricula?.aluno_nome ?? '—' }} (nº {{ pagamento.matricula?.aluno_numero ?? '—' }})</td></tr>
                                <tr><td class="text-muted">Turma / Ano</td><td class="text-end">{{ pagamento.matricula?.turma_rotulo ?? '—' }} · {{ pagamento.matricula?.ano_lectivo_nome ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Estado da matrícula</td><td class="text-end">{{ pagamento.matricula?.estado_descricao ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                        <Link v-if="pagamento.matricula" :href="`/financeiro/creditos/alunos/${pagamento.matricula.aluno_id}`" class="btn btn-light btn-sm mt-4">
                            Créditos do aluno
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="pagamento.anulacao" class="card bg-body-secondary mb-6">
            <div class="card-body py-4 fs-7">
                <strong>Anulado</strong> em {{ instante(pagamento.anulacao.em) }} por {{ pagamento.anulacao.por ?? '—' }} — {{ pagamento.anulacao.motivo }}
            </div>
        </div>

        <div class="row g-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Distribuição pelas propinas</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="pagamento.distribuicoes.length === 0" class="text-muted mb-0">Nenhuma propina: o valor ficou todo como crédito do aluno.</p>
                        <table v-else class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                            <thead><tr class="text-muted fw-bold text-uppercase"><th>Período</th><th>Plano</th><th>Estado</th><th class="text-end">Valor</th><th class="text-end">Acções</th></tr></thead>
                            <tbody>
                                <tr v-for="d in pagamento.distribuicoes" :key="d.propina_id">
                                    <td><Link :href="`/financeiro/propinas/${d.propina_id}`">{{ d.periodo }}</Link></td>
                                    <td>{{ d.plano_nome ?? '—' }}</td>
                                    <td>{{ d.estado_propina_descricao }}</td>
                                    <td class="text-end">{{ formatarDinheiro(d.valor, moeda) }}</td>
                                    <td class="text-end text-nowrap">
                                        <a
                                            v-if="d.em_divida && can('pagamento.criar')"
                                            :href="`/financeiro/pagamentos/novo?matricula=${pagamento.matricula_id}`"
                                            class="btn btn-sm btn-light-primary"
                                        >Pagar</a>
                                        <a
                                            v-else
                                            :href="`/financeiro/pagamentos/${pagamento.id}/recibo/propinas/${d.propina_id}`"
                                            target="_blank" class="btn btn-sm btn-light"
                                        >Recibo</a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Crédito gerado</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="!pagamento.credito" class="text-muted mb-0">Sem excedente.</p>
                        <template v-else>
                            <p class="mb-3">
                                {{ formatarDinheiro(pagamento.credito.valor, moeda) }} · utilizado {{ formatarDinheiro(pagamento.credito.valor_utilizado, moeda) }} ·
                                saldo {{ formatarDinheiro(pagamento.credito.saldo, moeda) }}
                                <span class="badge ms-2" :class="estadoCreditoBadgeClass(pagamento.credito.estado)">{{ pagamento.credito.estado_descricao }}</span>
                            </p>
                            <table v-if="pagamento.credito.utilizacoes.length" class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                                <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Propina</th><th>Estado</th><th class="text-end">Valor</th><th></th></tr></thead>
                                <tbody>
                                    <tr v-for="u in pagamento.credito.utilizacoes" :key="u.id">
                                        <td>{{ dataCurta(u.data_utilizacao) }}</td>
                                        <td><Link :href="`/financeiro/propinas/${u.propina_id}`">{{ u.periodo }}</Link> <span class="text-muted">· {{ u.matricula_numero }}</span></td>
                                        <td>{{ u.estado_descricao }}</td>
                                        <td class="text-end">{{ formatarDinheiro(u.valor, moeda) }}</td>
                                        <td class="text-end">
                                            <button v-if="podeAnularUtilizacoes && u.estado === ESTADO_UTILIZACAO.ACTIVA" type="button" class="btn btn-sm btn-light-danger" @click="errors = {}; utilizacaoAnular = u">Anular</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="pagamento.multas?.length" class="card mt-6">
            <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Distribuição por multas</h3></div>
            <div class="card-body pt-0">
                <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                    <thead><tr class="text-muted fw-bold text-uppercase"><th>Período</th><th>Estado</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                        <tr v-for="m in pagamento.multas" :key="`multa-${m.propina_id}`">
                            <td><Link :href="`/financeiro/propinas/${m.propina_id}`">{{ m.periodo }}</Link></td>
                            <td>{{ m.estado_propina_descricao }}</td>
                            <td class="text-end">
                                {{ formatarDinheiro(m.valor, moeda) }}
                                <div v-if="m.regra" class="text-muted fs-8">Multa aplicada: {{ m.regra }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="pagamento.utilizacoes_combinadas.length" class="card mt-6">
            <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Crédito usado neste pagamento</h3></div>
            <div class="card-body pt-0">
                <p class="text-muted fs-7">Utilizações de crédito feitas no mesmo acto. São independentes do dinheiro deste pagamento: anular o pagamento não as anula.</p>
                <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                    <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Propina</th><th>Estado</th><th class="text-end">Valor</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="u in pagamento.utilizacoes_combinadas" :key="u.id">
                            <td>{{ dataCurta(u.data_utilizacao) }}</td>
                            <td><Link :href="`/financeiro/propinas/${u.propina_id}`">{{ u.periodo }}</Link> <span class="text-muted">· {{ u.matricula_numero }}</span></td>
                            <td>{{ u.estado_descricao }}</td>
                            <td class="text-end">{{ formatarDinheiro(u.valor, moeda) }}</td>
                            <td class="text-end">
                                <button v-if="podeAnularUtilizacoes && u.estado === ESTADO_UTILIZACAO.ACTIVA" type="button" class="btn btn-sm btn-light-danger" @click="errors = {}; utilizacaoAnular = u">Anular</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AnularRegistoModal
            :show="anularAberto"
            titulo="Anular pagamento"
            :subtitulo="`Nº ${pagamento.numero} — ${formatarDinheiro(pagamento.valor, moeda)}`"
            aviso="O pagamento passa a Anulado e deixa de contar: as propinas que liquidava voltam a ficar em dívida e o crédito gerado é anulado. Nada é apagado. Para corrigir, registe depois o pagamento certo."
            rotulo-confirmar="Anular pagamento"
            :itens="itensDaAnulacao"
            itens-titulo="Continuam Activas depois de anular este pagamento (anule-as à parte, se for caso disso):"
            campo-erro="pagamento"
            :processing="processing"
            :errors="errors"
            @confirmar="anular"
            @cancelar="anularAberto = false"
        />
        <AnularRegistoModal
            :show="utilizacaoAnular !== null"
            titulo="Anular utilização de crédito"
            :subtitulo="utilizacaoAnular ? `${utilizacaoAnular.periodo} — ${formatarDinheiro(utilizacaoAnular.valor, moeda)}` : ''"
            aviso="O crédito recupera este valor e a propina volta a ficar em dívida nessa parte."
            rotulo-confirmar="Anular utilização"
            campo-erro="utilizacao"
            :processing="processing"
            :errors="errors"
            @confirmar="anularUtilizacao"
            @cancelar="utilizacaoAnular = null"
        />
    </div>
</template>
