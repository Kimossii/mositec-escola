<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO, ESTADO_OPCOES } from '../Models/Horario';

const props = defineProps({
    show: { type: Boolean, default: false },
    horario: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({
    nome: '',
    hora_inicio: '',
    hora_fim: '',
    estado: ESTADO.ATIVO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.horario?.nome ?? '';
    form.hora_inicio = props.horario?.hora_inicio ?? '';
    form.hora_fim = props.horario?.hora_fim ?? '';
    form.estado = props.horario?.estado ?? ESTADO.ATIVO;
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ horario ? 'Editar Horário' : 'Novo Horário' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Manhã" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Hora de início</label>
                            <input v-model="form.hora_inicio" type="time" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.hora_inicio">{{ errors.hora_inicio }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Hora de fim</label>
                            <input v-model="form.hora_fim" type="time" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.hora_fim">{{ errors.hora_fim }}</div>
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
