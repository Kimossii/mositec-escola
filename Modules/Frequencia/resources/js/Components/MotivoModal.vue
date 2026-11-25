<script setup>
import { ref, watch } from 'vue';
import Loader from '@/Components/Shared/Loader.vue';
import ErrosGerais from './ErrosGerais.vue';

/**
 * Modal de um motivo (e, opcionalmente, da referência do documento): serve para justificar uma falta, retirar a
 * justificação e marcar a aula como não leccionada.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, required: true },
    contexto: { type: String, default: '' },
    rotuloMotivo: { type: String, default: 'Motivo' },
    comDocumento: { type: Boolean, default: false },
    textoConfirmar: { type: String, default: 'Guardar' },
    perigo: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['motivo', 'documento_referencia'];
const motivo = ref('');
const documento = ref('');

watch(() => props.show, (show) => {
    if (!show) return;
    motivo.value = '';
    documento.value = '';
});

const submeter = () => emit('submit', { motivo: motivo.value, ...(props.comDocumento ? { documento_referencia: documento.value || null } : {}) });
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">{{ titulo }}</h3>
                <div v-if="contexto" class="text-muted fs-7 mb-5">{{ contexto }}</div>
                <form @submit.prevent="submeter">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="fv-row mb-5">
                        <label class="required fw-semibold fs-6 mb-2">{{ rotuloMotivo }}</label>
                        <textarea v-model="motivo" class="form-control form-control-solid" rows="3" maxlength="500"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.motivo">{{ errors.motivo }}</div>
                    </div>

                    <div v-if="comDocumento" class="fv-row mb-5">
                        <label class="fw-semibold fs-6 mb-2">Referência do documento <span class="text-muted fw-normal">(opcional)</span></label>
                        <input v-model="documento" type="text" maxlength="255" class="form-control form-control-solid" placeholder="Ex.: atestado n.º 123" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.documento_referencia">{{ errors.documento_referencia }}</div>
                    </div>

                    <div class="text-end mt-7">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn" :class="perigo ? 'btn-danger' : 'btn-success'" :disabled="processing">
                            <span v-if="processing">A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>{{ textoConfirmar }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
