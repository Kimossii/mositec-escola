<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Loader from '@/Components/Shared/Loader.vue';
import ErrosGerais from './ErrosGerais.vue';
import VigenciaBadge from './VigenciaBadge.vue';
import { PAPEL } from '../../Models/PapelDocente';

const props = defineProps({
    show: { type: Boolean, default: false },
    atribuicao: { type: Object, default: null }, // { id, professor_nome, papel, data_inicio, data_fim, estado }
    contexto: { type: String, default: '' }, // «Disciplina · Turma»
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['papel', 'data_inicio', 'data_fim'];
const PAPEL_OPCOES = [
    { value: PAPEL.TITULAR, label: 'Titular' },
    { value: PAPEL.SUBSTITUTO, label: 'Substituto' },
];

const form = reactive({ papel: PAPEL.TITULAR, data_inicio: '', data_fim: '' });

watch(() => props.show, (show) => {
    if (!show) return;
    form.papel = props.atribuicao?.papel ?? PAPEL.TITULAR;
    form.data_inicio = props.atribuicao?.data_inicio ?? '';
    form.data_fim = props.atribuicao?.data_fim ?? '';
});

function submeter() {
    emit('submit', { ...form, data_fim: form.data_fim || null });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">Editar vigência</h3>
                <div class="text-muted fs-7 mb-5">
                    {{ atribuicao?.professor_nome }}<span v-if="contexto"> · {{ contexto }}</span>
                    <VigenciaBadge v-if="atribuicao?.estado" :estado="atribuicao.estado" class="ms-2" />
                </div>
                <form @submit.prevent="submeter">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Papel</label>
                        <SelectSolid v-model="form.papel" :options="PAPEL_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.papel">{{ errors.papel }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Início</label>
                            <input v-model="form.data_inicio" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_inicio">{{ errors.data_inicio }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Fim</label>
                            <input v-model="form.data_fim" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_fim">{{ errors.data_fim }}</div>
                            <div class="text-muted fs-8 mt-1" v-else>Vazio = até ao fim do ano lectivo.</div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">
                            <span v-if="processing">A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>Guardar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
