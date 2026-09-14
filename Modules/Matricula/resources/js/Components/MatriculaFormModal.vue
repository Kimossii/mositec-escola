<script setup>
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    matricula: { type: Object, default: null },
    turmasDisponiveis: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const opcoesTurma = computed(() => props.turmasDisponiveis.map((turma) => ({
    value: turma.id,
    label: `${turma.codigo} — ${turma.nome} · ${turma.ano_lectivo?.nome ?? '—'} · ${turma.curso?.nome ?? turma.nivel_academico?.nome ?? '—'}`,
})));

const form = reactive({
    turma_id: '',
    ano_lectivo_id: '',
    data_matricula: '',
    observacoes: '',
});

watch(() => props.show, (show) => {
    if (!show) return;

    if (props.matricula) {
        form.turma_id = props.matricula.turma_id;
        form.ano_lectivo_id = props.matricula.ano_lectivo_id;
        form.data_matricula = props.matricula.data_matricula?.slice(0, 10) ?? '';
        form.observacoes = props.matricula.observacoes ?? '';
        return;
    }

    form.turma_id = '';
    form.ano_lectivo_id = '';
    form.data_matricula = new Date().toISOString().slice(0, 10);
    form.observacoes = '';
});

watch(() => form.turma_id, (turmaId) => {
    const turma = props.turmasDisponiveis.find((t) => t.id === turmaId);
    form.ano_lectivo_id = turma?.ano_lectivo_id ?? '';
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ matricula ? 'Editar Matrícula' : 'Nova Matrícula' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Turma</label>
                        <SelectSolid v-model="form.turma_id" :options="opcoesTurma" placeholder="Selecione a turma" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.turma_id">{{ errors.turma_id }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Data da Matrícula</label>
                        <input v-model="form.data_matricula" type="date" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.data_matricula">{{ errors.data_matricula }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Observações</label>
                        <textarea v-model="form.observacoes" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.observacoes">{{ errors.observacoes }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">{{ matricula ? 'Guardar' : 'Matricular' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
