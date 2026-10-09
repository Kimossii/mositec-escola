<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { can } from '@/Composables/usePermissoes';

const props = defineProps({
    regra: {
        type: Object,
        required: true,
    },
    escaloes: { type: Array, default: () => [] },
    moeda: { type: Object, required: true },
    tiposMulta: { type: Array, default: () => [] },
    maxEscaloes: { type: Number, default: 3 },
});
defineOptions({ layout: AppLayout });

const podeEditar = computed(() => can('regra-cobranca.editar'));

function snapshot() {
    return {
        dia_vencimento: props.regra.dia_vencimento,
        dias_tolerancia: props.regra.dias_tolerancia,
        permite_pagamento_parcial: props.regra.permite_pagamento_parcial,
        permite_pagamento_antecipado: props.regra.permite_pagamento_antecipado,
        gerar_automaticamente: props.regra.gerar_automaticamente,
        permite_negociacao: props.regra.permite_negociacao,
        desconto_maximo_negociacao: props.regra.desconto_maximo_negociacao,
        multa_activa: props.regra.multa_activa,
        // O valor_input vem pronto do servidor: "2,5" (percentagem) ou decimal da moeda.
        escaloes: props.escaloes.map((e) => ({ dias_atraso: e.dias_atraso, tipo: e.tipo, valor: e.valor_input })),
    };
}

const form = reactive(snapshot());
const errors = ref({});
const processing = ref(false);

watch(() => form.permite_negociacao, (ligada) => {
    if (!ligada) form.desconto_maximo_negociacao = 0;
});

const PERCENTAGEM = 0;
const tipoPorDefeito = props.tiposMulta[0]?.value ?? PERCENTAGEM;

function adicionarEscalao() {
    if (form.escaloes.length >= props.maxEscaloes) return;

    const ultimo = form.escaloes[form.escaloes.length - 1];
    form.escaloes.push({ dias_atraso: ultimo ? Number(ultimo.dias_atraso || 0) + 1 : 1, tipo: tipoPorDefeito, valor: '' });
}

function removerEscalao(indice) {
    form.escaloes.splice(indice, 1);
}

function aoMudarTipo(escalao) {
    escalao.valor = '';
}

const errosDoEscalao = (indice) => Object.entries(errors.value)
    .filter(([chave]) => chave.startsWith(`escaloes.${indice}.`))
    .map(([, mensagem]) => mensagem);

watch(() => form.multa_activa, (ligada) => {
    if (ligada && form.escaloes.length === 0) adicionarEscalao();
});

function submeter() {
    processing.value = true;
    errors.value = {};

    // Com a multa desligada os escalões gravados mantêm-se: não se enviam.
    const { escaloes, ...dados } = form;
    const payload = form.multa_activa ? { ...dados, escaloes } : dados;

    router.put('/financeiro/configuracao/regras-cobranca', payload, {
        preserveScroll: true,
        onSuccess: () => toast.success('Regras de cobrança atualizadas com sucesso.'),
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
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Regras de Cobrança</h1>
            <p class="text-muted fs-6 mb-0" style="max-width: 640px">
                Define como a escola cobra: vencimento, tolerância e o que é permitido nos pagamentos.
                Alterações aplicam-se apenas a cobranças futuras.
            </p>
        </div>

        <form @submit.prevent="submeter">
            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Vencimento</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label for="dia_vencimento" class="form-label fw-semibold">Dia de vencimento</label>
                            <input
                                id="dia_vencimento" v-model.number="form.dia_vencimento" type="number" min="1" max="28"
                                class="form-control" :class="{ 'is-invalid': errors.dia_vencimento }" :disabled="!podeEditar"
                            >
                            <div v-if="errors.dia_vencimento" class="invalid-feedback">{{ errors.dia_vencimento }}</div>
                            <div class="form-text">Entre 1 e 28, para existir em todos os meses.</div>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label for="dias_tolerancia" class="form-label fw-semibold">Dias de tolerância</label>
                            <input
                                id="dias_tolerancia" v-model.number="form.dias_tolerancia" type="number" min="0" max="90"
                                class="form-control" :class="{ 'is-invalid': errors.dias_tolerancia }" :disabled="!podeEditar"
                            >
                            <div v-if="errors.dias_tolerancia" class="invalid-feedback">{{ errors.dias_tolerancia }}</div>
                            <div class="form-text">Dias corridos após o vencimento antes de a cobrança ficar em atraso.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Pagamentos e geração</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="form-check form-switch form-check-custom form-check-solid mb-5">
                        <input id="permite_pagamento_parcial" v-model="form.permite_pagamento_parcial" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="permite_pagamento_parcial" class="form-check-label fw-semibold">Permitir pagamento parcial</label>
                    </div>
                    <div class="form-check form-switch form-check-custom form-check-solid mb-5">
                        <input id="permite_pagamento_antecipado" v-model="form.permite_pagamento_antecipado" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="permite_pagamento_antecipado" class="form-check-label fw-semibold">Permitir pagamento antecipado</label>
                    </div>
                    <div class="form-check form-switch form-check-custom form-check-solid">
                        <input id="gerar_automaticamente" v-model="form.gerar_automaticamente" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="gerar_automaticamente" class="form-check-label fw-semibold">Gerar cobranças automaticamente</label>
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Negociação de dívidas</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="form-check form-switch form-check-custom form-check-solid mb-5">
                        <input id="permite_negociacao" v-model="form.permite_negociacao" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="permite_negociacao" class="form-check-label fw-semibold">Permitir negociação de dívidas</label>
                    </div>
                    <div v-if="form.permite_negociacao" class="row">
                        <div class="col-md-4">
                            <label for="desconto_maximo_negociacao" class="form-label fw-semibold">Desconto máximo (%)</label>
                            <input
                                id="desconto_maximo_negociacao" v-model.number="form.desconto_maximo_negociacao" type="number" min="0" max="100"
                                class="form-control" :class="{ 'is-invalid': errors.desconto_maximo_negociacao }" :disabled="!podeEditar"
                            >
                            <div v-if="errors.desconto_maximo_negociacao" class="invalid-feedback">{{ errors.desconto_maximo_negociacao }}</div>
                            <div class="form-text">0 permite apenas renegociar prazos, sem desconto.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Multas por atraso</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="form-check form-switch form-check-custom form-check-solid mb-2">
                        <input id="multa_activa" v-model="form.multa_activa" type="checkbox" class="form-check-input" :disabled="!podeEditar">
                        <label for="multa_activa" class="form-check-label fw-semibold">Aplicar multa por atraso</label>
                    </div>
                    <div class="form-text mb-4">
                        Cada escalão entra em vigor ao fim dos dias de atraso indicados (1 = primeiro dia em atraso, depois da tolerância).
                        Desligar só interrompe novas multas; as já aplicadas mantêm-se.
                    </div>

                    <div v-if="form.multa_activa && errors.escaloes" class="text-danger fs-7 mb-3">{{ errors.escaloes }}</div>

                    <fieldset v-if="form.multa_activa" :disabled="!podeEditar" class="border-0 p-0 m-0 min-w-0">
                        <div v-for="(escalao, i) in form.escaloes" :key="i" class="bg-body-secondary rounded p-4 mb-3">
                            <div class="row align-items-start g-3">
                                <div class="col-md-1 d-flex align-items-center pt-md-9">
                                    <span class="badge badge-light-primary">{{ i + 1 }}º</span>
                                </div>
                                <div class="col-md-3">
                                    <label :for="`esc_dias_${i}`" class="form-label fw-semibold">Dias de atraso</label>
                                    <input
                                        :id="`esc_dias_${i}`" v-model.number="escalao.dias_atraso" type="number" min="1" step="1"
                                        class="form-control" :class="{ 'is-invalid': errors[`escaloes.${i}.dias_atraso`] }" :disabled="!podeEditar"
                                    >
                                    <div v-if="errors[`escaloes.${i}.dias_atraso`]" class="invalid-feedback">{{ errors[`escaloes.${i}.dias_atraso`] }}</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Tipo</label>
                                    <SelectSolid v-model="escalao.tipo" :options="tiposMulta" @update:model-value="aoMudarTipo(escalao)" />
                                    <div v-if="errors[`escaloes.${i}.tipo`]" class="text-danger fs-7 mt-1">{{ errors[`escaloes.${i}.tipo`] }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label :for="`esc_valor_${i}`" class="form-label fw-semibold">Valor</label>
                                    <div class="input-group">
                                        <input
                                            :id="`esc_valor_${i}`" v-model="escalao.valor" type="text" inputmode="decimal" autocomplete="off"
                                            class="form-control" :class="{ 'is-invalid': errors[`escaloes.${i}.valor`] }" :disabled="!podeEditar"
                                            :placeholder="escalao.tipo === PERCENTAGEM ? 'ex: 2,5' : (moeda.decimais === 0 ? 'ex: 5000' : 'ex: 5000,' + '0'.repeat(moeda.decimais))"
                                        >
                                        <span class="input-group-text">{{ escalao.tipo === PERCENTAGEM ? '%' : moeda.simbolo }}</span>
                                        <div v-if="errors[`escaloes.${i}.valor`]" class="invalid-feedback">{{ errors[`escaloes.${i}.valor`] }}</div>
                                    </div>
                                </div>
                                <div v-if="podeEditar" class="col-md-1 d-flex justify-content-md-end pt-md-9">
                                    <button type="button" class="btn btn-sm btn-icon btn-light-danger" title="Remover escalão" @click="removerEscalao(i)">
                                        <i class="ki-duotone ki-trash fs-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
                                    </button>
                                </div>
                            </div>
                            <div v-if="errosDoEscalao(i).length && !errors[`escaloes.${i}.dias_atraso`] && !errors[`escaloes.${i}.valor`] && !errors[`escaloes.${i}.tipo`]" class="text-danger fs-7 mt-2">
                                {{ errosDoEscalao(i)[0] }}
                            </div>
                        </div>

                        <button
                            v-if="podeEditar && form.escaloes.length < maxEscaloes" type="button"
                            class="btn btn-sm btn-light-primary" @click="adicionarEscalao"
                        >
                            <i class="ki-duotone ki-plus fs-4"></i> Adicionar escalão
                        </button>
                        <div v-if="form.escaloes.length >= maxEscaloes" class="form-text">Máximo de {{ maxEscaloes }} escalões.</div>
                    </fieldset>
                </div>
            </div>

            <div v-if="podeEditar" class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" :disabled="processing">
                    {{ processing ? 'A guardar…' : 'Guardar alterações' }}
                </button>
            </div>
        </form>
    </div>
</template>
