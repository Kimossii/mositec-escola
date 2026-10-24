<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import PerfilForm from '../Components/PerfilForm.vue';
import PermissoesPorMenu from '../Components/PermissoesPorMenu.vue';

defineProps({
    perfis: {
        type: Array,
        required: true,
    },
    acoes: {
        type: Array,
        required: true,
    },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const perfilEmEdicao = ref(null);

function abrirCriacao() {
    perfilEmEdicao.value = null;
    modalAberto.value = true;
}

function abrirEdicao(perfil) {
    perfilEmEdicao.value = perfil;
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

// estado: 1 = ativo, 0 = inativo (mesma convenção do módulo Usuario).
function alternarEstado(perfil) {
    router.patch(`/permissoes/perfis/${perfil.id}/estado`, {}, {
        preserveScroll: true,
        onSuccess: () => toast.success(perfil.estado === 1 ? 'Perfil desativado.' : 'Perfil ativado.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
    });
}

const perfilParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(perfil) {
    perfilParaEliminar.value = perfil;
}

function cancelarEliminacao() {
    perfilParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/permissoes/perfis/${perfilParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            perfilParaEliminar.value = null;
        },
    });
}

</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Perfis</h1>
            <button v-if="can('autorizacao.criar')" class="btn btn-primary" @click="abrirCriacao">Novo perfil</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-100px">Tipo</th>
                            <th class="min-w-100px">Utilizadores</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-150px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <template v-for="perfil in perfis" :key="perfil.id">
                            <tr>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-icon btn-sm btn-light-primary me-2"
                                        data-bs-toggle="collapse"
                                        :data-bs-target="`#perfil-permissoes-${perfil.id}`"
                                        aria-expanded="false"
                                    >
                                        <i class="ki-duotone ki-down fs-3"></i>
                                    </button>
                                    {{ perfil.descricao }}
                                </td>
                                <td>
                                    <span class="badge" :class="perfil.sistema ? 'badge-light-primary' : 'badge-light-info'">
                                        {{ perfil.sistema ? 'Sistema' : 'Personalizado' }}
                                    </span>
                                </td>
                                <td>{{ perfil.utilizadores_count }}</td>
                                <td>
                                    <span class="badge" :class="perfil.estado === 1 ? 'badge-light-success' : 'badge-light-danger'">
                                        {{ perfil.estado === 1 ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button
                                        v-if="can('autorizacao.editar')"
                                        type="button"
                                        class="btn btn-light-primary btn-sm me-2"
                                        @click="alternarEstado(perfil)"
                                    >
                                        <AcaoIcone :acao="perfil.estado === 1 ? 'desativar' : 'ativar'" class="me-1" />
                                        {{ perfil.estado === 1 ? 'Desativar' : 'Ativar' }}
                                    </button>
                                    <a v-if="can('autorizacao.ver')" :href="`/permissoes/perfis/${perfil.id}/permissoes`" class="btn btn-light-success btn-sm me-2">
                                        <AcaoIcone acao="permissoes" class="me-1" />
                                        Permissões
                                    </a>
                                    <button v-if="can('autorizacao.editar')" class="btn btn-light-primary btn-sm me-2" @click="abrirEdicao(perfil)">
                                        <AcaoIcone acao="editar" class="me-1" />
                                        Editar
                                    </button>
                                    <button
                                        v-if="!perfil.sistema && can('autorizacao.eliminar')"
                                        class="btn btn-light-danger btn-sm"
                                        @click="pedirEliminacao(perfil)"
                                    >
                                        <AcaoIcone acao="eliminar" class="me-1" />
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5" class="p-0 border-0">
                                    <div class="collapse" :id="`perfil-permissoes-${perfil.id}`">
                                        <div
                                            class="bg-light rounded-3 mx-6 mb-6 overflow-hidden"
                                            style="border-left: 4px solid var(--bs-success);"
                                        >
                                            <div class="p-4">
                                                <PermissoesPorMenu :permissoes="perfil.permissoes" :acoes="acoes" />
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="modalAberto" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="fecharModal">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content p-6">
                    <h3 class="mb-5">{{ perfilEmEdicao ? 'Editar perfil' : 'Novo perfil' }}</h3>
                    <PerfilForm :perfil="perfilEmEdicao" @fechar="fecharModal" />
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="!!perfilParaEliminar"
            titulo="Eliminar perfil"
            :mensagem="`Tem certeza que deseja eliminar o perfil ${perfilParaEliminar?.descricao}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
