<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import TurmaTabs from '../../Components/Shared/TurmaTabs.vue';
import TurnoFormModal from '../../Components/Turno/TurnoFormModal.vue';
import TurnoHorariosModal from '../../Components/Turno/TurnoHorariosModal.vue';
import { ESTADO } from '../../Models/Estado';

defineProps({
    turnos: { type: Array, required: true },
    horariosDisponiveis: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const turnoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    turnoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(turno) {
    turnoEmEdicao.value = turno;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = turnoEmEdicao.value ? `/turnos/${turnoEmEdicao.value.id}` : '/turnos';
    const metodo = turnoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(turnoEmEdicao.value ? 'Turno atualizado com sucesso.' : 'Turno criado com sucesso.');
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

const turnoParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(turno, estado) {
    turnoParaAlterarEstado.value = turno;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    turnoParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/turnos/${turnoParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do turno atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            turnoParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}

const turnoParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(turno) {
    turnoParaEliminar.value = turno;
}

function cancelarEliminacao() {
    turnoParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/turnos/${turnoParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Turno eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            turnoParaEliminar.value = null;
        },
    });
}

const modalHorariosAberto = ref(false);
const turnoParaHorarios = ref(null);
const processingHorario = ref(false);
const errorsHorario = ref({});

function abrirHorarios(turno) {
    turnoParaHorarios.value = turno;
    errorsHorario.value = {};
    modalHorariosAberto.value = true;
}

function fecharHorarios() {
    modalHorariosAberto.value = false;
    turnoParaHorarios.value = null;
}

function adicionarHorario(payload) {
    processingHorario.value = true;
    errorsHorario.value = {};
    router.post(`/turnos/${turnoParaHorarios.value.id}/horarios`, payload, {
        preserveScroll: true,
        onSuccess: () => toast.success('Horário adicionado ao turno com sucesso.'),
        onError: (erros) => {
            errorsHorario.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processingHorario.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <TurmaTabs atual="turnos" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Turnos</h1>
            <button v-if="can('turmas.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Turno</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Nome</th>
                            <th class="min-w-100px">Horários</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="turnos.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum turno criado.</td>
                        </tr>
                        <tr v-for="turno in turnos" :key="turno.id">
                            <td>{{ turno.nome }}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-light-primary" @click="abrirHorarios(turno)">
                                    {{ turno.turno_horarios?.length ?? 0 }} horário(s)
                                </button>
                            </td>
                            <td>
                                <EstadoBadge :estado="turno.estado" :estado-descricao="turno.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('turmas.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(turno)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar
                                        </a>
                                    </div>
                                    <div v-if="can('turmas.editar') && turno.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(turno, ESTADO.ATIVO)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('turmas.editar') && turno.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(turno, ESTADO.INATIVO)">
                                            <AcaoIcone acao="desativar" class="me-2" />
                                            Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('turmas.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(turno)">
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
        </div>

        <TurnoFormModal
            :show="modalAberto"
            :turno="turnoEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <TurnoHorariosModal
            :show="modalHorariosAberto"
            :turno="turnoParaHorarios"
            :horarios-disponiveis="horariosDisponiveis"
            :processing="processingHorario"
            :errors="errorsHorario"
            @adicionar="adicionarHorario"
            @fechar="fecharHorarios"
        />

        <ConfirmModal
            :show="!!turnoParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado do turno ${turnoParaAlterarEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!turnoParaEliminar"
            titulo="Eliminar Turno"
            :mensagem="`Tem certeza que deseja eliminar o turno ${turnoParaEliminar?.nome}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
