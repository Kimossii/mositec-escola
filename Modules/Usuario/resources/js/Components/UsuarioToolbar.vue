<script setup>
import { reactive, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import UsuarioExportModal from './UsuarioExportModal.vue';
import UsuarioCreateModal from './UsuarioCreateModal.vue';

const props = defineProps({
    /** filtros aplicados no servidor: { pesquisa, estado } */
    filtros: { type: Object, default: () => ({}) },
    /** componente de form a usar no modal "Add User" — repassado até UsuarioCreateModal */
    formComponent: {
        type: [Object, Function],
        default: undefined,
    },
    utilizadorEmEdicao: { type: Object, default: null },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
    permissoesPorPerfil: { type: Object, default: () => ({}) },
    criarPermissao: { type: String, default: 'usuario.criar' },
});

const opcoesEstado = [
    { value: '', label: 'Todos os estados' },
    { value: '1', label: 'Ativo' },
    { value: '0', label: 'Inativo' },
];

const consulta = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: props.filtros.estado ?? '',
});

const pagina = usePage();
let debounceId = null;

// Pesquisa e filtro correm no servidor (a tabela só tem a página actual).
// Mudar qualquer filtro volta à página 1: `page` não vai no pedido.
watch(consulta, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(new URL(pagina.url, window.location.origin).pathname, valor, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});
</script>

<template>
    <!--begin::Card title-->
    <div class="card-title">
        <!--begin::Search-->
        <div class="d-flex align-items-center position-relative my-1">
            <i class="ki-duotone ki-magnifier fs-3 position-absolute ms-5"><span class="path1"></span><span class="path2"></span></i>
            <input v-model="consulta.pesquisa" type="text" class="form-control form-control-solid w-250px w-md-300px ps-13" placeholder="Nome, email ou matrícula" />
        </div>
        <!--end::Search-->
    </div>
    <!--begin::Card title-->

    <!--begin::Card toolbar-->
    <div class="card-toolbar">
        <!--begin::Toolbar-->
        <div class="d-flex justify-content-end">
            <!--begin::Filter-->
            <div class="w-175px me-3">
                <SelectSolid v-model="consulta.estado" :options="opcoesEstado" />
            </div>
            <!--end::Filter-->

            <!--begin::Export-->
            <button type="button" class="btn btn-light-primary me-3" data-bs-toggle="modal" data-bs-target="#kt_modal_export_users">
                <i class="ki-duotone ki-exit-up fs-2"><span class="path1"></span><span class="path2"></span></i>
                Exportar
            </button>
            <!--end::Export-->

            <!--begin::Add user-->
            <button v-if="can(criarPermissao)" type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#kt_modal_add_user">
                <i class="ki-duotone ki-plus fs-2"></i>
                Registar utilizador
            </button>
            <!--end::Add user-->
        </div>
        <!--end::Toolbar-->

        <UsuarioExportModal />
        <UsuarioCreateModal
            :form-component="formComponent"
            :utilizador="utilizadorEmEdicao"
            :perfis="perfis"
            :modulos="modulos"
            :acoes="acoes"
            :permissoes-por-perfil="permissoesPorPerfil"
        />
    </div>
    <!--end::Card toolbar-->
</template>
