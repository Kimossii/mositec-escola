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
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import TurmaTabs from '../../Components/Shared/TurmaTabs.vue';
import TurmaFormModal from '../../Components/Turma/TurmaFormModal.vue';
import { ESTADO } from '../../Models/Estado';

const props = defineProps({
    turmas: { type: Object, required: true }, // paginator: { data, links, ... }
    anoLectivos: { type: Array, required: true },
    niveisAcademicos: { type: Array, required: true },
    cursos: { type: Array, required: true },
    turnos: { type: Array, required: true },
    filtros: { type: Object, default: () => ({}) },
});
defineOptions({ layout: AppLayout });

const opcoesAnoLectivo = computed(() => [
    { value: '', label: 'Todos os anos lectivos' },
    ...props.anoLectivos.map((anoLectivo) => ({ value: anoLectivo.id, label: anoLectivo.nome })),
]);

const opcoesCurso = computed(() => [
    { value: '', label: 'Todos os cursos' },
    ...props.cursos.map((curso) => ({ value: curso.id, label: curso.nome })),
]);

const opcoesNivelAcademico = computed(() => [
    { value: '', label: 'Todos os níveis' },
    ...props.niveisAcademicos.map((nivel) => ({ value: nivel.id, label: nivel.nome })),
]);

const opcoesTurno = computed(() => [
    { value: '', label: 'Todos os turnos' },
    ...props.turnos.map((turno) => ({ value: turno.id, label: turno.nome })),
]);

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
]);

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    ano_lectivo_id: props.filtros.ano_lectivo_id ?? '',
    curso_id: props.filtros.curso_id ?? '',
    nivel_academico_id: props.filtros.nivel_academico_id ?? '',
    turno_id: props.filtros.turno_id ?? '',
    estado: props.filtros.estado ?? '',
});

let debounceId = null;

watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get('/turmas', valor, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const modalAberto = ref(false);
const turmaEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    turmaEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(turma) {
    turmaEmEdicao.value = turma;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = turmaEmEdicao.value ? `/turmas/${turmaEmEdicao.value.id}` : '/turmas';
    const metodo = turmaEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(turmaEmEdicao.value ? 'Turma atualizada com sucesso.' : 'Turma criada com sucesso.');
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

const turmaParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(turma, estado) {
    turmaParaAlterarEstado.value = turma;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    turmaParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/turmas/${turmaParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado da turma atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            turmaParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}

const turmaParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(turma) {
    turmaParaEliminar.value = turma;
}

function cancelarEliminacao() {
    turmaParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/turmas/${turmaParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Turma eliminada com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            turmaParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <TurmaTabs atual="turmas" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Turmas</h1>
            <button v-if="can('turmas.criar')" class="btn btn-primary" @click="abrirCriacao">Nova Turma</button>
        </div>

        <div class="card mb-6">
            <div class="card-body d-flex flex-wrap gap-4">
                <div style="min-width: 220px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                    <input
                        v-model="filtros.pesquisa"
                        type="text"
                        class="form-control form-control-solid"
                        placeholder="Código ou nome"
                    />
                </div>
                <div style="min-width: 180px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                    <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAnoLectivo" />
                </div>
                <div style="min-width: 200px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Curso</label>
                    <SelectSolid v-model="filtros.curso_id" :options="opcoesCurso" searchable />
                </div>
                <div style="min-width: 200px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Nível Académico</label>
                    <SelectSolid v-model="filtros.nivel_academico_id" :options="opcoesNivelAcademico" searchable />
                </div>
                <div style="min-width: 180px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Turno</label>
                    <SelectSolid v-model="filtros.turno_id" :options="opcoesTurno" />
                </div>
                <div style="min-width: 160px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                    <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-150px">Curso</th>
                            <th class="min-w-100px">Ano Lectivo</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="turmas.data.length === 0">
                            <td colspan="6" class="text-center text-muted py-6">Nenhuma turma encontrada.</td>
                        </tr>
                        <tr v-for="turma in turmas.data" :key="turma.id">
                            <td>{{ turma.codigo }}</td>
                            <td>{{ turma.nome }}</td>
                            <td>{{ turma.curso?.nome ?? '—' }}</td>
                            <td>{{ turma.ano_lectivo?.nome ?? '—' }}</td>
                            <td>
                                <EstadoBadge :estado="turma.estado" :estado-descricao="turma.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div class="menu-item px-3">
                                        <a :href="`/turmas/${turma.id}`" class="menu-link px-3">
                                            <AcaoIcone acao="visualizar" class="me-2" />
                                            Ver
                                        </a>
                                    </div>
                                    <div v-if="can('turmas.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(turma)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar
                                        </a>
                                    </div>
                                    <div v-if="can('turmas.editar') && turma.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(turma, ESTADO.ATIVO)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('turmas.editar') && turma.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(turma, ESTADO.INATIVO)">
                                            <AcaoIcone acao="desativar" class="me-2" />
                                            Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('turmas.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(turma)">
                                            <AcaoIcone acao="eliminar" class="me-2" />
                                            Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="turmas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="turmas.links" />
            </div>
        </div>

        <TurmaFormModal
            :show="modalAberto"
            :turma="turmaEmEdicao"
            :ano-lectivos="anoLectivos"
            :niveis-academicos="niveisAcademicos"
            :cursos="cursos"
            :turnos="turnos"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!turmaParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado da turma ${turmaParaAlterarEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!turmaParaEliminar"
            titulo="Eliminar Turma"
            :mensagem="`Tem certeza que deseja eliminar a turma ${turmaParaEliminar?.nome}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
