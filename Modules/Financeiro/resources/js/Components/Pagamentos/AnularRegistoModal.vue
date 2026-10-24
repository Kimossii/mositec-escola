<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    aviso: { type: String, required: true },
    itens: { type: Array, default: () => [] }, // O8: o que NÃO muda com a anulação mas o utilizador deve ver (ex.: utilizações de crédito do mesmo acto)
    itensTitulo: { type: String, default: '' },
    rotuloConfirmar: { type: String, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    campoErro: { type: String, required: true }, // 'pagamento' | 'utilizacao' | 'propina'
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
                <h3 class="mb-2">{{ titulo }}</h3>
                <p v-if="subtitulo" class="text-muted fs-6 mb-5">{{ subtitulo }}</p>

                <div class="card bg-body-secondary mb-5">
                    <div class="card-body py-4 fs-7">{{ aviso }}</div>
                </div>

                <div v-if="itens.length" class="mb-5">
                    <div class="fw-semibold fs-7 mb-1">{{ itensTitulo }}</div>
                    <ul class="fs-7 mb-0">
                        <li v-for="(item, indice) in itens" :key="indice">{{ item }}</li>
                    </ul>
                </div>

                <div v-if="errors[campoErro]" class="alert alert-danger fs-7 py-3 mb-5">{{ errors[campoErro] }}</div>

                <label for="motivo-anulacao" class="form-label required fw-semibold">Motivo</label>
                <textarea
                    id="motivo-anulacao"
                    v-model="motivo"
                    class="form-control form-control-solid"
                    :class="{ 'is-invalid': errors.motivo }"
                    rows="3"
                    maxlength="500"
                    placeholder="Ex.: valor errado no talão; registado em duplicado"
                ></textarea>
                <div class="d-flex justify-content-between mt-1">
                    <div class="text-danger fs-7">{{ errors.motivo }}</div>
                    <div class="text-muted fs-8">{{ motivo.length }}/{{ MAXIMO }}</div>
                </div>

                <div class="d-flex justify-content-end gap-3 mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="emit('cancelar')">Voltar</button>
                    <button type="button" class="btn btn-danger" :disabled="processing || invalido" @click="emit('confirmar', motivo.trim())">
                        {{ processing ? 'A anular…' : rotuloConfirmar }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
