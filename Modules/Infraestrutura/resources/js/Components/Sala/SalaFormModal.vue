<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO_SALA, TIPO_SALA, TIPO_SALA_OPCOES } from '../../Models/Sala';

const props = defineProps({
    show: { type: Boolean, default: false },
    sala: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO_SALA.ATIVA, label: 'Ativa' },
    { value: ESTADO_SALA.MANUTENCAO, label: 'Em Manutenção' },
    { value: ESTADO_SALA.INATIVA, label: 'Inativa' },
];

const form = reactive({
    codigo: '',
    nome: '',
    tipo: TIPO_SALA.SALA_AULA,
    capacidade: '',
    localizacao: '',
    observacoes: '',
    estado: ESTADO_SALA.ATIVA,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.codigo = props.sala?.codigo ?? '';
    form.nome = props.sala?.nome ?? '';
    form.tipo = props.sala?.tipo ?? TIPO_SALA.SALA_AULA;
    form.capacidade = props.sala?.capacidade ?? '';
    form.localizacao = props.sala?.localizacao ?? '';
    form.observacoes = props.sala?.observacoes ?? '';
    form.estado = props.sala?.estado ?? ESTADO_SALA.ATIVA;
});

function submeter() {
    const payload = { ...form, capacidade: form.capacidade === '' ? null : form.capacidade };
    if (!props.sala) delete payload.estado;
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ sala ? 'Editar Sala' : 'Nova Sala' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: A101" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                            <SelectSolid v-model="form.tipo" :options="TIPO_SALA_OPCOES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Sala 101" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Capacidade</label>
                            <input v-model="form.capacidade" type="number" min="1" max="500" class="form-control form-control-solid" placeholder="ex: 40" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.capacidade">{{ errors.capacidade }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Localização</label>
                            <input v-model="form.localizacao" type="text" class="form-control form-control-solid" placeholder="ex: Bloco B, 1º Andar" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.localizacao">{{ errors.localizacao }}</div>
                        </div>
                    </div>

                    <div v-if="sala" class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Estado</label>
                        <SelectSolid v-model="form.estado" :options="ESTADO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.estado">{{ errors.estado }}</div>
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
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
