<script setup>
import { onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePageScripts } from '@/composables/usePageScripts';
import { useUsuarios } from '../Composables/useUsuarios';
import UsuarioTable from '../Components/UsuarioTable.vue';
import UsuarioToolbar from '../Components/UsuarioToolbar.vue';

// TODO: trocar por `defineProps({ usuarios: Array })` quando
// UsuarioController@index passar a enviar os usuários reais via Inertia
// (ver Composables/useUsuarios.js e Models/Usuario.js).
const { usuarios } = useUsuarios();

const { loadAll } = usePageScripts([
    '/themes/metronic/assets/plugins/custom/datatables/datatables.bundle.js',
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/table.js',
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/export-users.js',
    '/themes/metronic/assets/js/components/custom/apps/user-management/users/list/add.js',
    '/themes/metronic/assets/js/widgets.bundle.js',
    '/themes/metronic/assets/js/components/custom/widgets.js',
    '/themes/metronic/assets/js/components/custom/apps/chat/chat.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/upgrade-plan.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/create-app.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/users-search.js',
]);

onMounted(() => {
    loadAll();
});
defineOptions({ layout: AppLayout })
</script>

<template>
  <!--begin::Main-->
  <div class="app-main flex-column flex-row-fluid" id="kt_app_main">
    <div class="d-flex flex-column flex-column-fluid">
<div id="kt_app_toolbar" class="app-toolbar  py-3 py-lg-6 "

         >

            <!--begin::Toolbar container-->
        <div id="kt_app_toolbar_container" class="app-container  container-xxl d-flex flex-stack ">



<!--begin::Page title-->
<div  class="page-title d-flex flex-column justify-content-center flex-wrap me-3 ">
    <!--begin::Title-->
    <h1 class="page-heading d-flex text-dark fw-bold fs-3 flex-column justify-content-center my-0">
        Users List
            </h1>
    <!--end::Title-->


        <!--begin::Breadcrumb-->
        <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
                            <!--begin::Item-->
                                    <li class="breadcrumb-item text-muted">
                                                    <a href="/" class="text-muted text-hover-primary">
                                Home                            </a>
                                            </li>
                                <!--end::Item-->
                                    <!--begin::Item-->
                    <li class="breadcrumb-item">
                        <span class="bullet bg-gray-400 w-5px h-2px"></span>
                    </li>
                    <!--end::Item-->

                            <!--begin::Item-->
                                    <li class="breadcrumb-item text-muted">
                                                    User Management                                            </li>
                                <!--end::Item-->
                                    <!--begin::Item-->
                    <li class="breadcrumb-item">
                        <span class="bullet bg-gray-400 w-5px h-2px"></span>
                    </li>
                    <!--end::Item-->

                            <!--begin::Item-->
                                    <li class="breadcrumb-item text-muted">
                                                    Users                                            </li>
                                <!--end::Item-->

                    </ul>
        <!--end::Breadcrumb-->
    </div>
<!--end::Page title-->
<!--begin::Actions-->
<div class="d-flex align-items-center gap-2 gap-lg-3">
            <!--begin::Filter menu-->
        <div class="m-0">
            <!--begin::Menu toggle-->
            <a href="#" class="btn btn-sm btn-flex bg-body btn-color-gray-700 btn-active-color-primary fw-bold" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                <i class="ki-duotone ki-filter fs-6 text-muted me-1"><span class="path1"></span><span class="path2"></span></i>
                Filter
            </a>
            <!--end::Menu toggle-->



<!--begin::Menu 1-->
<div class="menu menu-sub menu-sub-dropdown w-250px w-md-300px" data-kt-menu="true" id="kt_menu_647624f195bb3">
    <!--begin::Header-->
    <div class="px-7 py-5">
        <div class="fs-5 text-dark fw-bold">Filter Options</div>
    </div>
    <!--end::Header-->

    <!--begin::Menu separator-->
    <div class="separator border-gray-200"></div>
    <!--end::Menu separator-->

    <!--begin::Form-->
    <div class="px-7 py-5">
        <!--begin::Input group-->
        <div class="mb-10">
            <!--begin::Label-->
            <label class="form-label fw-semibold">Status:</label>
            <!--end::Label-->

            <!--begin::Input-->
            <div>
                <select class="form-select form-select-solid" data-kt-select2="true" data-placeholder="Select option" data-dropdown-parent="#kt_menu_647624f195bb3" data-allow-clear="true">
                    <option></option>
                    <option value="1">Approved</option>
                    <option value="2">Pending</option>
                    <option value="2">In Process</option>
                    <option value="2">Rejected</option>
                </select>
            </div>
            <!--end::Input-->
        </div>
        <!--end::Input group-->

        <!--begin::Input group-->
        <div class="mb-10">
            <!--begin::Label-->
            <label class="form-label fw-semibold">Member Type:</label>
            <!--end::Label-->

            <!--begin::Options-->
            <div class="d-flex">
                <!--begin::Options-->
                <label class="form-check form-check-sm form-check-custom form-check-solid me-5">
                    <input class="form-check-input" type="checkbox" value="1"/>
                    <span class="form-check-label">
                        Author
                    </span>
                </label>
                <!--end::Options-->

                <!--begin::Options-->
                <label class="form-check form-check-sm form-check-custom form-check-solid">
                    <input class="form-check-input" type="checkbox" value="2" checked="checked"/>
                    <span class="form-check-label">
                        Customer
                    </span>
                </label>
                <!--end::Options-->
            </div>
            <!--end::Options-->
        </div>
        <!--end::Input group-->

        <!--begin::Input group-->
        <div class="mb-10">
            <!--begin::Label-->
            <label class="form-label fw-semibold">Notifications:</label>
            <!--end::Label-->

            <!--begin::Switch-->
            <div class="form-check form-switch form-switch-sm form-check-custom form-check-solid">
                <input class="form-check-input" type="checkbox" value="" name="notifications" checked />
                <label class="form-check-label">
                    Enabled
                </label>
            </div>
            <!--end::Switch-->
        </div>
        <!--end::Input group-->

        <!--begin::Actions-->
        <div class="d-flex justify-content-end">
            <button type="reset" class="btn btn-sm btn-light btn-active-light-primary me-2" data-kt-menu-dismiss="true">Reset</button>

            <button type="submit" class="btn btn-sm btn-primary" data-kt-menu-dismiss="true">Apply</button>
        </div>
        <!--end::Actions-->
    </div>
    <!--end::Form-->
</div>
<!--end::Menu 1-->        </div>
        <!--end::Filter menu-->


    <!--begin::Secondary button-->
        <!--end::Secondary button-->

    <!--begin::Primary button-->
            <a href="#" class="btn btn-sm fw-bold btn-primary"  data-bs-toggle="modal" data-bs-target="#kt_modal_create_app">
            Create        </a>
        <!--end::Primary button-->
</div>
<!--end::Actions-->
        </div>
        <!--end::Toolbar container-->
    </div>
<!--end::Toolbar-->

<!--begin::Content-->
<div id="kt_app_content" class="app-content  flex-column-fluid " >


        <!--begin::Content container-->
        <div id="kt_app_content_container" class="app-container  container-xxl ">
            <!--begin::Card-->
            <div class="card">
                <!--begin::Card header-->
                <div class="card-header border-0 pt-6">
                    <UsuarioToolbar />
                </div>
                <!--end::Card header-->

                <!--begin::Card body-->
                <div class="card-body py-4">
                    <UsuarioTable :usuarios="usuarios" />
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
