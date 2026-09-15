<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import AlunoFormModal from '../Components/AlunoFormModal.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import MatriculaEstadoBadge from '../../../../Matricula/resources/js/Components/Shared/EstadoBadge.vue';
import MatriculaFormModal from '../../../../Matricula/resources/js/Components/MatriculaFormModal.vue';
import { ESTADO_MATRICULA, estadoMatriculaLabel, transicoesDisponiveis } from '../../../../Matricula/resources/js/Models/Estado';

const props = defineProps({
    aluno: { type: Object, required: true },
    matriculas: { type: Array, default: () => [] },
    turmasDisponiveis: { type: Array, default: () => [] },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const processing = ref(false);
const errors = ref({});

function abrirEdicao() {
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function formatarData(data) {
    if (!data) return '—';
    const [ano, mes, dia] = data.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};
    router.put(`/alunos/${props.aluno.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Aluno atualizado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const matriculaModalAberto = ref(false);
const matriculaProcessing = ref(false);
const matriculaErrors = ref({});
const matriculaEmEdicao = ref(null);
const matriculaModoRenovacao = ref(false);
const matriculaParaRenovarManualmente = ref(null);

function abrirNovaMatricula() {
    matriculaEmEdicao.value = null;
    matriculaModoRenovacao.value = false;
    matriculaErrors.value = {};
    matriculaModalAberto.value = true;
}

function abrirEdicaoMatricula(matricula) {
    matriculaEmEdicao.value = matricula;
    matriculaModoRenovacao.value = false;
    matriculaErrors.value = {};
    matriculaModalAberto.value = true;
}

function abrirRenovacaoManual(matricula) {
    matriculaEmEdicao.value = null;
    matriculaModoRenovacao.value = true;
    matriculaParaRenovarManualmente.value = matricula;
    matriculaErrors.value = {};
    matriculaModalAberto.value = true;
}

function fecharMatriculaModal() {
    matriculaModalAberto.value = false;
    matriculaEmEdicao.value = null;
    matriculaModoRenovacao.value = false;
    matriculaParaRenovarManualmente.value = null;
}

function guardarMatricula(payload) {
    matriculaProcessing.value = true;
    matriculaErrors.value = {};

    if (matriculaModoRenovacao.value) {
        const matricula = matriculaParaRenovarManualmente.value;
        router.post(`/alunos/${props.aluno.id}/matriculas/${matricula.id}/renovar`, {
            turma_id: payload.turma_id,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Matrícula renovada com sucesso.');
                fecharMatriculaModal();
            },
            onError: (erros) => {
                matriculaErrors.value = erros;
                toast.error(Object.values(erros)[0]);
            },
            onFinish: () => {
                matriculaProcessing.value = false;
            },
        });
        return;
    }

    const emEdicao = matriculaEmEdicao.value;
    const url = emEdicao
        ? `/alunos/${props.aluno.id}/matriculas/${emEdicao.id}`
        : `/alunos/${props.aluno.id}/matriculas`;
    const metodo = emEdicao ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(emEdicao ? 'Matrícula atualizada com sucesso.' : 'Matrícula criada com sucesso.');
            fecharMatriculaModal();
        },
        onError: (erros) => {
            matriculaErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            matriculaProcessing.value = false;
        },
    });
}

const matriculaParaAlterarEstado = ref(null);
const estadoAlvo = ref(null);
const confirmandoEstado = ref(false);

function pedirAlteracaoEstado(matricula, novoEstado) {
    matriculaParaAlterarEstado.value = matricula;
    estadoAlvo.value = novoEstado;
}

function cancelarAlteracaoEstado() {
    matriculaParaAlterarEstado.value = null;
    estadoAlvo.value = null;
}

function confirmarAlteracaoEstado() {
    confirmandoEstado.value = true;
    const matricula = matriculaParaAlterarEstado.value;
    router.patch(`/alunos/${props.aluno.id}/matriculas/${matricula.id}/estado`, {
        estado: estadoAlvo.value,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado da matrícula atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            confirmandoEstado.value = false;
            matriculaParaAlterarEstado.value = null;
            estadoAlvo.value = null;
        },
    });
}

const matriculaParaRenovar = ref(null);
const confirmandoRenovacao = ref(false);

function pedirRenovacao(matricula) {
    matriculaParaRenovar.value = matricula;
}

function cancelarRenovacao() {
    matriculaParaRenovar.value = null;
}

function confirmarRenovacao() {
    confirmandoRenovacao.value = true;
    const matricula = matriculaParaRenovar.value;
    router.post(`/alunos/${props.aluno.id}/matriculas/${matricula.id}/renovar`, {}, {
        preserveScroll: true,
        onSuccess: () => toast.success('Matrícula renovada com sucesso.'),
        onError: (erros) => {
            if (erros.turma_id) {
                abrirRenovacaoManual(matricula);
                return;
            }
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            confirmandoRenovacao.value = false;
            matriculaParaRenovar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/alunos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ aluno.dados_pessoa?.nome_completo }}</h1>
                <span class="text-muted">Matrícula: {{ aluno.numero_matricula }}</span>
            </div>
            <button v-if="can('aluno.editar')" class="btn btn-primary" @click="abrirEdicao">Editar</button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Estado</div>
                    <div class="col-md-9">
                        <EstadoBadge :estado="aluno.estado" :estado-descricao="aluno.estado_descricao" />
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Email</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.email ?? '—' }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Telefone</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.telefone ?? '—' }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Data de nascimento</div>
                    <div class="col-md-9">{{ formatarData(aluno.dados_pessoa?.data_nascimento) }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Número de identificação</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.numero_identificacao ?? '—' }}</div>
                </div>
            </div>
        </div>

        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title fw-bold">Matrículas</h3>
                <div class="card-toolbar">
                    <button v-if="can('matricula.criar')" class="btn btn-sm btn-primary" @click="abrirNovaMatricula">
                        Nova Matrícula
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-125px">Nº Registo</th>
                            <th class="min-w-150px">Turma</th>
                            <th class="min-w-100px">Ano Lectivo</th>
                            <th class="min-w-100px">Data</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-150px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="!matriculas.length">
                            <td colspan="6" class="text-center text-muted py-6">Nenhuma matrícula registada.</td>
                        </tr>
                        <tr v-for="matricula in matriculas" :key="matricula.id">
                            <td>{{ matricula.numero_registo_matricula }}</td>
                            <td>{{ matricula.turma?.codigo }} — {{ matricula.turma?.nome }}</td>
                            <td>{{ matricula.ano_lectivo?.nome ?? '—' }}</td>
                            <td>{{ formatarData(matricula.data_matricula) }}</td>
                            <td><MatriculaEstadoBadge :estado="matricula.estado" /></td>
                            <td class="text-end">
                                <a
                                    v-if="can('matricula.editar')"
                                    href="#"
                                    class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm"
                                    data-kt-menu-trigger="click"
                                    data-kt-menu-placement="bottom-end"
                                >
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicaoMatricula(matricula)">
                                            Editar
                                        </a>
                                    </div>
                                    <div
                                        v-for="proximoEstado in transicoesDisponiveis(matricula.estado)"
                                        :key="proximoEstado"
                                        class="menu-item px-3"
                                    >
                                        <a
                                            href="#"
                                            class="menu-link px-3"
                                            @click.prevent="pedirAlteracaoEstado(matricula, proximoEstado)"
                                        >
                                            Marcar como {{ estadoMatriculaLabel(proximoEstado) }}
                                        </a>
                                    </div>
                                    <div
                                        v-if="matricula.estado === ESTADO_MATRICULA.CONCLUIDA"
                                        class="menu-item px-3"
                                    >
                                        <a
                                            href="#"
                                            class="menu-link px-3"
                                            @click.prevent="pedirRenovacao(matricula)"
                                        >
                                            Renovar Matrícula
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AlunoFormModal
            :show="modalAberto"
            :aluno="aluno"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <MatriculaFormModal
            :show="matriculaModalAberto"
            :matricula="matriculaEmEdicao"
            :renovacao="matriculaModoRenovacao"
            :turmas-disponiveis="turmasDisponiveis"
            :processing="matriculaProcessing"
            :errors="matriculaErrors"
            @submit="guardarMatricula"
            @cancelar="fecharMatriculaModal"
        />

        <ConfirmModal
            :show="!!matriculaParaAlterarEstado"
            titulo="Alterar Estado da Matrícula"
            :mensagem="`Alterar o estado da matrícula ${matriculaParaAlterarEstado?.numero_registo_matricula} para '${estadoMatriculaLabel(estadoAlvo)}'?`"
            texto-confirmar="Confirmar"
            :processando="confirmandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!matriculaParaRenovar"
            titulo="Renovar Matrícula"
            :mensagem="`Renovar a matrícula ${matriculaParaRenovar?.numero_registo_matricula}? O sistema vai tentar sugerir automaticamente a turma seguinte.`"
            texto-confirmar="Renovar"
            :processando="confirmandoRenovacao"
            @confirmar="confirmarRenovacao"
            @cancelar="cancelarRenovacao"
        />
    </div>
</template>
