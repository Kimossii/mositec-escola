<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    matricula: { type: Object, default: null },
    turmasDisponiveis: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    renovacao: { type: Boolean, default: false },
});
const emit = defineEmits(['submit', 'cancelar']);

// O Nível Académico está sempre presente; o Curso só aparece quando a turma
// tem um (Ensino Secundário/Superior — turmas de Creche/Pré-Escolar/Primário
// nunca têm). Mostramos os dois quando existirem, para a pesquisa encontrar
// tanto pelo Curso como pelo Nível.
const opcoesTurma = computed(() => props.turmasDisponiveis.map((turma) => {
    const partes = [
        `${turma.codigo} — ${turma.nome}`,
        turma.ano_lectivo?.nome,
        turma.turno?.nome,
        turma.curso?.nome,
        turma.nivel_academico?.nome,
    ].filter(Boolean);

    return { value: turma.id, label: partes.join(' · ') };
}));

const form = reactive({
    turma_id: '',
    ano_lectivo_id: '',
    data_matricula: '',
    observacoes: '',
});

const turmaSeleccionada = computed(() => props.turmasDisponiveis.find((t) => t.id === form.turma_id) ?? null);

const erroTurmaLocal = ref('');

watch(() => props.show, (show) => {
    if (!show) return;

    erroTurmaLocal.value = '';
    planoCurricular.value = null;
    planoConsultado.value = false;
    planoExpandido.value = false;

    if (props.matricula) {
        form.turma_id = props.matricula.turma_id;
        form.ano_lectivo_id = props.matricula.ano_lectivo_id;
        form.data_matricula = props.matricula.data_matricula?.slice(0, 10) ?? '';
        form.observacoes = props.matricula.observacoes ?? '';
        return;
    }

    form.turma_id = '';
    form.ano_lectivo_id = '';
    form.data_matricula = new Date().toISOString().slice(0, 10);
    form.observacoes = '';
});

// O plano curricular só é consultado/exigido ao criar uma matrícula nova
// (inclui a renovação manual, que também passa por CriarMatriculaAction) —
// ao editar uma matrícula existente, AtualizarMatriculaAction não impõe
// esta regra, por isso não faz sentido bloquear aqui.
const exigePlanoCurricular = computed(() => !props.matricula);

const planoCurricular = ref(null);
const planoConsultado = ref(false);
const carregandoPlano = ref(false);
const planoExpandido = ref(false);

async function consultarPlanoCurricular(turmaId) {
    planoCurricular.value = null;
    planoConsultado.value = false;
    planoExpandido.value = false;

    if (!turmaId || !exigePlanoCurricular.value) return;

    carregandoPlano.value = true;
    try {
        const { data } = await axios.get(`/turmas/${turmaId}/plano-curricular`);
        planoCurricular.value = data.plano;
    } finally {
        carregandoPlano.value = false;
        planoConsultado.value = true;
    }
}

const bloqueadoSemPlano = computed(() => exigePlanoCurricular.value
    && !!form.turma_id
    && planoConsultado.value
    && planoCurricular.value === null);

watch(() => form.turma_id, (turmaId) => {
    const turma = props.turmasDisponiveis.find((t) => t.id === turmaId);
    form.ano_lectivo_id = turma?.ano_lectivo_id ?? '';

    if (turmaId) {
        erroTurmaLocal.value = '';
    }

    consultarPlanoCurricular(turmaId);
});

function submeter() {
    if (!form.turma_id) {
        erroTurmaLocal.value = 'Selecione uma turma.';
        return;
    }

    erroTurmaLocal.value = '';
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ renovacao ? 'Renovar Matrícula' : (matricula ? 'Editar Matrícula' : 'Nova Matrícula') }}</h3>
                <p v-if="renovacao" class="text-muted fs-7 mb-5">
                    Não foi possível sugerir automaticamente a turma seguinte. Escolha-a manualmente.
                </p>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Turma</label>
                        <SelectSolid v-model="form.turma_id" :options="opcoesTurma" searchable placeholder="Selecione a turma" />
                        <div class="text-danger fs-7 mt-1" v-if="erroTurmaLocal">{{ erroTurmaLocal }}</div>
                        <div class="text-danger fs-7 mt-1" v-else-if="errors.turma_id">{{ errors.turma_id }}</div>
                    </div>

                    <div v-if="turmaSeleccionada" class="row mb-7">
                        <div class="col-6">
                            <label class="fw-semibold fs-7 text-muted mb-1">Curso</label>
                            <div class="fs-6">{{ turmaSeleccionada.curso?.nome ?? '—' }}</div>
                        </div>
                        <div class="col-6">
                            <label class="fw-semibold fs-7 text-muted mb-1">Nível Académico</label>
                            <div class="fs-6">{{ turmaSeleccionada.nivel_academico?.nome ?? '—' }}</div>
                        </div>
                    </div>

                    <div v-if="exigePlanoCurricular && form.turma_id" class="fv-row mb-7">
                        <div v-if="carregandoPlano" class="text-muted fs-7">A consultar Plano Curricular...</div>
                        <template v-else-if="planoConsultado">
                            <div v-if="planoCurricular" class="bg-light rounded p-3">
                                <a href="#" class="fw-semibold fs-6" @click.prevent="planoExpandido = !planoExpandido">
                                    Plano Curricular: {{ planoCurricular.nome }} ({{ planoCurricular.codigo }})
                                    <i class="ki-duotone ms-1" :class="planoExpandido ? 'ki-up' : 'ki-down'"></i>
                                </a>
                                <ul v-if="planoExpandido" class="mt-3 mb-0 ps-4">
                                    <li v-for="item in planoCurricular.disciplinas" :key="item.id" class="fs-7 d-flex align-items-center gap-2">
                                        {{ item.disciplina?.nome }}
                                        <span
                                            class="badge fs-9"
                                            :class="item.inscricao_automatica ? 'badge-light-success' : 'badge-light-secondary'"
                                        >
                                            {{ item.inscricao_automatica ? 'Automática' : 'Manual' }}
                                        </span>
                                    </li>
                                    <li v-if="!planoCurricular.disciplinas.length" class="fs-7 text-muted">
                                        Este plano ainda não tem disciplinas associadas.
                                    </li>
                                </ul>
                                <div v-if="planoExpandido && planoCurricular.disciplinas.length" class="text-muted fs-8 mt-2">
                                    As disciplinas "Automática" são inscritas ao confirmar a matrícula; as "Manual"
                                    ficam disponíveis para inscrição posterior no modal Disciplinas.
                                </div>
                            </div>
                            <div v-else class="alert alert-danger d-flex align-items-center fs-7 mb-0">
                                <i class="ki-duotone ki-shield-cross fs-2 me-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                                Não existe um Plano Curricular confirmado para esta turma. Configure o Plano Curricular
                                (Académico → Cursos → Plano Curricular) antes de matricular alunos aqui.
                            </div>
                        </template>
                    </div>

                    <template v-if="!renovacao">
                        <div class="fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data da Matrícula</label>
                            <input v-model="form.data_matricula" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_matricula">{{ errors.data_matricula }}</div>
                        </div>

                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Observações</label>
                            <textarea v-model="form.observacoes" class="form-control form-control-solid" rows="3"></textarea>
                            <div class="text-danger fs-7 mt-1" v-if="errors.observacoes">{{ errors.observacoes }}</div>
                        </div>
                    </template>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing || bloqueadoSemPlano">
                            {{ renovacao ? 'Renovar' : (matricula ? 'Guardar' : 'Matricular') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
