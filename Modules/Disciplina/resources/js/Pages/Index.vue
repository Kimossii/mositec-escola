<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import DisciplinaFormModal from '../Components/DisciplinaFormModal.vue';
import { ESTADO } from '../Models/Estado';

defineProps({
    disciplinas: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const disciplinaEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    disciplinaEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(disciplina) {
    disciplinaEmEdicao.value = disciplina;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = disciplinaEmEdicao.value ? `/disciplinas/${disciplinaEmEdicao.value.id}` : '/disciplinas';
    const metodo = disciplinaEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(disciplinaEmEdicao.value ? 'Disciplina atualizada com sucesso.' : 'Disciplina criada com sucesso.');
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

const disciplinaParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(disciplina, estado) {
    disciplinaParaAlterarEstado.value = disciplina;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    disciplinaParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/disciplinas/${disciplinaParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado da disciplina atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            disciplinaParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Disciplinas</h1>
            <button v-if="can('disciplina.criar')" class="btn btn-primary" @click="abrirCriacao">Nova Disciplina</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="disciplinas.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhuma disciplina criada.</td>
                        </tr>
                        <tr v-for="disciplina in disciplinas" :key="disciplina.id">
                            <td>
                                <a :href="`/disciplinas/${disciplina.id}`" class="text-gray-800 text-hover-primary">{{ disciplina.codigo }}</a>
                            </td>
                            <td>{{ disciplina.nome }}</td>
                            <td>
                                <EstadoBadge :estado="disciplina.estado" :estado-descricao="disciplina.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('disciplina.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(disciplina)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar
                                        </a>
                                    </div>
                                    <div v-if="can('disciplina.editar') && disciplina.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(disciplina, ESTADO.ATIVO)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('disciplina.editar') && disciplina.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(disciplina, ESTADO.INATIVO)">
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
        </div>

        <DisciplinaFormModal
            :show="modalAberto"
            :disciplina="disciplinaEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!disciplinaParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado da disciplina ${disciplinaParaAlterarEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />
    </div>
</template>
