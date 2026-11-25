<script setup>
import { reactive, watch } from 'vue';
import Loader from '@/Components/Shared/Loader.vue';
import ErrosGerais from './ErrosGerais.vue';
import { formatarDia, hojeIso } from '../Support/grelha';

const props = defineProps({
    show: { type: Boolean, default: false },
    entrada: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['vigencia_fim'];
const form = reactive({ vigencia_fim: '' });

watch(() => props.show, (show) => {
    if (show) form.vigencia_fim = hojeIso();
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">Encerrar aula do horário</h3>
                <div class="text-muted fs-7 mb-5">
                    {{ entrada?.disciplina_nome }} · em vigor desde {{ formatarDia(entrada?.vigencia_inicio) }}. O histórico mantém-se.
                </div>
                <form @submit.prevent="emit('submit', { ...form })">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Último dia</label>
                        <input v-model="form.vigencia_fim" type="date" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.vigencia_fim">{{ errors.vigencia_fim }}</div>
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
