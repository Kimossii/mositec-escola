<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import MatriculaFormModal from '../Components/MatriculaFormModal.vue';
import InscricaoDisciplinaModal from '../Components/InscricaoDisciplinaModal.vue';
import { ESTADO_MATRICULA, ESTADO_MATRICULA_LABEL, estadoMatriculaLabel, transicoesDisponiveis } from '../Models/Estado';

const props = defineProps({
    matriculas: { type: Object, required: true }, // paginator: { data, links, ... }
    turmasDisponiveis: { type: Array, required: true },
    anosLectivosDisponiveis: { type: Array, required: true },
    filtros: { type: Object, default: () => ({}) },
});
defineOptions({ layout: AppLayout });

const opcoesAnoLectivo = computed(() => [
    { value: '', label: 'Todos os anos lectivos' },
    ...props.anosLectivosDisponiveis.map((anoLectivo) => ({ value: anoLectivo.id, label: anoLectivo.nome })),
]);

const opcoesTurma = computed(() => [
    { value: '', label: 'Todas as turmas' },
    ...props.turmasDisponiveis.map((turma) => {
        const partes = [
            `${turma.codigo} — ${turma.nome}`,
            turma.ano_lectivo?.nome,
            turma.turno?.nome,
            turma.curso?.nome,
            turma.nivel_academico?.nome,
        ].filter(Boolean);

        return { value: turma.id, label: partes.join(' · ') };
    }),
]);

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    ...Object.entries(ESTADO_MATRICULA_LABEL).map(([value, label]) => ({ value: Number(value), label })),
]);

const filtros = reactive({
    ano_lectivo_id: props.filtros.ano_lectivo_id ?? '',
    turma_id: props.filtros.turma_id ?? '',
    estado: props.filtros.estado ?? '',
    pesquisa: props.filtros.pesquisa ?? '',
});

let debounceId = null;

watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get('/matriculas', valor, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: reinitMenu,
        });
    }, 300);
});

function formatarData(data) {
    if (!data) return '—';
    const [ano, mes, dia] = data.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}

// Qualquer acção que altere uma matrícula recarrega a lista com preserveState
// (redirect()->back() do controller) — recria as linhas e os seus dropdowns
// "Ações", que ficam sem o clique ligado pelo KTMenu global até isto correr.
function reinitMenu() {
    nextTick(() => window.KTMenu?.init());
}

const matriculaModalAberto = ref(false);
const matriculaProcessing = ref(false);
const matriculaErrors = ref({});
const matriculaEmEdicao = ref(null);
const matriculaModoRenovacao = ref(false);
const matriculaParaRenovarManualmente = ref(null);

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
        router.post(`/alunos/${matricula.aluno_id}/matriculas/${matricula.id}/renovar`, {
            turma_id: payload.turma_id,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Matrícula renovada com sucesso.');
                fecharMatriculaModal();
                reinitMenu();
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
    router.put(`/alunos/${emEdicao.aluno_id}/matriculas/${emEdicao.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Matrícula atualizada com sucesso.');
            fecharMatriculaModal();
            reinitMenu();
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
    router.patch(`/alunos/${matricula.aluno_id}/matriculas/${matricula.id}/estado`, {
        estado: estadoAlvo.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Estado da matrícula atualizado com sucesso.');
            reinitMenu();
        },
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
    router.post(`/alunos/${matricula.aluno_id}/matriculas/${matricula.id}/renovar`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Matrícula renovada com sucesso.');
            reinitMenu();
        },
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

const matriculaParaVerDisciplinas = ref(null);

function abrirDisciplinas(matricula) {
    matriculaParaVerDisciplinas.value = matricula;
}

function fecharDisciplinas() {
    matriculaParaVerDisciplinas.value = null;
}

const matriculaParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(matricula) {
    matriculaParaEliminar.value = matricula;
}

function cancelarEliminacao() {
    matriculaParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    const matricula = matriculaParaEliminar.value;
    router.delete(`/alunos/${matricula.aluno_id}/matriculas/${matricula.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Matrícula eliminada com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            matriculaParaEliminar.value = null;
        },
    });
}

// Renovação em massa — só faz sentido para matrículas Concluídas, tal como
// a ação individual na página do aluno.
// Vazio (e a UI de selecção em massa toda escondida) para quem não pode
// renovar — a rota já exige matricula.criar, isto só evita mostrar
// checkboxes e um botão que iam sempre falhar com 403.
const matriculasConcluidasDaPagina = computed(() => {
    if (!can('matricula.criar')) return [];
    return props.matriculas.data.filter((m) => m.estado === ESTADO_MATRICULA.CONCLUIDA);
});

const seleccionadas = ref([]);

watch(() => props.matriculas, () => {
    seleccionadas.value = [];
});

const todasSeleccionadas = computed(() =>
    matriculasConcluidasDaPagina.value.length > 0
    && matriculasConcluidasDaPagina.value.every((m) => seleccionadas.value.includes(m.id))
);

function alternarTodas() {
    seleccionadas.value = todasSeleccionadas.value
        ? []
        : matriculasConcluidasDaPagina.value.map((m) => m.id);
}

const confirmandoRenovacaoEmMassa = ref(false);
const mostrarConfirmRenovacaoEmMassa = ref(false);

function pedirRenovacaoEmMassa() {
    mostrarConfirmRenovacaoEmMassa.value = true;
}

function cancelarRenovacaoEmMassa() {
    mostrarConfirmRenovacaoEmMassa.value = false;
}

function confirmarRenovacaoEmMassa() {
    confirmandoRenovacaoEmMassa.value = true;
    router.post('/matriculas/renovar-em-massa', {
        matricula_ids: seleccionadas.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            const mensagem = usePage().props.flash?.success;
            toast.success(mensagem ?? 'Renovação em massa concluída.');
            seleccionadas.value = [];
            reinitMenu();
        },
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            confirmandoRenovacaoEmMassa.value = false;
            mostrarConfirmRenovacaoEmMassa.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Matrículas</h1>
        </div>

        <div class="card mb-6">
            <div class="card-body d-flex flex-wrap gap-4">
                <div style="min-width: 280px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Aluno</label>
                    <input
                        v-model="filtros.pesquisa"
                        type="text"
                        class="form-control form-control-solid"
                        placeholder="Nº de matrícula, nome ou nº de identificação"
                    />
                </div>
                <div style="min-width: 200px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                    <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAnoLectivo" />
                </div>
                <div style="min-width: 260px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Turma</label>
                    <SelectSolid v-model="filtros.turma_id" :options="opcoesTurma" searchable />
                </div>
                <div style="min-width: 200px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                    <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header" :class="{ 'd-none': !matriculasConcluidasDaPagina.length }">
                <h3 class="card-title fw-bold">
                    {{ seleccionadas.length }} seleccionada(s)
                </h3>
                <div class="card-toolbar">
                    <button
                        class="btn btn-sm btn-primary"
                        :disabled="!seleccionadas.length"
                        @click="pedirRenovacaoEmMassa"
                    >
                        Renovar Seleccionadas
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="w-25px">
                                <input
                                    v-if="matriculasConcluidasDaPagina.length"
                                    type="checkbox"
                                    class="form-check-input"
                                    :checked="todasSeleccionadas"
                                    @change="alternarTodas"
                                />
                            </th>
                            <th class="min-w-125px">Nº Registo</th>
                            <th class="min-w-200px">Aluno</th>
                            <th class="min-w-150px">Turma</th>
                            <th class="min-w-100px">Ano Lectivo</th>
                            <th class="min-w-100px">Data</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-200px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="matriculas.data.length === 0">
                            <td colspan="8" class="text-center text-muted py-6">Nenhuma matrícula encontrada.</td>
                        </tr>
                        <tr v-for="matricula in matriculas.data" :key="matricula.id">
                            <td>
                                <input
                                    v-if="matriculasConcluidasDaPagina.includes(matricula)"
                                    v-model="seleccionadas"
                                    type="checkbox"
                                    class="form-check-input"
                                    :value="matricula.id"
                                />
                            </td>
                            <td>{{ matricula.numero_registo_matricula }}</td>
                            <td>{{ matricula.aluno?.dados_pessoa?.nome_completo ?? '—' }}</td>
                            <td>{{ matricula.turma?.codigo }} — {{ matricula.turma?.nome }}</td>
                            <td>{{ matricula.ano_lectivo?.nome ?? '—' }}</td>
                            <td>{{ formatarData(matricula.data_matricula) }}</td>
                            <td><EstadoBadge :estado="matricula.estado" /></td>
                            <td class="text-end">
                                <a :href="`/alunos/${matricula.aluno_id}`" class="btn btn-light btn-active-light-primary btn-sm me-2">
                                    Ver Aluno
                                </a>
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
                                        v-if="[ESTADO_MATRICULA.PENDENTE, ESTADO_MATRICULA.ACTIVA].includes(matricula.estado) && can('matricula.editar')"
                                        class="menu-item px-3"
                                    >
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicaoMatricula(matricula)">
                                            Editar
                                        </a>
                                    </div>
                                    <div
                                        v-for="proximoEstado in (can('matricula.editar') ? transicoesDisponiveis(matricula.estado) : [])"
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
                                        v-if="matricula.estado === ESTADO_MATRICULA.CONCLUIDA && can('matricula.criar')"
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
                                    <div v-if="can('matricula.ver')" class="menu-item px-3">
                                        <a
                                            href="#"
                                            class="menu-link px-3"
                                            @click.prevent="abrirDisciplinas(matricula)"
                                        >
                                            Disciplinas
                                        </a>
                                    </div>
                                    <div
                                        v-if="matricula.estado === ESTADO_MATRICULA.PENDENTE && can('matricula.eliminar')"
                                        class="menu-item px-3"
                                    >
                                        <a
                                            href="#"
                                            class="menu-link px-3 text-danger"
                                            @click.prevent="pedirEliminacao(matricula)"
                                        >
                                            Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="matriculas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="matriculas.links" />
            </div>
        </div>

        <ConfirmModal
            :show="mostrarConfirmRenovacaoEmMassa"
            titulo="Renovar Matrículas em Massa"
            :mensagem="`Renovar ${seleccionadas.length} matrícula(s) seleccionada(s)? O sistema vai tentar sugerir automaticamente a turma seguinte para cada uma; as que falharem ficam por renovar manualmente.`"
            texto-confirmar="Renovar"
            :processando="confirmandoRenovacaoEmMassa"
            @confirmar="confirmarRenovacaoEmMassa"
            @cancelar="cancelarRenovacaoEmMassa"
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

        <InscricaoDisciplinaModal
            :show="!!matriculaParaVerDisciplinas"
            :aluno-id="matriculaParaVerDisciplinas?.aluno_id"
            :matricula="matriculaParaVerDisciplinas"
            @fechar="fecharDisciplinas"
        />

        <ConfirmModal
            :show="!!matriculaParaEliminar"
            titulo="Eliminar Matrícula"
            :mensagem="`Tem a certeza que deseja eliminar a matrícula ${matriculaParaEliminar?.numero_registo_matricula}? Esta acção não pode ser desfeita pela interface.`"
            texto-confirmar="Eliminar"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
