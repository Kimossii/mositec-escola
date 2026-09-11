<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import PlanoCurricularFormModal from '../Components/PlanoCurricularFormModal.vue';
import DisciplinaPlanoFormModal from '../Components/DisciplinaPlanoFormModal.vue';
import ConfirmarAnoLectivoModal from '../Components/ConfirmarAnoLectivoModal.vue';
import { ESTADO } from '../Models/Estado';

const props = defineProps({
    planoCurricular: { type: Object, required: true },
    opcoes: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

// --- Editar plano ---

const editModalAberto = ref(false);
const editProcessing = ref(false);
const editErrors = ref({});

function abrirEdicao() {
    editErrors.value = {};
    editModalAberto.value = true;
}

function fecharEdicao() {
    editModalAberto.value = false;
}

function guardar(payload) {
    editProcessing.value = true;
    editErrors.value = {};
    router.put(`/planos-curriculares/${props.planoCurricular.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Plano curricular atualizado com sucesso.');
            fecharEdicao();
        },
        onError: (erros) => {
            editErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            editProcessing.value = false;
        },
    });
}

// --- Ativar / Desativar plano ---

const confirmarEstadoAberto = ref(false);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado() {
    confirmarEstadoAberto.value = true;
}

function cancelarAlteracaoEstado() {
    confirmarEstadoAberto.value = false;
}

function confirmarAlteracaoEstado() {
    const novoEstado = props.planoCurricular.estado === ESTADO.ATIVO ? ESTADO.INATIVO : ESTADO.ATIVO;
    alterandoEstado.value = true;
    router.patch(`/planos-curriculares/${props.planoCurricular.id}/estado`, { estado: novoEstado }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do plano curricular atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            confirmarEstadoAberto.value = false;
        },
    });
}

// --- Disciplinas do plano ---

const disciplinaModalAberto = ref(false);
const disciplinaEmEdicao = ref(null);
const disciplinaProcessing = ref(false);
const disciplinaErrors = ref({});

function abrirAdicionarDisciplina() {
    disciplinaEmEdicao.value = null;
    disciplinaErrors.value = {};
    disciplinaModalAberto.value = true;
}

function abrirEdicaoDisciplina(disciplina) {
    disciplinaEmEdicao.value = disciplina;
    disciplinaErrors.value = {};
    disciplinaModalAberto.value = true;
}

function fecharDisciplina() {
    disciplinaModalAberto.value = false;
}

function guardarDisciplina(payload) {
    disciplinaProcessing.value = true;
    disciplinaErrors.value = {};

    const url = disciplinaEmEdicao.value
        ? `/planos-curriculares/${props.planoCurricular.id}/disciplinas/${disciplinaEmEdicao.value.id}`
        : `/planos-curriculares/${props.planoCurricular.id}/disciplinas`;
    const metodo = disciplinaEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(disciplinaEmEdicao.value ? 'Disciplina atualizada com sucesso.' : 'Disciplina adicionada ao plano com sucesso.');
            fecharDisciplina();
        },
        onError: (erros) => {
            disciplinaErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            disciplinaProcessing.value = false;
        },
    });
}

const gruposDisciplinas = computed(() => {
    const grupos = new Map();

    for (const item of props.planoCurricular.disciplinas ?? []) {
        const nivel = item.nivel_academico;
        const chave = nivel?.id ?? 'sem-nivel';

        if (!grupos.has(chave)) {
            grupos.set(chave, {
                nome: nivel?.nome ?? 'Sem nível académico',
                ordem: nivel?.ordem ?? Number.MAX_SAFE_INTEGER,
                disciplinas: [],
            });
        }

        grupos.get(chave).disciplinas.push(item);
    }

    return [...grupos.values()]
        .sort((a, b) => a.ordem - b.ordem)
        .map((grupo) => ({
            ...grupo,
            disciplinas: [...grupo.disciplinas].sort((a, b) => a.ordem - b.ordem),
        }));
});

const disciplinaParaRemover = ref(null);
const removendoDisciplina = ref(false);

function pedirRemocaoDisciplina(disciplina) {
    disciplinaParaRemover.value = disciplina;
}

function cancelarRemocaoDisciplina() {
    disciplinaParaRemover.value = null;
}

function confirmarRemocaoDisciplina() {
    removendoDisciplina.value = true;
    router.delete(`/planos-curriculares/${props.planoCurricular.id}/disciplinas/${disciplinaParaRemover.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Disciplina removida do plano com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            removendoDisciplina.value = false;
            disciplinaParaRemover.value = null;
        },
    });
}

// --- Anos lectivos do plano ---

const anoLectivoModalAberto = ref(false);
const anoLectivoProcessing = ref(false);
const anoLectivoErrors = ref({});

function abrirConfirmarAnoLectivo() {
    anoLectivoErrors.value = {};
    anoLectivoModalAberto.value = true;
}

function fecharAnoLectivo() {
    anoLectivoModalAberto.value = false;
}

function guardarAnoLectivo(payload) {
    anoLectivoProcessing.value = true;
    anoLectivoErrors.value = {};
    router.post(`/planos-curriculares/${props.planoCurricular.id}/anos-lectivos`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Plano confirmado para o ano lectivo com sucesso.');
            fecharAnoLectivo();
        },
        onError: (erros) => {
            anoLectivoErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            anoLectivoProcessing.value = false;
        },
    });
}

function formatarDataHora(valor) {
    if (!valor) return '—';
    return new Date(valor).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar :href="`/cursos/${planoCurricular.curso_id}`" class="mb-4" />

        <div class="card mb-6">
            <div class="card-body d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="fs-2 fw-bold mb-2">{{ planoCurricular.nome }}</h1>
                    <div class="text-muted fs-6 mb-3">
                        Código: {{ planoCurricular.codigo }} — Curso: {{ planoCurricular.curso?.nome ?? '—' }}
                    </div>
                    <EstadoBadge :estado="planoCurricular.estado" :estado-descricao="planoCurricular.estado_descricao" />
                </div>
                <div v-if="can('plano-curricular.editar')" class="d-flex gap-2">
                    <button
                        class="btn btn-light"
                        :class="planoCurricular.estado === ESTADO.ATIVO ? 'btn-light-danger' : 'btn-light-success'"
                        @click="pedirAlteracaoEstado"
                    >
                        {{ planoCurricular.estado === ESTADO.ATIVO ? 'Desativar' : 'Ativar' }}
                    </button>
                    <button class="btn btn-primary" @click="abrirEdicao">Editar</button>
                </div>
            </div>

            <div class="card-body border-top pt-6">
                <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Descrição</div>
                <div class="fs-6">{{ planoCurricular.descricao ?? '—' }}</div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header">
                <h3 class="card-title fw-bold">Disciplinas</h3>
                <div class="card-toolbar">
                    <button v-if="can('plano-curricular.editar')" class="btn btn-sm btn-primary" @click="abrirAdicionarDisciplina">
                        Adicionar Disciplina
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Disciplina</th>
                            <th class="min-w-125px">Carga Horária</th>
                            <th class="min-w-100px">Créditos</th>
                            <th class="min-w-125px">Componente</th>
                            <th class="min-w-100px">Tipo</th>
                            <th class="min-w-100px">Obrigatória</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody v-if="!planoCurricular.disciplinas?.length" class="text-gray-600 fw-semibold">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-6">Nenhuma disciplina associada a este plano.</td>
                        </tr>
                    </tbody>
                    <tbody v-for="grupo in gruposDisciplinas" :key="grupo.nome" class="text-gray-600 fw-semibold">
                        <tr class="bg-light-primary">
                            <td colspan="7" class="fw-bold text-gray-800">{{ grupo.nome }}</td>
                        </tr>
                        <tr v-for="disciplina in grupo.disciplinas" :key="disciplina.id">
                            <td>{{ disciplina.disciplina?.nome }}</td>
                            <td>{{ disciplina.carga_horaria ?? '—' }}</td>
                            <td>{{ disciplina.creditos ?? '—' }}</td>
                            <td>{{ disciplina.componente_descricao ?? '—' }}</td>
                            <td>{{ disciplina.tipo_descricao ?? '—' }}</td>
                            <td>{{ disciplina.obrigatoria ? 'Sim' : 'Não' }}</td>
                            <td class="text-end">
                                <a
                                    v-if="can('plano-curricular.editar')"
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
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicaoDisciplina(disciplina)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar
                                        </a>
                                    </div>
                                    <div class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirRemocaoDisciplina(disciplina)">
                                            <AcaoIcone acao="eliminar" class="me-2" />
                                            Remover
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title fw-bold">Anos Lectivos</h3>
                <div class="card-toolbar">
                    <button v-if="can('plano-curricular.editar')" class="btn btn-sm btn-primary" @click="abrirConfirmarAnoLectivo">
                        Confirmar para Ano Lectivo
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-125px">Ano Lectivo</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="min-w-150px">Confirmado em</th>
                            <th class="min-w-150px">Confirmado por</th>
                            <th class="min-w-200px">Observações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="!planoCurricular.anos_lectivos?.length">
                            <td colspan="5" class="text-center text-muted py-6">Este plano ainda não foi confirmado para nenhum ano lectivo.</td>
                        </tr>
                        <tr v-for="anoLectivo in planoCurricular.anos_lectivos" :key="anoLectivo.id">
                            <td>{{ anoLectivo.ano_lectivo?.nome ?? '—' }}</td>
                            <td>
                                <EstadoBadge :estado="anoLectivo.estado" :estado-descricao="anoLectivo.estado_descricao" />
                            </td>
                            <td>{{ formatarDataHora(anoLectivo.confirmado_em) }}</td>
                            <td>{{ anoLectivo.confirmado_por?.name ?? '—' }}</td>
                            <td>{{ anoLectivo.observacoes ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <PlanoCurricularFormModal
            :show="editModalAberto"
            :plano-curricular="planoCurricular"
            :opcoes="opcoes"
            :processing="editProcessing"
            :errors="editErrors"
            @submit="guardar"
            @cancelar="fecharEdicao"
        />

        <ConfirmModal
            :show="confirmarEstadoAberto"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado do plano curricular ${planoCurricular.nome} para '${planoCurricular.estado === ESTADO.ATIVO ? 'Inativo' : 'Ativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!disciplinaParaRemover"
            titulo="Remover disciplina"
            :mensagem="`Remover a disciplina ${disciplinaParaRemover?.disciplina?.nome} deste plano curricular?`"
            texto-confirmar="Remover"
            :processando="removendoDisciplina"
            @confirmar="confirmarRemocaoDisciplina"
            @cancelar="cancelarRemocaoDisciplina"
        />

        <DisciplinaPlanoFormModal
            :show="disciplinaModalAberto"
            :disciplina="disciplinaEmEdicao"
            :disciplinas-existentes="planoCurricular.disciplinas"
            :opcoes="opcoes"
            :processing="disciplinaProcessing"
            :errors="disciplinaErrors"
            @submit="guardarDisciplina"
            @cancelar="fecharDisciplina"
        />

        <ConfirmarAnoLectivoModal
            :show="anoLectivoModalAberto"
            :plano-curricular="planoCurricular"
            :opcoes="opcoes"
            :processing="anoLectivoProcessing"
            :errors="anoLectivoErrors"
            @submit="guardarAnoLectivo"
            @cancelar="fecharAnoLectivo"
        />
    </div>
</template>
