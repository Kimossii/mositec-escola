<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO_ANO_LECTIVO } from '../Models/AnoLectivo';

const props = defineProps({
    show: { type: Boolean, default: false },
    anoLectivo: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO_ANO_LECTIVO.PLANEADO, label: 'Planeado' },
    { value: ESTADO_ANO_LECTIVO.ATIVO, label: 'Activo' },
    { value: ESTADO_ANO_LECTIVO.ENCERRADO, label: 'Encerrado' },
];

const form = reactive({
    nome: '',
    data_inicio: '',
    data_fim: '',
    estado: ESTADO_ANO_LECTIVO.PLANEADO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.anoLectivo?.nome ?? '';
    form.data_inicio = props.anoLectivo?.data_inicio ?? '';
    form.data_fim = props.anoLectivo?.data_fim ?? '';
    form.estado = props.anoLectivo?.estado ?? ESTADO_ANO_LECTIVO.PLANEADO;
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ anoLectivo ? 'Editar Ano Lectivo' : 'Novo Ano Lectivo' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: 2026/2027" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de início</label>
                            <input v-model="form.data_inicio" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_inicio">{{ errors.data_inicio }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de fim</label>
                            <input v-model="form.data_fim" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_fim">{{ errors.data_fim }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
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
