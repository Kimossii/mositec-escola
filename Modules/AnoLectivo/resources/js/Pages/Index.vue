<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import AnoLectivoStatusBadge from '../Components/AnoLectivoStatusBadge.vue';
import AnoLectivoFormModal from '../Components/AnoLectivoFormModal.vue';
import { ESTADO_ANO_LECTIVO } from '../Models/AnoLectivo';

defineProps({
    anoLectivos: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

// --- Criar Ano Lectivo ---
const modalAberto = ref(false);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function criar(payload) {
    processing.value = true;
    errors.value = {};
    router.post('/ano-lectivos', payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Ano Lectivo criado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível criar o Ano Lectivo.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

// --- Alterar estado (Activar / Encerrar) ---
const anoLectivoParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirActivacao(anoLectivo) {
    anoLectivoParaAlterarEstado.value = anoLectivo;
    novoEstado.value = ESTADO_ANO_LECTIVO.ATIVO;
}

function pedirEncerramento(anoLectivo) {
    anoLectivoParaAlterarEstado.value = anoLectivo;
    novoEstado.value = ESTADO_ANO_LECTIVO.ENCERRADO;
}

function cancelarAlteracaoEstado() {
    anoLectivoParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/ano-lectivos/${anoLectivoParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success(
            novoEstado.value === ESTADO_ANO_LECTIVO.ATIVO ? 'Ano Lectivo activado.' : 'Ano Lectivo encerrado.',
        ),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível alterar o estado.'),
        onFinish: () => {
            alterandoEstado.value = false;
            anoLectivoParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}

// --- Eliminar ---
const anoLectivoParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(anoLectivo) {
    anoLectivoParaEliminar.value = anoLectivo;
}

function cancelarEliminacao() {
    anoLectivoParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/ano-lectivos/${anoLectivoParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Ano Lectivo eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o Ano Lectivo.'),
        onFinish: () => {
            eliminando.value = false;
            anoLectivoParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Anos Lectivos</h1>
            <button v-if="can('ano-lectivo.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Ano Lectivo</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-200px">Período</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="anoLectivos.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum Ano Lectivo criado.</td>
                        </tr>
                        <tr v-for="anoLectivo in anoLectivos" :key="anoLectivo.id">
                            <td>{{ anoLectivo.nome }}</td>
                            <td>{{ anoLectivo.data_inicio }} — {{ anoLectivo.data_fim }}</td>
                            <td>
                                <AnoLectivoStatusBadge :estado="anoLectivo.estado" :estado-descricao="anoLectivo.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-175px py-4" data-kt-menu="true">
                                    <div class="menu-item px-3">
                                        <a :href="`/ano-lectivos/${anoLectivo.id}`" class="menu-link px-3">
                                            <AcaoIcone acao="visualizar" class="me-2" />
                                            Ver
                                        </a>
                                    </div>
                                    <div v-if="anoLectivo.estado === 0 && can('ano-lectivo.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirActivacao(anoLectivo)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Activar
                                        </a>
                                    </div>
                                    <div v-if="anoLectivo.estado === 1 && can('ano-lectivo.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirEncerramento(anoLectivo)">
                                            <AcaoIcone acao="encerrar" class="me-2" />
                                            Encerrar
                                        </a>
                                    </div>
                                    <div v-if="can('ano-lectivo.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(anoLectivo)">
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

        <AnoLectivoFormModal
            :show="modalAberto"
            :processing="processing"
            :errors="errors"
            @submit="criar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!anoLectivoParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="novoEstado === 1
                ? `Activar o Ano Lectivo ${anoLectivoParaAlterarEstado?.nome}?`
                : `Encerrar o Ano Lectivo ${anoLectivoParaAlterarEstado?.nome}?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!anoLectivoParaEliminar"
            titulo="Eliminar Ano Lectivo"
            :mensagem="`Tem certeza que deseja eliminar o Ano Lectivo ${anoLectivoParaEliminar?.nome}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
