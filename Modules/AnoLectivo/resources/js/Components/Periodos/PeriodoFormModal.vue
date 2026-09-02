<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { TIPO_PERIODO, TIPO_PERIODO_OPCOES } from '../../Models/AnoLectivo';

const props = defineProps({
    show: { type: Boolean, default: false },
    periodo: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({
    nome: '',
    tipo: TIPO_PERIODO.TRIMESTRE,
    numero: null,
    data_inicio: '',
    data_fim: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.periodo?.nome ?? '';
    form.tipo = props.periodo?.tipo ?? TIPO_PERIODO.TRIMESTRE;
    form.numero = props.periodo?.numero ?? null;
    form.data_inicio = props.periodo?.data_inicio ?? '';
    form.data_fim = props.periodo?.data_fim ?? '';
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ periodo ? 'Editar Período' : 'Novo Período' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: 1.º Trimestre" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-8 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                            <SelectSolid v-model="form.tipo" :options="TIPO_PERIODO_OPCOES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Número</label>
                            <input v-model.number="form.numero" type="number" min="1" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero">{{ errors.numero }}</div>
                        </div>
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
