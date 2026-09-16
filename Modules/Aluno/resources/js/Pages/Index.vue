<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import AlunoFormModal from '../Components/AlunoFormModal.vue';
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
                            <th class="min-w-125px">Matrícula</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="alunos.data.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum aluno encontrado.</td>
                        </tr>
                        <tr v-for="aluno in alunos.data" :key="aluno.id">
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
