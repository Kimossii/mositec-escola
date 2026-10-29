<script setup>
import { reactive, watch } from 'vue';
import Loader from '@/Components/Shared/Loader.vue';
import ErrosGerais from '../Shared/ErrosGerais.vue';
import { formatarDia, hojeIso, papelLabel } from '../../Models/PapelDocente';

const props = defineProps({
    show: { type: Boolean, default: false },
    atribuicao: { type: Object, default: null },
    contexto: { type: String, default: '' },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['data_fim', 'motivo'];

const form = reactive({ data_fim: '', motivo: '' });

watch(() => props.show, (show) => {
    if (!show) return;
    form.data_fim = hojeIso();
    form.motivo = '';
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">Encerrar atribuição</h3>
                <div class="text-muted fs-7 mb-5">
                    {{ atribuicao?.docente_nome }} ({{ papelLabel(atribuicao?.papel) }})<span v-if="contexto"> · {{ contexto }}</span>
                    · desde {{ formatarDia(atribuicao?.data_inicio) }}
                </div>
                <form @submit.prevent="emit('submit', { ...form })">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Último dia</label>
                        <input v-model="form.data_fim" type="date" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.data_fim">{{ errors.data_fim }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Motivo</label>
                        <textarea v-model="form.motivo" class="form-control form-control-solid" rows="3" maxlength="500"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.motivo">{{ errors.motivo }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-danger" :disabled="processing">
                            <span v-if="processing">A encerrar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>Encerrar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
