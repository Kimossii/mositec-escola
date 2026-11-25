<script setup>
import { computed, reactive, watch } from 'vue';
import Loader from '@/Components/Shared/Loader.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import ErrosGerais from './ErrosGerais.vue';
import { DIAS, diaSeguinte, formatarDia, hojeIso } from '../Support/grelha';

/**
 * Criar uma aula na célula escolhida, ou alterá-la (cria uma nova versão a partir de uma data; a actual termina
 * na véspera). Só aparecem disciplinas com titular em vigor: o servidor recusa as outras.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    entrada: { type: Object, default: null }, // presente ao alterar
    celula: { type: Object, default: null }, // { dia_semana, horario_id } ao criar
    turma: { type: Object, default: null },
    tempos: { type: Array, default: () => [] },
    disciplinas: { type: Array, default: () => [] }, // [{ id, nome, tem_titular }]
    salas: { type: Array, default: () => [] },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['dia_semana', 'horario_id', 'disciplina_id', 'sala_id', 'vigencia_inicio', 'vigencia_fim'];

const form = reactive({ dia_semana: '', horario_id: '', disciplina_id: '', sala_id: '', vigencia_inicio: '', vigencia_fim: '' });

const alterar = computed(() => !!props.entrada);
const opcoesDia = DIAS.map((d) => ({ value: d.valor, label: d.nome }));
const opcoesTempo = computed(() => props.tempos.filter((t) => t.e_aula).map((t) => ({ value: t.id, label: `${t.nome} (${t.hora_inicio}–${t.hora_fim})` })));
const opcoesDisciplina = computed(() => props.disciplinas.filter((d) => d.tem_titular).map((d) => ({ value: d.id, label: d.nome })));
const semTitular = computed(() => props.disciplinas.filter((d) => !d.tem_titular).length);
const opcoesSala = computed(() => [{ value: '', label: 'Sem sala' }, ...props.salas.map((s) => ({ value: s.id, label: s.nome }))]);

watch(() => props.show, (show) => {
    if (!show) return;
    const hoje = hojeIso();
    if (props.entrada) {
        const e = props.entrada;
        Object.assign(form, {
            dia_semana: e.dia_semana, horario_id: e.horario_id, disciplina_id: e.disciplina_id, sala_id: e.sala_id ?? '',
            // A nova versão começa depois da actual: hoje, ou o dia a seguir ao início se esta ainda não começou.
            vigencia_inicio: hoje > e.vigencia_inicio ? hoje : diaSeguinte(e.vigencia_inicio),
            vigencia_fim: '',
        });
        return;
    }
    Object.assign(form, {
        dia_semana: props.celula?.dia_semana ?? '', horario_id: props.celula?.horario_id ?? '', disciplina_id: '', sala_id: '',
        vigencia_inicio: hoje, vigencia_fim: '',
    });
});

function submeter() {
    emit('submit', {
        dia_semana: form.dia_semana,
        horario_id: form.horario_id,
        disciplina_id: form.disciplina_id,
        sala_id: form.sala_id === '' ? null : form.sala_id,
        vigencia_inicio: form.vigencia_inicio,
        vigencia_fim: form.vigencia_fim || null,
        turma_id: props.turma?.id,
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">{{ alterar ? 'Alterar aula' : 'Adicionar aula ao horário' }}</h3>
                <div class="text-muted fs-7 mb-5">
                    {{ turma?.rotulo }}
                    <template v-if="alterar"> · em vigor desde {{ formatarDia(entrada.vigencia_inicio) }}. A alteração cria uma nova versão e a actual termina na véspera.</template>
                </div>
                <form @submit.prevent="submeter">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="row g-4 mb-5">
                        <div class="col-6">
                            <label class="required fw-semibold fs-6 mb-2">Dia</label>
                            <SelectSolid v-model="form.dia_semana" :options="opcoesDia" placeholder="Dia" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.dia_semana">{{ errors.dia_semana }}</div>
                        </div>
                        <div class="col-6">
                            <label class="required fw-semibold fs-6 mb-2">Tempo</label>
                            <SelectSolid v-model="form.horario_id" :options="opcoesTempo" placeholder="Tempo" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.horario_id">{{ errors.horario_id }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-5">
                        <label class="required fw-semibold fs-6 mb-2">Disciplina</label>
                        <SelectSolid v-model="form.disciplina_id" :options="opcoesDisciplina" placeholder="Seleccione a disciplina" searchable />
                        <div class="text-muted fs-8 mt-1" v-if="semTitular">
                            {{ semTitular }} {{ semTitular === 1 ? 'disciplina não aparece' : 'disciplinas não aparecem' }} por não ter docente titular em vigor.
                        </div>
                        <div class="text-danger fs-7 mt-1" v-if="errors.disciplina_id">{{ errors.disciplina_id }}</div>
                    </div>

                    <div class="fv-row mb-5">
                        <label class="fw-semibold fs-6 mb-2">Sala <span class="text-muted fw-normal">(opcional)</span></label>
                        <SelectSolid v-model="form.sala_id" :options="opcoesSala" placeholder="Sem sala" searchable />
                        <div class="text-danger fs-7 mt-1" v-if="errors.sala_id">{{ errors.sala_id }}</div>
                    </div>

                    <div class="row g-4 mb-7">
                        <div class="col-6">
                            <label class="required fw-semibold fs-6 mb-2">{{ alterar ? 'Nova versão desde' : 'Em vigor desde' }}</label>
                            <input v-model="form.vigencia_inicio" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.vigencia_inicio">{{ errors.vigencia_inicio }}</div>
                        </div>
                        <div class="col-6">
                            <label class="fw-semibold fs-6 mb-2">Até <span class="text-muted fw-normal">(opcional)</span></label>
                            <input v-model="form.vigencia_fim" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.vigencia_fim">{{ errors.vigencia_fim }}</div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-success" :disabled="processing">
                            <span v-if="processing">A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>{{ alterar ? 'Guardar alteração' : 'Adicionar' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
