<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    propina: { type: Object, required: true }, // detalhe (periodo, matricula.aluno_nome, …)
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const MAXIMO = 500;
const motivo = ref('');
const invalido = computed(() => motivo.value.trim().length < 5 || motivo.value.length > MAXIMO);

watch(() => props.show, (show) => {
    if (show) motivo.value = '';
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-2">Cancelar propina</h3>
                <p class="text-muted fs-6 mb-5">
                    {{ propina.periodo }} — {{ propina.matricula?.aluno_nome ?? 'Aluno' }}
                </p>

                <div class="card bg-body-secondary mb-5">
                    <div class="card-body py-4 fs-7">
                        A propina passa a <strong>Cancelada</strong> e deixa de ser cobrada. A operação não se desfaz:
                        se for preciso, gere de novo o período em Financeiro → Propinas → Gerar propinas (a geração manual volta a cobrá-lo, depois de confirmada). Uma propina que já recebeu pagamentos
                        não se cancela (anula-se, com o módulo de Pagamentos).
                    </div>
                </div>

                <div v-if="errors.propina" class="alert alert-danger fs-7 py-3 mb-5">{{ errors.propina }}</div>

                <label for="motivo-cancelamento" class="form-label required fw-semibold">Motivo</label>
                <textarea
                    id="motivo-cancelamento"
                    v-model="motivo"
                    class="form-control form-control-solid"
                    :class="{ 'is-invalid': errors.motivo }"
                    rows="3"
                    maxlength="500"
                    placeholder="Ex.: lançada por engano; isenção decidida pela direcção"
                ></textarea>
                <div class="d-flex justify-content-between mt-1">
                    <div class="text-danger fs-7">{{ errors.motivo }}</div>
                    <div class="text-muted fs-8">{{ motivo.length }}/{{ MAXIMO }}</div>
                </div>

                <div class="d-flex justify-content-end gap-3 mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="emit('cancelar')">Voltar</button>
                    <button type="button" class="btn btn-danger" :disabled="processing || invalido" @click="emit('confirmar', motivo.trim())">
                        {{ processing ? 'A cancelar…' : 'Cancelar propina' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
