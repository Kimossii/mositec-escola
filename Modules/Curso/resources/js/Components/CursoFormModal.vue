<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    curso: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

const form = reactive({
    codigo: '',
    nome: '',
    descricao: '',
    estado: ESTADO.ATIVO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.codigo = props.curso?.codigo ?? '';
    form.nome = props.curso?.nome ?? '';
    form.descricao = props.curso?.descricao ?? '';
    form.estado = props.curso?.estado ?? ESTADO.ATIVO;
});

function submeter() {
    const payload = { ...form };
    if (!props.curso) delete payload.estado;
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ curso ? 'Editar Curso' : 'Novo Curso' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: INF" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Informática" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>

                    <div v-if="curso" class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Estado</label>
                        <SelectSolid v-model="form.estado" :options="ESTADO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.estado">{{ errors.estado }}</div>
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
