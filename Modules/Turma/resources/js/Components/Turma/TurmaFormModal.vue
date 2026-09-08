<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    turma: { type: Object, default: null },
    anoLectivos: { type: Array, required: true },
    niveisAcademicos: { type: Array, required: true },
    turnos: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

const opcoesAnoLectivo = () => props.anoLectivos.map((a) => ({ value: a.id, label: a.nome }));
const opcoesNivelAcademico = () => props.niveisAcademicos.map((n) => ({ value: n.id, label: n.nome }));
const opcoesTurno = () => [{ value: '', label: 'Sem turno' }, ...props.turnos.map((t) => ({ value: t.id, label: t.nome }))];

const form = reactive({
    ano_lectivo_id: '',
    nivel_academico_id: '',
    codigo: '',
    nome: '',
    turno_id: '',
    estado: ESTADO.ATIVO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.ano_lectivo_id = props.turma?.ano_lectivo_id ?? '';
    form.nivel_academico_id = props.turma?.nivel_academico_id ?? '';
    form.codigo = props.turma?.codigo ?? '';
    form.nome = props.turma?.nome ?? '';
    form.turno_id = props.turma?.turno_id ?? '';
    form.estado = props.turma?.estado ?? ESTADO.ATIVO;
});

function submeter() {
    const payload = { ...form, turno_id: form.turno_id === '' ? null : form.turno_id };
    if (props.turma) delete payload.ano_lectivo_id;
    else delete payload.estado;
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ turma ? 'Editar Turma' : 'Nova Turma' }}</h3>
                <form @submit.prevent="submeter">
                    <div v-if="!turma" class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Ano Lectivo</label>
                        <SelectSolid v-model="form.ano_lectivo_id" :options="opcoesAnoLectivo()" placeholder="Selecione o ano lectivo" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.ano_lectivo_id">{{ errors.ano_lectivo_id }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nível Académico</label>
                        <SelectSolid v-model="form.nivel_academico_id" :options="opcoesNivelAcademico()" placeholder="Selecione o nível académico" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nivel_academico_id">{{ errors.nivel_academico_id }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: T1" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Turma 1" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Turno</label>
                        <SelectSolid v-model="form.turno_id" :options="opcoesTurno()" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.turno_id">{{ errors.turno_id }}</div>
                    </div>

                    <div v-if="turma" class="fv-row mb-7">
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
