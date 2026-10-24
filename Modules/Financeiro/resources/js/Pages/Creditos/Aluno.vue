<script setup>
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import AnularRegistoModal from '../../Components/Pagamentos/AnularRegistoModal.vue';
import AplicarCreditoModal from '../../Components/Pagamentos/AplicarCreditoModal.vue';
import { ESTADO_UTILIZACAO, estadoCreditoBadgeClass } from '../../Models/Pagamento';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

defineProps({
    aluno: { type: Object, required: true },
    saldo_disponivel: { type: Number, required: true },
    creditos: { type: Array, required: true },
    matriculas: { type: Array, required: true },
    moeda: { type: Object, required: true },
    podeAplicar: { type: Boolean, required: true },
    podeAnular: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const page = usePage();
const aplicarAberto = ref(false);
const utilizacaoAnular = ref(null);
const processing = ref(false);
const errors = ref({});

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
                <h1 class="fs-2 fw-bold mb-1">Créditos de {{ aluno.nome ?? 'aluno' }}</h1>
                <p class="text-muted fs-6 mb-0">nº {{ aluno.numero ?? '—' }} · disponível {{ formatarDinheiro(saldo_disponivel, moeda) }}</p>
            </div>
            <button v-if="podeAplicar && saldo_disponivel > 0" type="button" class="btn btn-primary" @click="aplicarAberto = true">Aplicar crédito</button>
        </div>

        <p v-if="creditos.length === 0" class="text-muted">Este aluno não tem créditos.</p>

        <div v-for="credito in creditos" :key="credito.id" class="card mb-6">
            <div class="card-header align-items-center">
                <h3 class="card-title fs-6 fw-bold">
                    {{ formatarDinheiro(credito.valor, moeda) }} · saldo {{ formatarDinheiro(credito.saldo, moeda) }}
                    <span class="badge ms-2" :class="estadoCreditoBadgeClass(credito.estado)">{{ credito.estado_descricao }}</span>
                </h3>
                <Link :href="`/financeiro/pagamentos/${credito.origem_pagamento_id}`" class="fs-7">Origem: pagamento nº {{ credito.origem_numero }} ({{ dataCurta(credito.origem_data) }})</Link>
            </div>
            <div class="card-body pt-0">
                <p v-if="credito.utilizacoes.length === 0" class="text-muted mb-0 mt-4">Sem utilizações.</p>
                <table v-else class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                    <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Propina</th><th>Matrícula</th><th>Estado</th><th class="text-end">Valor</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="u in credito.utilizacoes" :key="u.id">
                            <td>{{ dataCurta(u.data_utilizacao) }}</td>
                            <td><Link :href="`/financeiro/propinas/${u.propina_id}`">{{ u.periodo }}</Link></td>
                            <td>{{ u.matricula_numero ?? '—' }}</td>
                            <td>{{ u.estado_descricao }}<div v-if="u.motivo_anulacao" class="text-muted fs-8">{{ u.motivo_anulacao }}</div></td>
                            <td class="text-end">{{ formatarDinheiro(u.valor, moeda) }}</td>
                            <td class="text-end">
                                <button v-if="podeAnular && u.estado === ESTADO_UTILIZACAO.ACTIVA" type="button" class="btn btn-sm btn-light-danger" @click="errors = {}; utilizacaoAnular = u">Anular</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AplicarCreditoModal :show="aplicarAberto" :matriculas="matriculas" :saldo-disponivel="saldo_disponivel" :moeda="moeda" @fechar="aplicarAberto = false" />
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
