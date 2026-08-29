<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import PerfilForm from '../Components/PerfilForm.vue';

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
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o perfil.'),
        onFinish: () => {
            eliminando.value = false;
            perfilParaEliminar.value = null;
        },
    });
}

// Agrupa a lista plana [{ modulo, acao }] por módulo, pra render como matriz
// Módulo × Ação (uma coluna por ação, igual à página de edição de permissões).
function agruparPorModulo(permissoes) {
    const grupos = new Map();
    for (const { modulo, acao } of permissoes) {
        if (!grupos.has(modulo)) grupos.set(modulo, []);
        grupos.get(modulo).push(acao);
    }
    return Array.from(grupos, ([modulo, acoes]) => ({ modulo, acoes }));
}

function temAcao(grupo, acao) {
    return grupo.acoes.includes(acao);
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Perfis</h1>
            <button class="btn btn-primary" @click="abrirCriacao">Novo perfil</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-100px">Tipo</th>
                            <th class="min-w-100px">Utilizadores</th>
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
                                <td class="text-end">
                                    <a :href="`/permissoes/perfis/${perfil.id}/permissoes`" class="btn btn-light-success btn-sm me-2">
                                        <AcaoIcone acao="permissoes" class="me-1" />
                                        Permissões
                                    </a>
                                    <button class="btn btn-light-primary btn-sm me-2" @click="abrirEdicao(perfil)">
                                        <AcaoIcone acao="editar" class="me-1" />
                                        Editar
                                    </button>
                                    <button
                                        v-if="!perfil.sistema"
                                        class="btn btn-light-danger btn-sm"
                                        @click="pedirEliminacao(perfil)"
                                    >
                                        <AcaoIcone acao="eliminar" class="me-1" />
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4" class="p-0 border-0">
                                    <div class="collapse" :id="`perfil-permissoes-${perfil.id}`">
                                        <div
                                            class="bg-light rounded-3 mx-6 mb-6 overflow-hidden"
                                            style="border-left: 4px solid var(--bs-success);"
                                        >
                                            <div v-if="!perfil.permissoes.length" class="p-6 text-muted fs-6">
                                                Nenhuma permissão atribuída a este perfil.
                                            </div>

                                            <div v-else class="table-responsive">
                                                <table class="table table-permissoes align-middle mb-0">
                                                    <thead>
                                                        <tr class="text-muted fw-bold fs-6 text-uppercase">
                                                            <th class="ps-6 min-w-200px">Módulo</th>
                                                            <th v-for="acao in acoes" :key="acao" class="text-center">
                                                                {{ acao }}
                                                            </th>
                                                        </tr>
                                                    </thead>

                                                    <tbody class="text-gray-800 fw-semibold">
                                                        <tr v-for="grupo in agruparPorModulo(perfil.permissoes)" :key="grupo.modulo">
                                                            <td class="ps-6 fw-bold fs-6">{{ grupo.modulo }}</td>

                                                            <td v-for="acao in acoes" :key="acao" class="text-center">
                                                                <span v-if="temAcao(grupo, acao)" class="badge badge-circle badge-success">
                                                                    <i class="ki-duotone ki-check fs-3 text-white"></i>
                                                                </span>
                                                                <span v-else class="badge badge-circle badge-danger">
                                                                    <i class="ki-duotone ki-cross fs-3 text-white">
                                                                        <span class="path1"></span>
                                                                        <span class="path2"></span>
                                                                    </i>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
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

<style scoped>
/* A borda padrão do Bootstrap (--bs-border-color: #F4F4F4) some em cima do
   fundo bg-light (--bs-light: #F9F9F9) — praticamente a mesma cor. Aqui as
   linhas (entre módulos) e a coluna (Módulo | Ações) precisam de contraste
   de verdade pra ficar com cara de tabela mesmo, não só texto lado a lado.

   O !important é necessário: o Bootstrap tem uma regra
   ".table:not(.table-bordered) tbody tr:last-child th/td { border-bottom: 0
   !important; }" pra tirar a borda da última linha visível de QUALQUER
   tabela. Como é seletor descendente (não filho direto), ela "vaza" pra
   dentro desta tabela aninhada sempre que o perfil expandido for o último
   da lista — sem !important a borda do cabeçalho some nesse caso. E só
   !important não bastava: a regra do Bootstrap também é mais específica
   (3 classes/pseudo-classes) que ".table-permissoes[data-v] th" (2) — por
   isso aqui usamos as 3 classes que o <table> já tem (table, table-permissoes,
   align-middle) pra ganhar o empate de especificidade também. */
.table.table-permissoes.align-middle > tbody > tr:not(:last-child) > td {
    border-bottom: 1px dashed rgba(0, 0, 0, 0.14) !important;
}

.table.table-permissoes.align-middle > thead > tr > th {
    border-bottom: 1px solid rgba(0, 0, 0, 0.14) !important;
}

.table-permissoes > thead > tr > th:not(:first-child),
.table-permissoes > tbody > tr > td:not(:first-child) {
    border-left: 1px solid rgba(0, 0, 0, 0.14);
}
</style>
