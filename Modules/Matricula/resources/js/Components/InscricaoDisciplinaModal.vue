<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import {
    ESTADO_INSCRICAO_DISCIPLINA,
    estadoInscricaoDisciplinaBadgeClass,
    estadoInscricaoDisciplinaLabel,
    transicoesDisponiveisInscricaoDisciplina,
} from '../Models/EstadoInscricaoDisciplina';

const props = defineProps({
    show: { type: Boolean, default: false },
    alunoId: { type: [Number, String], default: null },
    matricula: { type: Object, default: null },
});
const emit = defineEmits(['fechar']);

function baseUrl() {
    return `/alunos/${props.alunoId}/matriculas/${props.matricula.id}/disciplinas`;
}

const carregando = ref(false);
const disciplinas = ref([]);
const disponiveis = ref([]);

async function carregar() {
    carregando.value = true;
    try {
        const [listaResp, disponiveisResp] = await Promise.all([
            axios.get(baseUrl()),
            axios.get(`${baseUrl()}-disponiveis`),
        ]);
        disciplinas.value = listaResp.data;
        disponiveis.value = disponiveisResp.data;
    } catch {
        toast.error('Não foi possível carregar as disciplinas desta matrícula.');
    } finally {
        carregando.value = false;
        // As linhas com o dropdown "Ações" só existem a partir daqui — o
        // KTMenu global (app.js) só liga o clique de novos triggers depois
        // de uma navegação Inertia, e este carregamento é um axios.get()
        // à parte, então sem isto o clique cai no <a href="#"> nativo.
        nextTick(() => window.KTMenu?.init());
    }
}

watch(() => props.show, (show) => {
    if (!show) return;
    formAberto.value = false;
    planoCurricularDisciplinaId.value = '';
    carregar();
});

// --- Inscrever manualmente ---
const formAberto = ref(false);
const planoCurricularDisciplinaId = ref('');
const inscrevendo = ref(false);

const opcoesDisponiveis = computed(() => disponiveis.value.map((item) => ({
    value: item.id,
    label: item.disciplina?.nome ?? `Disciplina #${item.disciplina_id}`,
})));

function abrirForm() {
    formAberto.value = true;
    planoCurricularDisciplinaId.value = '';
}

function inscrever() {
    if (!planoCurricularDisciplinaId.value) return;

    inscrevendo.value = true;
    router.post(baseUrl(), { plano_curricular_disciplina_id: planoCurricularDisciplinaId.value }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Disciplina inscrita com sucesso.');
            formAberto.value = false;
            carregar();
        },
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            inscrevendo.value = false;
        },
    });
}

// --- Alterar estado ---
const inscricaoParaAlterarEstado = ref(null);
const estadoAlvo = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(inscricao, novoEstado) {
    inscricaoParaAlterarEstado.value = inscricao;
    estadoAlvo.value = novoEstado;
}

function cancelarAlteracaoEstado() {
    inscricaoParaAlterarEstado.value = null;
    estadoAlvo.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    const inscricao = inscricaoParaAlterarEstado.value;
    router.patch(`${baseUrl()}/${inscricao.id}/estado`, { estado: estadoAlvo.value }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Estado da inscrição atualizado com sucesso.');
            carregar();
        },
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            cancelarAlteracaoEstado();
        },
    });
}

// --- Eliminar ---
const inscricaoParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(inscricao) {
    inscricaoParaEliminar.value = inscricao;
}

function cancelarEliminacao() {
    inscricaoParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    const inscricao = inscricaoParaEliminar.value;
    router.delete(`${baseUrl()}/${inscricao.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Inscrição eliminada com sucesso.');
            carregar();
        },
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            cancelarEliminacao();
        },
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-6">
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <h3 class="mb-0">Disciplinas — Matrícula {{ matricula?.numero_registo_matricula }}</h3>
                    <button type="button" class="btn-close" @click="emit('fechar')"></button>
                </div>

                <div v-if="carregando" class="text-center text-muted py-6">A carregar...</div>

                <template v-else>
                    <div class="table-responsive mb-4">
                        <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                    <th>Disciplina</th>
                                    <th class="min-w-100px">Estado</th>
                                    <th class="text-end min-w-125px">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-semibold">
                                <tr v-if="!disciplinas.length">
                                    <td colspan="3" class="text-center text-muted py-6">Nenhuma disciplina inscrita.</td>
                                </tr>
                                <tr v-for="inscricao in disciplinas" :key="inscricao.id">
                                    <td>{{ inscricao.plano_curricular_disciplina?.disciplina?.nome ?? '—' }}</td>
                                    <td>
                                        <div class="badge fw-bold" :class="estadoInscricaoDisciplinaBadgeClass(inscricao.estado)">
                                            {{ estadoInscricaoDisciplinaLabel(inscricao.estado) }}
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <a
                                            href="#"
                                            class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm"
                                            data-kt-menu-trigger="click"
                                            data-kt-menu-placement="bottom-end"
                                        >
                                            Ações
                                            <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                        </a>
                                        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                            <div
                                                v-for="proximoEstado in transicoesDisponiveisInscricaoDisciplina(inscricao.estado)"
                                                :key="proximoEstado"
                                                class="menu-item px-3"
                                            >
                                                <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(inscricao, proximoEstado)">
                                                    Marcar como {{ estadoInscricaoDisciplinaLabel(proximoEstado) }}
                                                </a>
                                            </div>
                                            <div v-if="inscricao.estado === ESTADO_INSCRICAO_DISCIPLINA.INSCRITA" class="menu-item px-3">
                                                <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(inscricao)">
                                                    Eliminar Inscrição
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="!formAberto">
                        <button
                            type="button"
                            class="btn btn-sm btn-light-primary"
                            :disabled="!opcoesDisponiveis.length"
                            @click="abrirForm"
                        >
                            + Inscrever Disciplina
                        </button>
                        <div v-if="!opcoesDisponiveis.length" class="text-muted fs-7 mt-2">
                            Não há disciplinas disponíveis para inscrever nesta matrícula.
                        </div>
                    </div>
                    <div v-else class="d-flex align-items-end gap-3">
                        <div class="flex-grow-1">
                            <label class="fw-semibold fs-7 mb-1">Disciplina</label>
                            <SelectSolid v-model="planoCurricularDisciplinaId" :options="opcoesDisponiveis" searchable placeholder="Selecione a disciplina" />
                        </div>
                        <button type="button" class="btn btn-primary" :disabled="!planoCurricularDisciplinaId || inscrevendo" @click="inscrever">
                            Inscrever
                        </button>
                        <button type="button" class="btn btn-light" :disabled="inscrevendo" @click="formAberto = false">
                            Cancelar
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <ConfirmModal
            :show="!!inscricaoParaAlterarEstado"
            titulo="Alterar Estado da Inscrição"
            :mensagem="`Marcar '${inscricaoParaAlterarEstado?.plano_curricular_disciplina?.disciplina?.nome}' como '${estadoInscricaoDisciplinaLabel(estadoAlvo)}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!inscricaoParaEliminar"
            titulo="Eliminar Inscrição"
            :mensagem="`Eliminar a inscrição em '${inscricaoParaEliminar?.plano_curricular_disciplina?.disciplina?.nome}'? Isto remove apenas a inscrição do aluno nesta disciplina — a disciplina e o plano curricular não são afectados.`"
            texto-confirmar="Eliminar"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
