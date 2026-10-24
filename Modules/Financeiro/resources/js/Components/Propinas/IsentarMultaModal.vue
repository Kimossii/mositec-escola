<script setup>
import { computed, ref, watch } from 'vue';
import { unidadesMenoresParaDecimal } from '../../Support/dinheiro';

const props = defineProps({
    show: { type: Boolean, default: false },
    propina: { type: Object, required: true }, // detalhe (periodo, matricula.aluno_nome, multa, …)
    moeda: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const MAXIMO = 500;
const novoValor = ref('');
const motivo = ref('');

const invalido = computed(() => novoValor.value.trim() === '' || motivo.value.trim().length < 5 || motivo.value.length > MAXIMO);

watch(() => props.show, (show) => {
    if (!show) return;
    novoValor.value = unidadesMenoresParaDecimal(props.propina.multa?.valor ?? 0, props.moeda);
    motivo.value = '';
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-2">Isentar ou reduzir multa</h3>
                <p class="text-muted fs-6 mb-5">
                    {{ propina.periodo }} — {{ propina.matricula?.aluno_nome ?? 'Aluno' }}
                </p>

                <div class="card bg-body-secondary mb-5">
                    <div class="card-body py-4 fs-7">
                        O novo valor fica entre o já pago e o valor actual. Igual ao já pago (pode ser 0) isenta por completo;
                        abaixo do actual reduz. A operação fica registada e a multa deixa de ser alterada automaticamente.
                    </div>
                </div>

                <div v-if="errors.multa" class="alert alert-danger fs-7 py-3 mb-5">{{ errors.multa }}</div>

                <label for="novo-valor-multa" class="form-label required fw-semibold">Novo valor da multa ({{ moeda.simbolo }})</label>
                <input
                    id="novo-valor-multa"
                    v-model="novoValor"
                    type="text"
                    inputmode="decimal"
                    class="form-control form-control-solid mb-5"
                    :class="{ 'is-invalid': errors.novo_valor }"
                />
                <div v-if="errors.novo_valor" class="text-danger fs-7 mb-5">{{ errors.novo_valor }}</div>

                <label for="motivo-multa" class="form-label required fw-semibold">Motivo</label>
                <textarea
                    id="motivo-multa"
                    v-model="motivo"
                    class="form-control form-control-solid"
                    :class="{ 'is-invalid': errors.motivo }"
                    rows="3"
                    maxlength="500"
                    placeholder="Ex.: isenção decidida pela direcção"
                ></textarea>
                <div class="d-flex justify-content-between mt-1">
                    <div class="text-danger fs-7">{{ errors.motivo }}</div>
                    <div class="text-muted fs-8">{{ motivo.length }}/{{ MAXIMO }}</div>
                </div>

                <div class="d-flex justify-content-end gap-3 mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="emit('cancelar')">Voltar</button>
                    <button type="button" class="btn btn-danger" :disabled="processing || invalido" @click="emit('confirmar', novoValor.value.trim(), motivo.value.trim())">
                        {{ processing ? 'A gravar…' : 'Confirmar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
