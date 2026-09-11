<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    planoCurricular: { type: Object, default: null },
    opcoes: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const opcoesCurso = () => props.opcoes.cursos.map((c) => ({ value: c.id, label: c.nome }));

const form = reactive({
    curso_id: '',
    codigo: '',
    nome: '',
    descricao: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.curso_id = props.planoCurricular?.curso_id ?? '';
    form.codigo = props.planoCurricular?.codigo ?? '';
    form.nome = props.planoCurricular?.nome ?? '';
    form.descricao = props.planoCurricular?.descricao ?? '';
});

function submeter() {
    const payload = { ...form };
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ planoCurricular ? 'Editar Plano Curricular' : 'Novo Plano Curricular' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Curso</label>
                        <SelectSolid v-model="form.curso_id" :options="opcoesCurso()" placeholder="Selecione o curso" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.curso_id">{{ errors.curso_id }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: INF-2026" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Plano Curricular de Informática" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
