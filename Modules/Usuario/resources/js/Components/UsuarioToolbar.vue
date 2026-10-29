<script setup>
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { computed, reactive, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import MenuExportar from '@/Components/Shared/MenuExportar.vue';
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

// A exportação leva o mesmo perfil (pelo endereço da lista) e filtros que o ecrã mostra.
const paramsExportacao = computed(() => ({
    perfil: new URL(pagina.url, window.location.origin).pathname.split('/').filter(Boolean)[1] ?? '',
    pesquisa: consulta.pesquisa,
    estado: consulta.estado,
}));
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
            <AcaoIcone acao="pesquisar" tamanho="fs-3" herda-cor class="position-absolute ms-5" />
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
            <MenuExportar v-if="can('usuario.exportar')" url="/usuarios/exportar" :params="paramsExportacao" class="me-3" />
            <!--end::Export-->

            <!--begin::Add user-->
            <button v-if="can(criarPermissao)" type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#kt_modal_add_user">
                <AcaoIcone acao="adicionar" tamanho="fs-2" herda-cor />
                Registar utilizador
            </button>
            <!--end::Add user-->
        </div>
        <!--end::Toolbar-->

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
