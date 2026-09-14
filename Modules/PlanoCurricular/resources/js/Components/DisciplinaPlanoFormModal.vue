<script setup>
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    disciplina: { type: Object, default: null },
    disciplinasExistentes: { type: Array, default: () => [] },
    opcoes: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const COMPONENTE_OPCOES = [
    { value: null, label: 'Sem componente' },
    { value: 1, label: 'Geral' },
    { value: 2, label: 'Técnica' },
    { value: 3, label: 'Prática' },
];

const TIPO_OPCOES = [
    { value: 0, label: 'Normal' },
    { value: 1, label: 'Estágio' },
    { value: 2, label: 'Optativa' },
    { value: 3, label: 'Projecto' },
];

const opcoesDisciplinas = computed(() => props.opcoes.disciplinas.map((d) => ({ value: d.id, label: d.nome })));

// Sugestão de ordem para uma disciplina nova: a seguir à última já
// existente no plano. O campo continua editável — é só um ponto de
// partida, para o utilizador não ter de ir contar manualmente.
const proximaOrdemSugerida = computed(() => {
    if (!props.disciplinasExistentes.length) return 0;
    return Math.max(...props.disciplinasExistentes.map((d) => d.ordem)) + 1;
});

const form = reactive({
    disciplina_id: '',
    carga_horaria: null,
    creditos: null,
    componente: null,
    tipo: 0,
    obrigatoria: true,
    ordem: 0,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.disciplina_id = props.disciplina?.disciplina?.id ?? '';
    form.carga_horaria = props.disciplina?.carga_horaria ?? null;
    form.creditos = props.disciplina?.creditos ?? null;
    form.componente = props.disciplina?.componente ?? null;
    form.tipo = props.disciplina?.tipo ?? 0;
    form.obrigatoria = props.disciplina?.obrigatoria ?? true;
    form.ordem = props.disciplina?.ordem ?? proximaOrdemSugerida.value;
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ disciplina ? 'Editar Disciplina do Plano' : 'Adicionar Disciplina ao Plano' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Disciplina</label>
                        <SelectSolid v-model="form.disciplina_id" :options="opcoesDisciplinas" placeholder="Selecione a disciplina" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.disciplina_id">{{ errors.disciplina_id }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Carga Horária</label>
                            <input v-model="form.carga_horaria" type="number" min="1" class="form-control form-control-solid" placeholder="ex: 90" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.carga_horaria">{{ errors.carga_horaria }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Créditos</label>
                            <input v-model="form.creditos" type="number" min="1" class="form-control form-control-solid" placeholder="ex: 6" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.creditos">{{ errors.creditos }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Componente</label>
                            <SelectSolid v-model="form.componente" :options="COMPONENTE_OPCOES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.componente">{{ errors.componente }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                            <SelectSolid v-model="form.tipo" :options="TIPO_OPCOES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ordem</label>
                            <input v-model="form.ordem" type="number" min="0" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.ordem">{{ errors.ordem }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7 d-flex align-items-end">
                            <div class="form-check form-check-custom form-check-solid">
                                <input v-model="form.obrigatoria" class="form-check-input" type="checkbox" id="disciplina-plano-obrigatoria" />
                                <label class="form-check-label fw-semibold fs-6" for="disciplina-plano-obrigatoria">Obrigatória</label>
                            </div>
                            <div class="text-danger fs-7 mt-1" v-if="errors.obrigatoria">{{ errors.obrigatoria }}</div>
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
