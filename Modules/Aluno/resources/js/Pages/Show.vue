<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import AlunoFormModal from '../Components/AlunoFormModal.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import MatriculaEstadoBadge from '../../../../Matricula/resources/js/Components/Shared/EstadoBadge.vue';
import MatriculaFormModal from '../../../../Matricula/resources/js/Components/MatriculaFormModal.vue';
import InscricaoDisciplinaModal from '../../../../Matricula/resources/js/Components/InscricaoDisciplinaModal.vue';
import DocumentoPessoaModal from '../../../../Usuario/resources/js/Components/DocumentoPessoaModal.vue';
import { ESTADO_MATRICULA, estadoMatriculaBadgeClass, estadoMatriculaLabel, estadoMatriculaTerminal, transicoesDisponiveis } from '../../../../Matricula/resources/js/Models/Estado';

const props = defineProps({
    aluno: { type: Object, required: true },
    matriculas: { type: Object, default: () => ({ data: [], links: [] }) }, // paginator
    matriculaActual: { type: Object, default: null },
    outrasMatriculasActivas: { type: Array, default: () => [] },
    turmasDisponiveis: { type: Array, default: () => [] },
    anosLectivosComMatricula: { type: Array, default: () => [] },
    filtrosMatricula: { type: Object, default: () => ({}) },
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

const iniciaisAluno = computed(() => {
    const partes = (props.aluno.dados_pessoa?.nome_completo ?? '').trim().split(/\s+/).filter(Boolean);
    if (!partes.length) return '?';
    const primeira = partes[0][0];
    const ultima = partes.length > 1 ? partes[partes.length - 1][0] : '';
    return (primeira + ultima).toUpperCase();
});

// No Ensino Superior um aluno pode ter mais de uma matrícula activa em
// simultâneo (ex.: cursos diferentes) — por omissão mostra-se a mais
// recente (matriculaActual), mas clicar numa das "outras" no dropdown, ou
// em "Ver Situação Académica" na tabela de Matrículas mais abaixo (mesmo em
// registos terminais/históricos), espelha a secção para a matrícula
// escolhida. matriculaSelecionadaManual guarda o registo completo (não só
// um id) porque uma matrícula terminal não está em todasMatriculasActivas.
const todasMatriculasActivas = computed(() => [props.matriculaActual, ...props.outrasMatriculasActivas].filter(Boolean));

const matriculaSelecionadaManual = ref(null);

const matriculaExibida = computed(() => matriculaSelecionadaManual.value ?? props.matriculaActual);

// A ver a matrícula "actual" de facto (ou nenhuma selecção manual) — usado
// para decidir o título da secção e se o badge do cabeçalho acompanha.
const aVerMatriculaActual = computed(() => {
    return !matriculaSelecionadaManual.value || matriculaSelecionadaManual.value.id === props.matriculaActual?.id;
});

// O badge do cabeçalho ("Matrícula Activa/Pendente") só acompanha a
// selecção quando esta continua a ser uma matrícula não-terminal — mostrar
// aí uma Cancelada/Concluída só porque se está a inspeccionar o histórico
// dava a entender, erradamente, que é esse o estado actual do aluno.
const matriculaParaCabecalho = computed(() => {
    if (matriculaExibida.value && !estadoMatriculaTerminal(matriculaExibida.value.estado)) {
        return matriculaExibida.value;
    }
    return props.matriculaActual;
});

// O dropdown "+N" dentro da secção só lista outras matrículas ACTIVAS (faz
// sentido independentemente de se estar a ver uma delas ou uma terminal).
const outrasMatriculasParaMostrar = computed(() => {
    return todasMatriculasActivas.value.filter((matricula) => matricula.id !== matriculaExibida.value?.id);
});

const situacaoAcademicaEl = ref(null);

function selecionarMatricula(matricula) {
    matriculaSelecionadaManual.value = matricula;
    situacaoAcademicaEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    // Troca a matrícula exibida recria o badge "+N" da Sala (Vue re-renderiza
    // esse bloco) — sem isto o KTMenu global não liga o clique ao novo
    // elemento e o browser segue o href="#" literal, saltando para a home.
    nextTick(() => window.KTMenu?.init());
}

function voltarAMatriculaActual() {
    matriculaSelecionadaManual.value = null;
}

// Depois de qualquer reload do Inertia (nova matrícula, mudança de estado,
// etc.) a selecção volta a acompanhar a matrícula actual em vez de ficar
// presa a um registo que pode já não fazer sentido.
watch(() => props.matriculaActual?.id, () => {
    matriculaSelecionadaManual.value = null;
});

// Nada no módulo Turma impede duas associações activas (fim nulo) em
// simultâneo — quando acontece, mostramos a mais recente como "a" sala e
// as restantes ficam acessíveis no dropdown "+N" ao lado.
const salasActivasDaTurma = computed(() => {
    const turmaSalas = matriculaExibida.value?.turma?.turma_salas ?? [];
    return turmaSalas
        .filter((turmaSala) => !turmaSala.fim && turmaSala.sala)
        .sort((a, b) => (a.inicio < b.inicio ? 1 : -1));
});

const salaActual = computed(() => salasActivasDaTurma.value[0]?.sala ?? null);

const outrasSalasActivas = computed(() => salasActivasDaTurma.value.slice(1).map((turmaSala) => turmaSala.sala));

function guardar(payload) {
    processing.value = true;
    errors.value = {};
    // PHP não faz parsing do corpo multipart/form-data em pedidos PUT
    // nativos (só em POST) — por isso, com upload de ficheiro, submete-se
    // sempre via POST com spoofing de método (_method), nunca router.put
    // directo.
    router.post(`/alunos/${props.aluno.id}`, { ...payload, _method: 'put' }, {
        forceFormData: true,
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

// O filtro por omissão (ano lectivo activo) já vem calculado do backend em
// filtrosMatricula — aqui só reflectimos o que o servidor devolveu.
const filtros = reactive({
    ano_lectivo_id: props.filtrosMatricula.ano_lectivo_id ?? '',
    pesquisa: props.filtrosMatricula.pesquisa ?? '',
});

const opcoesAnoLectivo = computed(() => [
    { value: '', label: 'Todos os anos lectivos' },
    ...props.anosLectivosComMatricula.map((anoLectivo) => ({ value: anoLectivo.id, label: anoLectivo.nome })),
]);

let debounceMatriculasId = null;

watch(filtros, (valor) => {
    clearTimeout(debounceMatriculasId);
    debounceMatriculasId = setTimeout(() => {
        router.get(`/alunos/${props.aluno.id}`, valor, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

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

const matriculaParaVerDisciplinas = ref(null);

function abrirDisciplinas(matricula) {
    matriculaParaVerDisciplinas.value = matricula;
}

function fecharDisciplinas() {
    matriculaParaVerDisciplinas.value = null;
}

const documentosAbertos = ref(false);

function abrirDocumentos() {
    documentosAbertos.value = true;
}

function fecharDocumentos() {
    documentosAbertos.value = false;
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
    router.delete(`/alunos/${props.aluno.id}/matriculas/${matricula.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Matrícula eliminada com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            matriculaParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/alunos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div class="d-flex align-items-center gap-4">
                <div class="symbol symbol-circle symbol-75px overflow-hidden">
                    <div class="symbol-label bg-light-primary">
                        <img v-if="aluno.foto_url" :src="aluno.foto_url" :alt="aluno.dados_pessoa?.nome_completo" class="w-100 h-100 object-fit-cover" />
                        <span v-else class="fs-2x fw-bold text-primary">{{ iniciaisAluno }}</span>
                    </div>
                </div>
                <div>
                    <h1 class="fs-2 fw-bold mb-1">{{ aluno.dados_pessoa?.nome_completo }}</h1>
                    <div class="d-flex align-items-center gap-3">
                        <span class="text-muted">Matrícula: {{ aluno.numero_matricula }}</span>
                        <span v-if="matriculaParaCabecalho" class="badge fw-bold" :class="estadoMatriculaBadgeClass(matriculaParaCabecalho.estado)">
                            Matrícula {{ estadoMatriculaLabel(matriculaParaCabecalho.estado) }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button v-if="can('documento-pessoa.ver')" class="btn btn-light-primary" @click="abrirDocumentos">Documentos</button>
                <button v-if="can('aluno.editar')" class="btn btn-primary" @click="abrirEdicao">Editar</button>
            </div>
        </div>

        <div class="card" ref="situacaoAcademicaEl">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h4 class="fw-bold mb-0">
                        {{ aVerMatriculaActual ? 'Situação Académica Actual' : `Situação Académica — Matrícula ${matriculaExibida?.numero_registo_matricula ?? ''}` }}
                    </h4>
                    <a v-if="!aVerMatriculaActual" href="#" class="fs-7 fw-semibold" @click.prevent="voltarAMatriculaActual">
                        ← Voltar à situação actual
                    </a>
                </div>
                <template v-if="matriculaExibida">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="row mb-4">
                                <div class="col-md-4 fw-bold text-muted">Estado da matrícula</div>
                                <div class="col-md-8 d-flex align-items-center gap-2">
                                    <MatriculaEstadoBadge :estado="matriculaExibida.estado" />
                                    <a
                                        v-if="outrasMatriculasParaMostrar.length"
                                        href="#"
                                        class="badge badge-light-primary"
                                        data-kt-menu-trigger="click"
                                        data-kt-menu-placement="bottom-start"
                                        title="Ver outras matrículas activas deste aluno neste ano lectivo"
                                    >
                                        +{{ outrasMatriculasParaMostrar.length }}
                                    </a>
                                    <div
                                        v-if="outrasMatriculasParaMostrar.length"
                                        class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 fw-semibold fs-7 w-300px py-3"
                                        data-kt-menu="true"
                                    >
                                        <div class="menu-item px-3 pb-2">
                                            <span class="text-muted text-uppercase fs-8">Outras matrículas activas — clique para ver</span>
                                        </div>
                                        <div v-for="matricula in outrasMatriculasParaMostrar" :key="matricula.id" class="menu-item px-3">
                                            <a
                                                href="#"
                                                class="menu-link px-3 d-flex flex-column align-items-start py-2"
                                                data-kt-menu-dismiss="true"
                                                @click.prevent="selecionarMatricula(matricula)"
                                            >
                                                <span class="fw-bold">{{ matricula.turma?.curso?.nome ?? matricula.turma?.codigo }}</span>
                                                <span class="text-muted fs-8">{{ matricula.turma?.codigo }} — {{ matricula.turma?.nome }}</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="matriculaExibida.ano_lectivo" class="row mb-4">
                                <div class="col-md-4 fw-bold text-muted">Ano Lectivo</div>
                                <div class="col-md-8">{{ matriculaExibida.ano_lectivo.nome }}</div>
                            </div>
                            <div v-if="matriculaExibida.turma?.curso" class="row mb-4">
                                <div class="col-md-4 fw-bold text-muted">Curso</div>
                                <div class="col-md-8">{{ matriculaExibida.turma.curso.nome }}</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div v-if="matriculaExibida.turma?.nivel_academico" class="row mb-4">
                                <div class="col-md-4 fw-bold text-muted">Nível Académico</div>
                                <div class="col-md-8">{{ matriculaExibida.turma.nivel_academico.nome }}</div>
                            </div>
                            <div v-if="matriculaExibida.turma" class="row mb-4">
                                <div class="col-md-4 fw-bold text-muted">Turma</div>
                                <div class="col-md-8">{{ matriculaExibida.turma.codigo }} — {{ matriculaExibida.turma.nome }}</div>
                            </div>
                            <div v-if="matriculaExibida.turma?.turno" class="row mb-4">
                                <div class="col-md-4 fw-bold text-muted">Turno</div>
                                <div class="col-md-8">{{ matriculaExibida.turma.turno.nome }}</div>
                            </div>
                            <div v-if="salaActual" class="row mb-4">
                                <div class="col-md-4 fw-bold text-muted">Sala</div>
                                <div class="col-md-8 d-flex align-items-center gap-2">
                                    <span>{{ salaActual.codigo }} — {{ salaActual.nome }}</span>
                                    <a
                                        v-if="outrasSalasActivas.length"
                                        href="#"
                                        class="badge badge-light-primary"
                                        data-kt-menu-trigger="click"
                                        data-kt-menu-placement="bottom-start"
                                        title="Ver outras salas activas associadas a esta turma"
                                    >
                                        +{{ outrasSalasActivas.length }}
                                    </a>
                                    <div
                                        v-if="outrasSalasActivas.length"
                                        class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 fw-semibold fs-7 w-250px py-3"
                                        data-kt-menu="true"
                                    >
                                        <div class="menu-item px-3 pb-2">
                                            <span class="text-muted text-uppercase fs-8">Outras salas activas</span>
                                        </div>
                                        <div v-for="sala in outrasSalasActivas" :key="sala.id" class="menu-item px-3">
                                            <span class="menu-link px-3">{{ sala.codigo }} — {{ sala.nome }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
                <div v-else class="text-muted fs-7 mb-2">Sem matrícula no ano lectivo actual.</div>

                <div class="separator separator-dashed my-6"></div>

                <h4 class="fw-bold mb-4">Dados Pessoais</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="row mb-4">
                            <div class="col-md-4 fw-bold text-muted">Nome completo</div>
                            <div class="col-md-8">{{ aluno.dados_pessoa?.nome_completo ?? '—' }}</div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4 fw-bold text-muted">Nº de matrícula</div>
                            <div class="col-md-8">{{ aluno.numero_matricula }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row mb-4">
                            <div class="col-md-4 fw-bold text-muted">Data de nascimento</div>
                            <div class="col-md-8">{{ formatarData(aluno.dados_pessoa?.data_nascimento) }}</div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4 fw-bold text-muted">Nº de identificação</div>
                            <div class="col-md-8">{{ aluno.dados_pessoa?.numero_identificacao ?? '—' }}</div>
                        </div>
                    </div>
                </div>

                <div class="separator separator-dashed my-6"></div>

                <h4 class="fw-bold mb-4">Contactos</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="row mb-4">
                            <div class="col-md-4 fw-bold text-muted">Email</div>
                            <div class="col-md-8">{{ aluno.dados_pessoa?.email ?? '—' }}</div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4 fw-bold text-muted">Telefone Principal</div>
                            <div class="col-md-8">{{ aluno.dados_pessoa?.telefone ?? '—' }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row mb-4">
                            <div class="col-md-4 fw-bold text-muted">Telefone Alternativo</div>
                            <div class="col-md-8">{{ aluno.dados_pessoa?.telefone_alternativo ?? '—' }}</div>
                        </div>
                    </div>
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
            <div class="card-body pt-0 pb-4 d-flex flex-wrap gap-4">
                <input
                    v-model="filtros.pesquisa"
                    type="text"
                    class="form-control form-control-solid w-md-250px"
                    placeholder="Pesquisar por nº de registo ou turma..."
                />
                <div style="min-width: 220px;">
                    <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAnoLectivo" />
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
                        <tr v-if="!matriculas.data.length">
                            <td colspan="6" class="text-center text-muted py-6">Nenhuma matrícula encontrada.</td>
                        </tr>
                        <tr v-for="matricula in matriculas.data" :key="matricula.id">
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
                                        <a href="#" class="menu-link px-3" data-kt-menu-dismiss="true" @click.prevent="selecionarMatricula(matricula)">
                                            Ver Situação Académica
                                        </a>
                                    </div>
                                    <div
                                        v-if="[ESTADO_MATRICULA.PENDENTE, ESTADO_MATRICULA.ACTIVA].includes(matricula.estado)"
                                        class="menu-item px-3"
                                    >
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

        <InscricaoDisciplinaModal
            :show="!!matriculaParaVerDisciplinas"
            :aluno-id="aluno.id"
            :matricula="matriculaParaVerDisciplinas"
            @fechar="fecharDisciplinas"
        />

        <DocumentoPessoaModal
            :show="documentosAbertos"
            :dados-pessoa-id="aluno.dados_pessoa?.id"
            :nome-pessoa="aluno.dados_pessoa?.nome_completo"
            @fechar="fecharDocumentos"
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
