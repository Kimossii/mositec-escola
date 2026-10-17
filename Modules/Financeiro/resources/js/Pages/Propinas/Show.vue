<script setup>
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { can } from '@/Composables/usePermissoes';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import CancelarPropinaModal from '../../Components/Propinas/CancelarPropinaModal.vue';
import IsentarMultaModal from '../../Components/Propinas/IsentarMultaModal.vue';
import AnularRegistoModal from '../../Components/Pagamentos/AnularRegistoModal.vue';
import { ORIGEM_GERACAO } from '../../Models/Propina';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    propina: { type: Object, required: true },
    moeda: { type: Object, required: true },
    podeCancelar: { type: Boolean, required: true },
    podeAnular: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const page = usePage();
const cancelarAberto = ref(false);
const anularAberto = ref(false);
const isentarAberto = ref(false);
const processing = ref(false);
const errors = ref({});

/** "2026-10-16 11:00" (fuso da escola) → "16/10/2026 11:00". */
const instante = (valor) => (valor ? `${dataCurta(valor.slice(0, 10))} ${valor.slice(11, 16)}` : '—');

function abrirCancelamento() {
    errors.value = {};
    cancelarAberto.value = true;
}

function cancelar(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/propinas/${props.propina.id}/cancelar`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            cancelarAberto.value = false;
            toast.success(page.props.flash?.success ?? 'Propina cancelada.');
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

function anular(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/propinas/${props.propina.id}/anular`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            anularAberto.value = false;
            toast.success(page.props.flash?.success ?? 'Propina anulada.');
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

function isentar(novoValor, motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/propinas/${props.propina.id}/multa`, { novo_valor: novoValor, motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            isentarAberto.value = false;
            toast.success(page.props.flash?.success ?? 'Multa actualizada.');
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
        <BotaoVoltar href="/financeiro/propinas" />

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-4 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Propina {{ propina.periodo }}</h1>
                <p class="text-muted fs-6 mb-2">
                    {{ propina.matricula?.aluno_nome ?? '—' }} · matrícula nº {{ propina.matricula?.numero ?? '—' }}
                </p>
                <EstadoCobrancaBadge :estado="propina.estado" :estado-descricao="propina.estado_descricao" />
                <span v-if="propina.origem_geracao === ORIGEM_GERACAO.RETROACTIVA" class="badge badge-light-warning ms-2">Retroactiva</span>
                <span v-else-if="propina.geracao_tardia" class="badge badge-light-warning ms-2">Geração tardia</span>
            </div>
            <div class="text-end">
                <button v-if="podeCancelar" type="button" class="btn btn-light-danger" @click="abrirCancelamento">
                    Cancelar propina
                </button>
                <div v-else-if="can('propina.cancelar') && propina.cancelamento_bloqueado" class="text-muted fs-7" style="max-width: 360px">
                    {{ propina.cancelamento_bloqueado }}
                </div>
                <button v-if="podeAnular" type="button" class="btn btn-light-danger ms-2" @click="errors = {}; anularAberto = true">
                    Anular propina
                </button>
                <button v-if="can('propina.isentar-multa') && propina.multa && propina.multa.estado !== 4" type="button" class="btn btn-light-warning ms-2" @click="errors = {}; isentarAberto = true">
                    Isentar/reduzir multa
                </button>
                <div v-else-if="can('propina.anular') && !podeCancelar && propina.anulacao_bloqueada" class="text-muted fs-7 mt-2" style="max-width: 360px">
                    {{ propina.anulacao_bloqueada }}
                </div>
            </div>
        </div>

        <div class="row g-6 mb-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Propina</h3></div>
                    <div class="card-body pt-0">
                        <div class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Período</span><span>{{ propina.periodo }} (período {{ propina.ordem }})</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Valor original</span><span>{{ formatarDinheiro(propina.valor_original, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Valor actual</span><span>{{ formatarDinheiro(propina.valor, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pago</span><span>{{ formatarDinheiro(propina.valor_pago, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Saldo</span><span class="fw-bold">{{ formatarDinheiro(propina.saldo, moeda) }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Estado registado</span><span>{{ propina.estado_persistido_descricao }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Snapshot da geração</h3></div>
                    <div class="card-body pt-0">
                        <div class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Vencimento</span><span>{{ dataCurta(propina.data_vencimento) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Tolerância</span><span>{{ propina.dias_tolerancia }} dia(s)</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Em atraso depois de</span><span>{{ dataCurta(propina.data_limite) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Moeda</span><span>{{ propina.moeda }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Câmbio (1 USD)</span><span>{{ propina.cambio_usd ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Origem</span><span>{{ propina.origem_geracao_descricao }}</span></div>
                            <div v-if="propina.motivo_geracao" class="text-muted fs-7 mt-2">Motivo: {{ propina.motivo_geracao }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Matrícula</h3></div>
                    <div class="card-body pt-0">
                        <div v-if="propina.matricula" class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Aluno</span><span>{{ propina.matricula.aluno_nome ?? '—' }} (nº {{ propina.matricula.aluno_numero ?? '—' }})</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Matrícula nº</span><span>{{ propina.matricula.numero }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Turma</span><span>{{ propina.matricula.turma_nome ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Ano lectivo</span><span>{{ propina.matricula.ano_lectivo_nome ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Estado</span><span>{{ propina.matricula.estado_descricao }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Datas</span><span>{{ dataCurta(propina.matricula.data_matricula) }} → {{ propina.matricula.data_fim ? dataCurta(propina.matricula.data_fim) : '…' }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Plano de origem</h3></div>
                    <div class="card-body pt-0">
                        <div v-if="propina.plano" class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Plano</span><span>{{ propina.plano.nome }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Periodicidade</span><span>{{ propina.plano.periodicidade ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Valor actual do plano</span><span>{{ formatarDinheiro(propina.plano.valor_actual, moeda) }}</span></div>
                            <div class="text-muted fs-8 mt-2">A propina guarda o valor do momento em que foi gerada; mudar o plano não a altera.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Multa</h3></div>
                    <div class="card-body pt-0">
                        <div v-if="!propina.multa" class="text-muted">Sem multa.</div>
                        <div v-else class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Valor</span><span>{{ formatarDinheiro(propina.multa.valor, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pago</span><span>{{ formatarDinheiro(propina.multa.valor_pago, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pendente</span><span class="fw-bold">{{ formatarDinheiro(propina.multa.pendente, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Estado</span><span>{{ propina.multa.estado_descricao }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Automática</span><span>{{ propina.multa.bloqueada_automatico ? 'Bloqueada (manual)' : 'Activa' }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="propina.cancelamento || propina.anulacao" class="card mb-6">
            <div class="card-header">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">{{ propina.cancelamento ? 'Cancelamento' : 'Anulação' }}</h3>
            </div>
            <div class="card-body pt-0">
                <div class="bg-body-secondary rounded p-4">
                    <div class="mb-2">{{ (propina.cancelamento ?? propina.anulacao).motivo }}</div>
                    <div class="text-muted fs-7">
                        {{ instante((propina.cancelamento ?? propina.anulacao).em) }} · {{ (propina.cancelamento ?? propina.anulacao).por ?? 'Sistema' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Histórico</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-125px">Quando</th>
                                <th class="min-w-100px">Evento</th>
                                <th class="min-w-125px">Por</th>
                                <th>Detalhe</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-for="(evento, indice) in propina.historico" :key="indice">
                                <td>{{ instante(evento.em) }}</td>
                                <td class="text-gray-800">{{ evento.evento }}</td>
                                <td>{{ evento.por ?? 'Sistema' }}</td>
                                <td>{{ evento.detalhe ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="propina.historico.length === 0" class="text-muted px-9 py-6 mb-0">Sem eventos no histórico.</p>
                </div>
            </div>
        </div>

        <div class="row g-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Pagamentos</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="propina.pagamentos.length === 0" class="text-muted mb-0">Sem pagamentos.</p>
                        <table v-else class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                            <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Origem</th><th>Estado</th><th class="text-end">Valor</th></tr></thead>
                            <tbody>
                                <tr v-for="registo in propina.pagamentos" :key="`${registo.tipo}-${registo.id}`" :class="{ 'text-muted text-decoration-line-through': !registo.activo }">
                                    <td>{{ dataCurta(registo.data) }}</td>
                                    <td>
                                        <Link v-if="registo.pagamento_id" :href="`/financeiro/pagamentos/${registo.pagamento_id}`">
                                            {{ registo.tipo === 'pagamento' ? 'Pagamento' : 'Crédito do pagamento' }} nº {{ registo.numero }}
                                        </Link>
                                    </td>
                                    <td>{{ registo.estado_descricao }}</td>
                                    <td class="text-end">{{ formatarDinheiro(registo.valor, moeda) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Ajustes de preço</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="propina.ajustes.length === 0" class="text-muted mb-0">Sem ajustes de preço.</p>
                        <div v-else class="table-responsive">
                            <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                                <thead>
                                    <tr class="text-muted fw-bold text-uppercase">
                                        <th>Data</th><th>Valor</th><th>Recebido</th><th>Plano</th><th>Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="ajuste in propina.ajustes" :key="ajuste.id">
                                        <td>{{ instante(ajuste.em) }}<div class="text-muted">{{ ajuste.por ?? '—' }}</div></td>
                                        <td>
                                            {{ formatarDinheiro(ajuste.valor_anterior, moeda) }} → {{ formatarDinheiro(ajuste.valor_novo, moeda) }}
                                            <div class="text-muted">{{ ajuste.modo_descricao }} · desde {{ ajuste.mes_efeito }}</div>
                                        </td>
                                        <td>{{ formatarDinheiro(ajuste.valor_pago_no_momento, moeda) }}</td>
                                        <td>{{ ajuste.plano_anterior }}<span v-if="ajuste.plano_novo"> → {{ ajuste.plano_novo }}</span></td>
                                        <td>{{ ajuste.motivo }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <CancelarPropinaModal
            :show="cancelarAberto"
            :propina="propina"
            :processing="processing"
            :errors="errors"
            @confirmar="cancelar"
            @cancelar="cancelarAberto = false"
        />
        <AnularRegistoModal
            :show="anularAberto"
            titulo="Anular propina"
            :subtitulo="`${propina.periodo} — ${propina.matricula?.aluno_nome ?? 'Aluno'}`"
            aviso="A propina recebeu pagamentos que foram todos anulados. Passa a Anulada e deixa de ser cobrada. A operação não se desfaz."
            rotulo-confirmar="Anular propina"
            campo-erro="propina"
            :processing="processing"
            :errors="errors"
            @confirmar="anular"
            @cancelar="anularAberto = false"
        />
        <IsentarMultaModal
            :show="isentarAberto"
            :propina="propina"
            :moeda="moeda"
            :processing="processing"
            :errors="errors"
            @confirmar="isentar"
            @cancelar="isentarAberto = false"
        />
    </div>
</template>
