<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import AlunoFormModal from '../Components/AlunoFormModal.vue';
import MatriculaEstadoBadge from '../../../../Matricula/resources/js/Components/Shared/EstadoBadge.vue';
import { estadoMatriculaLabel } from '../../../../Matricula/resources/js/Models/Estado';
import { ESTADO } from '../Models/Estado';

const props = defineProps({
    alunos: { type: Object, required: true }, // paginator: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
    anosLectivosDisponiveis: { type: Array, default: () => [] },
    turmasDisponiveis: { type: Array, default: () => [] },
    cursosDisponiveis: { type: Array, default: () => [] },
    niveisAcademicosDisponiveis: { type: Array, default: () => [] },
});
defineOptions({ layout: AppLayout });

// --- Filtros ---
const CRITERIO_CAMPO = { curso: 'curso_id', turma: 'turma_id', nivel_academico: 'nivel_academico_id' };

function criterioInicial() {
    if (props.filtros.curso_id) return 'curso';
    if (props.filtros.turma_id) return 'turma';
    if (props.filtros.nivel_academico_id) return 'nivel_academico';
    return '';
}

const criterio = ref(criterioInicial());

const opcoesCriterio = [
    { value: '', label: 'Todos' },
    { value: 'curso', label: 'Curso' },
    { value: 'turma', label: 'Turma' },
    { value: 'nivel_academico', label: 'Nível Académico' },
];

const opcoesAnoLectivo = computed(() => [
    { value: '', label: 'Todos os anos lectivos' },
    ...props.anosLectivosDisponiveis.map((ano) => ({ value: ano.id, label: ano.nome })),
]);

const opcoesSegundoSelect = computed(() => {
    if (criterio.value === 'curso') {
        return props.cursosDisponiveis.map((curso) => ({ value: curso.id, label: curso.nome }));
    }
    if (criterio.value === 'nivel_academico') {
        return props.niveisAcademicosDisponiveis.map((nivel) => ({ value: nivel.id, label: nivel.nome }));
    }
    if (criterio.value === 'turma') {
        return props.turmasDisponiveis.map((turma) => {
            const partes = [
                `${turma.codigo} — ${turma.nome}`,
                turma.ano_lectivo?.nome,
                turma.curso?.nome,
                turma.nivel_academico?.nome,
            ].filter(Boolean);

            return { value: turma.id, label: partes.join(' · ') };
        });
    }
    return [];
});

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    ano_lectivo_id: props.filtros.ano_lectivo_id ?? '',
    curso_id: props.filtros.curso_id ?? '',
    turma_id: props.filtros.turma_id ?? '',
    nivel_academico_id: props.filtros.nivel_academico_id ?? '',
});

// Trocar o critério limpa os outros dois — só um filtro académico fica
// activo de cada vez, para não ocupar espaço na interface.
watch(criterio, () => {
    filtros.curso_id = '';
    filtros.turma_id = '';
    filtros.nivel_academico_id = '';
});

const valorSegundoSelect = computed({
    get: () => (criterio.value ? filtros[CRITERIO_CAMPO[criterio.value]] : ''),
    set: (valor) => {
        if (criterio.value) filtros[CRITERIO_CAMPO[criterio.value]] = valor;
    },
});

// As opções de turma dependem do ano lectivo (o servidor só envia as desse
// ano) — trocar de ano invalida a turma escolhida, que deixaria de aparecer
// no select mas continuaria a filtrar.
watch(() => filtros.ano_lectivo_id, () => {
    filtros.turma_id = '';
});

let debounceId = null;

watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get('/alunos', valor, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

// --- Criar/Editar ---
const modalAberto = ref(false);
const alunoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    alunoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(aluno) {
    alunoEmEdicao.value = aluno;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = alunoEmEdicao.value ? `/alunos/${alunoEmEdicao.value.id}` : '/alunos';
    // PHP não faz parsing do corpo multipart/form-data em pedidos PUT
    // nativos (só em POST) — por isso, com upload de ficheiro, submete-se
    // sempre via POST com spoofing de método (_method) quando é edição.
    const dados = alunoEmEdicao.value ? { ...payload, _method: 'put' } : payload;

    router.post(url, dados, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            toast.success(alunoEmEdicao.value ? 'Aluno atualizado com sucesso.' : 'Aluno criado com sucesso.');
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

const alunoParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(aluno, estado) {
    alunoParaAlterarEstado.value = aluno;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    alunoParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/alunos/${alunoParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do aluno atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            alunoParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}

// --- Colapso "Situação Académica" por linha ---
// Dados Pessoais/Contactos já vêm na própria listagem (aluno.dados_pessoa);
// só a Situação Académica e as Últimas Matrículas são pedidas ao expandir,
// para não sobrecarregar a página com dados que a maioria das linhas nunca
// vai mostrar.
const linhasExpandidas = ref(new Set());
const resumosAcademicos = reactive({});
const aCarregarResumo = ref(new Set());

function formatarData(data) {
    if (!data) return '—';
    const [ano, mes, dia] = data.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}

async function alternarLinha(aluno) {
    if (linhasExpandidas.value.has(aluno.id)) {
        linhasExpandidas.value.delete(aluno.id);
        linhasExpandidas.value = new Set(linhasExpandidas.value);
        return;
    }

    linhasExpandidas.value = new Set(linhasExpandidas.value).add(aluno.id);

    if (resumosAcademicos[aluno.id]) return;

    aCarregarResumo.value = new Set(aCarregarResumo.value).add(aluno.id);
    try {
        const { data } = await axios.get(`/alunos/${aluno.id}/resumo-academico`);
        resumosAcademicos[aluno.id] = data;
    } catch {
        toast.error('Não foi possível carregar a situação académica deste aluno.');
        linhasExpandidas.value.delete(aluno.id);
        linhasExpandidas.value = new Set(linhasExpandidas.value);
    } finally {
        const restantes = new Set(aCarregarResumo.value);
        restantes.delete(aluno.id);
        aCarregarResumo.value = restantes;
    }
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Alunos</h1>
            <button v-if="can('aluno.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Aluno</button>
        </div>

        <div class="card mb-6">
            <div class="card-body d-flex flex-wrap gap-4">
                <div style="min-width: 280px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Pesquisar</label>
                    <input
                        v-model="filtros.pesquisa"
                        type="text"
                        class="form-control form-control-solid"
                        placeholder="Nome, nº de matrícula ou nº de identificação"
                    />
                </div>
                <div style="min-width: 220px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                    <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAnoLectivo" />
                </div>
                <div style="min-width: 180px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Filtrar por</label>
                    <SelectSolid v-model="criterio" :options="opcoesCriterio" />
                </div>
                <div v-if="criterio" style="min-width: 260px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">{{ opcoesCriterio.find((o) => o.value === criterio)?.label }}</label>
                    <SelectSolid v-model="valorSegundoSelect" :options="opcoesSegundoSelect" searchable />
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="w-25px"></th>
                            <th class="min-w-125px">Matrícula</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="alunos.data.length === 0">
                            <td colspan="5" class="text-center text-muted py-6">Nenhum aluno encontrado.</td>
                        </tr>
                        <template v-for="aluno in alunos.data" :key="aluno.id">
                            <tr>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-icon btn-sm btn-light"
                                        :title="linhasExpandidas.has(aluno.id) ? 'Ocultar situação académica' : 'Ver situação académica'"
                                        @click="alternarLinha(aluno)"
                                    >
                                        <i class="ki-duotone fs-3" :class="linhasExpandidas.has(aluno.id) ? 'ki-up' : 'ki-down'"></i>
                                    </button>
                                </td>
                                <td>
                                    <a :href="`/alunos/${aluno.id}`" class="text-gray-800 text-hover-primary">{{ aluno.numero_matricula }}</a>
                                </td>
                                <td>{{ aluno.dados_pessoa?.nome_completo }}</td>
                                <td>
                                    <EstadoBadge :estado="aluno.estado" :estado-descricao="aluno.estado_descricao" />
                                </td>
                                <td class="text-end">
                                    <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                        Ações
                                        <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                    </a>
                                    <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                         <div class="menu-item px-3">
                                            <a :href="`/alunos/${aluno.id}`" class="menu-link px-3">
                                                <AcaoIcone acao="visualizar" class="me-2" />
                                                Ver detalhes
                                            </a>
                                        </div>
                                        <div v-if="can('aluno.editar')" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(aluno)">
                                                <AcaoIcone acao="editar" class="me-2" />
                                                Editar
                                            </a>
                                        </div>
                                        <div v-if="can('aluno.editar') && aluno.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(aluno, ESTADO.ATIVO)">
                                                <AcaoIcone acao="ativar" class="me-2" />
                                                Ativar
                                            </a>
                                        </div>
                                        <div v-if="can('aluno.editar') && aluno.estado === ESTADO.ATIVO" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(aluno, ESTADO.INATIVO)">
                                                <AcaoIcone acao="desativar" class="me-2" />
                                                Desativar
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="linhasExpandidas.has(aluno.id)">
                                <td colspan="5" class="bg-light-primary bg-opacity-25 p-6">
                                    <div v-if="aCarregarResumo.has(aluno.id)" class="text-muted fs-7">A carregar…</div>
                                    <div v-else>
                                        <div class="row g-6">
                                            <div class="col-md-6">
                                                <h6 class="fw-bold text-uppercase fs-8 text-muted mb-3">Situação Académica Actual</h6>
                                                <template v-if="resumosAcademicos[aluno.id]?.matriculaActual">
                                                    <div class="mb-2 d-flex align-items-center gap-2">
                                                        <MatriculaEstadoBadge :estado="resumosAcademicos[aluno.id].matriculaActual.estado" />
                                                    </div>
                                                    <div class="fs-7 mb-1"><span class="text-muted">Ano Lectivo:</span> {{ resumosAcademicos[aluno.id].matriculaActual.ano_lectivo?.nome ?? '—' }}</div>
                                                    <div class="fs-7 mb-1"><span class="text-muted">Curso:</span> {{ resumosAcademicos[aluno.id].matriculaActual.turma?.curso?.nome ?? '—' }}</div>
                                                    <div class="fs-7 mb-1"><span class="text-muted">Nível Académico:</span> {{ resumosAcademicos[aluno.id].matriculaActual.turma?.nivel_academico?.nome ?? '—' }}</div>
                                                    <div class="fs-7 mb-1"><span class="text-muted">Turma:</span> {{ resumosAcademicos[aluno.id].matriculaActual.turma?.codigo ?? '—' }}</div>
                                                    <div class="fs-7"><span class="text-muted">Turno:</span> {{ resumosAcademicos[aluno.id].matriculaActual.turma?.turno?.nome ?? '—' }}</div>
                                                </template>
                                                <div v-else class="text-muted fs-7">Sem matrícula no ano lectivo actual.</div>
                                            </div>

                                            <div class="col-md-6">
                                                <h6 class="fw-bold text-uppercase fs-8 text-muted mb-3">Dados Pessoais</h6>
                                                <div class="fs-7 mb-1"><span class="text-muted">Data de nascimento:</span> {{ formatarData(aluno.dados_pessoa?.data_nascimento) }}</div>
                                                <div class="fs-7 mb-1"><span class="text-muted">Nº de identificação:</span> {{ aluno.dados_pessoa?.numero_identificacao ?? '—' }}</div>
                                                <div class="fs-7 mb-1"><span class="text-muted">Email:</span> {{ aluno.dados_pessoa?.email ?? '—' }}</div>
                                                <div class="fs-7 mb-1"><span class="text-muted">Telefone Principal:</span> {{ aluno.dados_pessoa?.telefone ?? '—' }}</div>
                                                <div class="fs-7"><span class="text-muted">Telefone Alternativo:</span> {{ aluno.dados_pessoa?.telefone_alternativo ?? '—' }}</div>

                                                <div class="border border-dashed rounded p-3 mt-4">
                                                    <div class="d-flex align-items-center gap-2 text-muted">
                                                        <i class="ki-duotone ki-dollar fs-3"><span class="path1"></span><span class="path2"></span></i>
                                                        <span class="fs-7 fw-bold">Situação de Propinas</span>
                                                        <span class="badge badge-light fs-9 ms-auto">Brevemente</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-6">
                                            <h6 class="fw-bold text-uppercase fs-8 text-muted mb-3">Últimas Matrículas</h6>
                                            <table v-if="resumosAcademicos[aluno.id]?.ultimasMatriculas?.length" class="table table-sm fs-7 mb-0">
                                                <thead>
                                                    <tr class="text-muted text-uppercase fs-9">
                                                        <th>Turma</th>
                                                        <th>Curso</th>
                                                        <th>Nível Académico</th>
                                                        <th>Ano Lectivo</th>
                                                        <th>Estado</th>
                                                        <th>Data</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="matricula in resumosAcademicos[aluno.id].ultimasMatriculas" :key="matricula.id">
                                                        <td>{{ matricula.turma?.codigo ?? '—' }}</td>
                                                        <td>{{ matricula.turma?.curso?.nome ?? '—' }}</td>
                                                        <td>{{ matricula.turma?.nivel_academico?.nome ?? '—' }}</td>
                                                        <td>{{ matricula.ano_lectivo?.nome ?? '—' }}</td>
                                                        <td>{{ estadoMatriculaLabel(matricula.estado) }}</td>
                                                        <td>{{ formatarData(matricula.data_matricula) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <div v-else class="text-muted fs-7">Sem matrículas registadas.</div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div v-if="alunos.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="alunos.links" />
            </div>
        </div>

        <AlunoFormModal
            :show="modalAberto"
            :aluno="alunoEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!alunoParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado do aluno ${alunoParaAlterarEstado?.dados_pessoa?.nome_completo} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />
    </div>
</template>
