<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';

const props = defineProps({
    regra: {
        type: Object,
        required: true,
    },
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
    };
}

const form = reactive(snapshot());
const errors = ref({});
const processing = ref(false);

watch(() => form.permite_negociacao, (ligada) => {
    if (!ligada) form.desconto_maximo_negociacao = 0;
});

function submeter() {
    processing.value = true;
    errors.value = {};

    router.put('/financeiro/configuracao/regras-cobranca', form, {
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

            <div v-if="podeEditar" class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" :disabled="processing">
                    {{ processing ? 'A guardar…' : 'Guardar alterações' }}
                </button>
            </div>
        </form>
    </div>
</template>
