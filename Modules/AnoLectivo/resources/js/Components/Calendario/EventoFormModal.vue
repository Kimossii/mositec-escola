<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { TIPO_EVENTO_CALENDARIO, TIPO_EVENTO_CALENDARIO_OPCOES } from '../../Models/AnoLectivo';

const props = defineProps({
    show: { type: Boolean, default: false },
    evento: { type: Object, default: null },
    dataInicial: { type: String, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({
    titulo: '',
    descricao: '',
    tipo: TIPO_EVENTO_CALENDARIO.EVENTO,
    data_inicio: '',
    data_fim: '',
    dia_inteiro: true,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.titulo = props.evento?.titulo ?? '';
    form.descricao = props.evento?.descricao ?? '';
    form.tipo = props.evento?.tipo ?? TIPO_EVENTO_CALENDARIO.EVENTO;
    form.data_inicio = props.evento?.data_inicio ?? props.dataInicial ?? '';
    form.data_fim = props.evento?.data_fim ?? props.dataInicial ?? '';
    form.dia_inteiro = props.evento?.dia_inteiro ?? true;
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ evento ? 'Editar Evento' : 'Novo Evento' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Título</label>
                        <input v-model="form.titulo" type="text" class="form-control form-control-solid" placeholder="ex: Início das aulas" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.titulo">{{ errors.titulo }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                        <SelectSolid v-model="form.tipo" :options="TIPO_EVENTO_CALENDARIO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo }}</div>
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
                        <div class="form-check form-check-custom form-check-solid">
                            <input v-model="form.dia_inteiro" class="form-check-input" type="checkbox" id="evento-dia-inteiro" />
                            <label class="form-check-label fw-semibold fs-6" for="evento-dia-inteiro">Dia inteiro</label>
                        </div>
                        <div class="text-danger fs-7 mt-1" v-if="errors.dia_inteiro">{{ errors.dia_inteiro }}</div>
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
