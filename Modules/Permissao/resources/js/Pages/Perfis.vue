<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import PerfilForm from '../Components/PerfilForm.vue';

defineProps({
    perfis: {
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

function eliminar(perfil) {
    router.delete(`/permissoes/perfis/${perfil.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o perfil.'),
    });
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
                        <tr v-for="perfil in perfis" :key="perfil.id">
                            <td>{{ perfil.descricao }}</td>
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
                                    @click="eliminar(perfil)"
                                >
                                    <AcaoIcone acao="eliminar" class="me-1" />
                                    Eliminar
                                </button>
                            </td>
                        </tr>
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
    </div>
</template>
