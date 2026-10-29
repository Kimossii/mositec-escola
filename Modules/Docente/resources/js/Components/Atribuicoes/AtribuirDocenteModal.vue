<script setup>
import { rotuloTurma } from '@/Support/turma';
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Loader from '@/Components/Shared/Loader.vue';
import ErrosGerais from '../Shared/ErrosGerais.vue';
import { PAPEL, hojeIso, opcoesDeDocentes } from '../../Models/PapelDocente';

const props = defineProps({
    show: { type: Boolean, default: false },
    linha: { type: Object, default: null }, // linha da vista por turma: disciplina_id, disciplina_nome, sem_titular
    turma: { type: Object, default: null }, // { id, nome }
    docentes: { type: Array, default: () => [] }, // [{ id, nome, tem_acesso }]
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['docente_id', 'papel', 'data_inicio', 'data_fim'];
const PAPEL_OPCOES = [
    { value: PAPEL.TITULAR, label: 'Titular' },
    { value: PAPEL.SUBSTITUTO, label: 'Substituto' },
];

const opcoesDocente = computed(() => opcoesDeDocentes(props.docentes));

const form = reactive({ docente_id: '', papel: PAPEL.TITULAR, data_inicio: '', data_fim: '' });

watch(() => props.show, (show) => {
    if (!show) return;
    form.docente_id = '';
    form.papel = props.linha?.sem_titular === false ? PAPEL.SUBSTITUTO : PAPEL.TITULAR;
    form.data_inicio = hojeIso();
    form.data_fim = '';
});

function submeter() {
    emit('submit', {
        ...form,
        turma_id: props.turma?.id,
        disciplina_id: props.linha?.disciplina_id,
        data_fim: form.data_fim || null,
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">Atribuir docente</h3>
                <div class="text-muted fs-7 mb-5">{{ linha?.disciplina_nome }} · {{ rotuloTurma(turma) }}</div>
                <form @submit.prevent="submeter">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Docente</label>
                        <SelectSolid v-model="form.docente_id" :options="opcoesDocente" placeholder="Seleccione o docente" searchable />
                        <div class="text-danger fs-7 mt-1" v-if="errors.docente_id">{{ errors.docente_id }}</div>
                        <div class="text-muted fs-7 mt-1" v-else-if="!docentes.length">Não há docentes activos para atribuir.</div>
                    </div>

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
                            <span v-if="processing">A atribuir... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>Atribuir</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
