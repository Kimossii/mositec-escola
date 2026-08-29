<script setup>
import { onMounted, ref } from 'vue';
import UsuarioTable from './UsuarioTable.vue';
import UsuarioToolbar from './UsuarioToolbar.vue';
import UsuarioVisualizarDrawer from './UsuarioVisualizarDrawer.vue';

/**
 * Casca de página compartilhada pelas 6 listagens de usuários (Todos os
 * Utilizadores, Alunos, Professores, Funcionários, Administradores,
 * Encarregados). Cada `Pages/*.vue` só define título, ícone, cor e o
 * perfil filtrado — a estrutura (breadcrumb, card, toolbar, tabela,
 * footer) fica só aqui, pra evitar 6 cópias do mesmo HTML do Metronic.
 */
defineProps({
    title: {
        type: String,
        required: true,
    },
    /** classe do ícone Keenicons sem o prefixo "ki-duotone", ex.: "ki-teacher" */
    icon: {
        type: String,
        required: true,
    },
    /** cor Bootstrap/Metronic: primary, success, warning, danger, etc. */
    accent: {
        type: String,
        default: 'primary',
    },
    usuarios: {
        type: Array,
        required: true,
    },
    /** componente de form a usar no modal "Add User" desta lista — ver pasta Forms/ de cada lista */
    formComponent: {
        type: [Object, Function],
        default: undefined,
    },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
    permissoesPorPerfil: { type: Object, default: () => ({}) },
});

const utilizadorEmEdicao = ref(null);
const utilizadorVisualizando = ref(null);

function abrirEdicao(utilizador) {
    utilizadorEmEdicao.value = utilizador;
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('kt_modal_add_user')).show();
}

function abrirVisualizacao(utilizador) {
    utilizadorVisualizando.value = utilizador;
    window.KTDrawer?.createInstances();
    window.KTDrawer?.getInstance(document.getElementById('kt_usuario_visualizar_drawer'))?.show();
}

onMounted(() => {
    document.getElementById('kt_modal_add_user')?.addEventListener('hidden.bs.modal', () => {
        utilizadorEmEdicao.value = null;
    });
});
</script>

<template>
    <!--begin::Main-->
    <div class="app-main flex-column flex-row-fluid" id="kt_app_main">
        <div class="d-flex flex-column flex-column-fluid">
            <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
                <!--begin::Toolbar container-->
                <div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
                    <!--begin::Page title-->
                    <div class="page-title d-flex align-items-center flex-wrap me-3">
                        <span class="symbol symbol-40px me-3" :class="`bg-light-${accent}`" style="border-radius: 0.475rem;">
                            <span class="symbol-label">
                                <i class="ki-duotone fs-2" :class="[icon, `text-${accent}`]">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                    <span class="path3"></span>
                                    <span class="path4"></span>
                                    <span class="path5"></span>
                                </i>
                            </span>
                        </span>
                        <div class="d-flex flex-column justify-content-center">
                            <h1 class="page-heading d-flex text-dark fw-bold fs-3 flex-column justify-content-center my-0">
                                {{ title }}
                            </h1>
                            <!--begin::Breadcrumb-->
                            <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
                                <li class="breadcrumb-item text-muted">
                                    <a href="/" class="text-muted text-hover-primary">Início</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <span class="bullet bg-gray-400 w-5px h-2px"></span>
                                </li>
                                <li class="breadcrumb-item text-muted">Gestão de Utilizadores</li>
                                <li class="breadcrumb-item">
                                    <span class="bullet bg-gray-400 w-5px h-2px"></span>
                                </li>
                                <li class="breadcrumb-item text-muted">{{ title }}</li>
                            </ul>
                            <!--end::Breadcrumb-->
                        </div>
                    </div>
                    <!--end::Page title-->
                </div>
                <!--end::Toolbar container-->
            </div>
            <!--end::Toolbar-->

            <!--begin::Content-->
            <div id="kt_app_content" class="app-content flex-column-fluid">
                <!--begin::Content container-->
                <div id="kt_app_content_container" class="app-container container-xxl">
                    <!--begin::Card-->
                    <div class="card">
                        <!--begin::Card header-->
                        <div class="card-header border-0 pt-6">
                            <UsuarioToolbar
                                :form-component="formComponent"
                                :utilizador-em-edicao="utilizadorEmEdicao"
                                :perfis="perfis"
                                :modulos="modulos"
                                :acoes="acoes"
                                :permissoes-por-perfil="permissoesPorPerfil"
                            />
                        </div>
                        <!--end::Card header-->

                        <!--begin::Card body-->
                        <div class="card-body py-4">
                            <UsuarioTable :usuarios="usuarios" @editar="abrirEdicao" @visualizar="abrirVisualizacao" />
                        </div>
                        <!--end::Card body-->
                    </div>
                    <!--end::Card-->
                </div>
                <!--end::Content container-->
            </div>
            <!--end::Content-->
        </div>

        <!--begin::Footer-->
        <div id="kt_app_footer" class="app-footer">
            <div class="app-container container-fluid d-flex flex-column flex-md-row flex-center flex-md-stack py-3">
                <div class="text-dark order-2 order-md-1">
                    <span class="text-muted fw-semibold me-1">2023&copy;</span>
                    <a href="https://keenthemes.com/" target="_blank" class="text-gray-800 text-hover-primary">Keenthemes</a>
                </div>
                <ul class="menu menu-gray-600 menu-hover-primary fw-semibold order-1">
                    <li class="menu-item"><a href="https://keenthemes.com/" target="_blank" class="menu-link px-2">About</a></li>
                    <li class="menu-item"><a href="https://devs.keenthemes.com/" target="_blank" class="menu-link px-2">Support</a></li>
                    <li class="menu-item"><a href="https://1.envato.market/EA4JP" target="_blank" class="menu-link px-2">Purchase</a></li>
                </ul>
            </div>
        </div>
        <!--end::Footer-->

        <UsuarioVisualizarDrawer :utilizador="utilizadorVisualizando" />
    </div>
    <!--end::Main-->
</template>

<style>
.image-input-placeholder {
    background-image: url('/themes/metronic/assets/media/svg/files/blank-image.svg');
}

[data-bs-theme="dark"] .image-input-placeholder {
    background-image: url('/themes/metronic/assets/media/svg/files/blank-image-dark.svg');
}
</style>
