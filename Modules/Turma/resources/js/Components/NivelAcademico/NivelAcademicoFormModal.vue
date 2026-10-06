<script setup>
import { reactive, watch, computed } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    nivelAcademico: { type: Object, default: null },
    etapasEnsino: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

const opcoesEtapaEnsino = computed(() => props.etapasEnsino.map((e) => ({ value: e.etapa_ensino, label: e.etapa_ensino_descricao })));

const form = reactive({
    codigo: '',
    nome: '',
    ordem: 1,
    etapa_ensino: '',
    estado: ESTADO.ATIVO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.codigo = props.nivelAcademico?.codigo ?? '';
    form.nome = props.nivelAcademico?.nome ?? '';
    form.ordem = props.nivelAcademico?.ordem ?? 1;
    form.etapa_ensino = props.nivelAcademico?.etapa_ensino ?? '';
    form.estado = props.nivelAcademico?.estado ?? ESTADO.ATIVO;
});

function submeter() {
    const payload = { ...form };
    if (!props.nivelAcademico) delete payload.estado;
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ nivelAcademico ? 'Editar Nível Académico' : 'Novo Nível Académico' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: 1C" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ordem</label>
                            <input v-model="form.ordem" type="number" min="1" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.ordem">{{ errors.ordem }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: 1ª Classe" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Etapa de Ensino</label>
                        <SelectSolid v-model="form.etapa_ensino" :options="opcoesEtapaEnsino" placeholder="Selecione a etapa de ensino" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.etapa_ensino">{{ errors.etapa_ensino }}</div>
                    </div>

                    <div v-if="nivelAcademico" class="fv-row mb-7">
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
