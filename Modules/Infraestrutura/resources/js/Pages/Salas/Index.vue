<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SalaStatusBadge from '../../Components/Sala/SalaStatusBadge.vue';
import SalaFormModal from '../../Components/Sala/SalaFormModal.vue';
import { ESTADO_SALA, tipoSalaLabel } from '../../Models/Sala';

defineProps({
    salas: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

// --- Criar / Editar Sala ---
const modalAberto = ref(false);
const salaEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    salaEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(sala) {
    salaEmEdicao.value = sala;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = salaEmEdicao.value ? `/salas/${salaEmEdicao.value.id}` : '/salas';
    const metodo = salaEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(salaEmEdicao.value ? 'Sala atualizada com sucesso.' : 'Sala criada com sucesso.');
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

// --- Alterar estado ---
const salaParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(sala, estado) {
    salaParaAlterarEstado.value = sala;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    salaParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/salas/${salaParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado da sala atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            salaParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}

// --- Eliminar ---
const salaParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(sala) {
    salaParaEliminar.value = sala;
}

function cancelarEliminacao() {
    salaParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/salas/${salaParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Sala eliminada com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            salaParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Salas</h1>
            <button v-if="can('infraestrutura.criar')" class="btn btn-primary" @click="abrirCriacao">Nova Sala</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-150px">Tipo</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="salas.length === 0">
                            <td colspan="5" class="text-center text-muted py-6">Nenhuma sala criada.</td>
                        </tr>
                        <tr v-for="sala in salas" :key="sala.id">
                            <td>{{ sala.codigo }}</td>
                            <td>{{ sala.nome }}</td>
                            <td>{{ tipoSalaLabel(sala.tipo) }}</td>
                            <td>
                                <SalaStatusBadge :estado="sala.estado" :estado-descricao="sala.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div class="menu-item px-3">
                                        <a :href="`/salas/${sala.id}`" class="menu-link px-3">
                                            <AcaoIcone acao="visualizar" class="me-2" />
                                            Ver
                                        </a>
                                    </div>
                                    <div v-if="can('infraestrutura.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(sala)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar
                                        </a>
                                    </div>
                                    <div v-if="can('infraestrutura.editar') && sala.estado !== ESTADO_SALA.ATIVA" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(sala, ESTADO_SALA.ATIVA)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Reativar
                                        </a>
                                    </div>
                                    <div v-if="can('infraestrutura.editar') && sala.estado === ESTADO_SALA.ATIVA" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(sala, ESTADO_SALA.MANUTENCAO)">
                                            <AcaoIcone acao="manutencao" class="me-2" />
                                            Colocar em Manutenção
                                        </a>
                                    </div>
                                    <div v-if="can('infraestrutura.editar') && sala.estado !== ESTADO_SALA.INATIVA" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(sala, ESTADO_SALA.INATIVA)">
                                            <AcaoIcone acao="desativar" class="me-2" />
                                            Inativar
                                        </a>
                                    </div>
                                    <div v-if="can('infraestrutura.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(sala)">
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

        <SalaFormModal
            :show="modalAberto"
            :sala="salaEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!salaParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado da sala ${salaParaAlterarEstado?.nome} para '${novoEstado === ESTADO_SALA.ATIVA ? 'Ativa' : novoEstado === ESTADO_SALA.MANUTENCAO ? 'Em Manutenção' : 'Inativa'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!salaParaEliminar"
            titulo="Eliminar Sala"
            :mensagem="`Tem certeza que deseja eliminar a sala ${salaParaEliminar?.nome}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
