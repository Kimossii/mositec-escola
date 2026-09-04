<script setup>
import UsuarioExportModal from './UsuarioExportModal.vue';
import UsuarioCreateModal from './UsuarioCreateModal.vue';

defineProps({
    /** componente de form a usar no modal "Add User" — repassado até UsuarioCreateModal */
    formComponent: {
        type: [Object, Function],
        default: undefined,
    },
    utilizadorEmEdicao: { type: Object, default: null },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
});
</script>

<template>
    <!--begin::Card title-->
    <div class="card-title">
        <!--begin::Search-->
        <div class="d-flex align-items-center position-relative my-1">
            <i class="ki-duotone ki-magnifier fs-3 position-absolute ms-5"><span class="path1"></span><span class="path2"></span></i>
            <input type="text" data-kt-user-table-filter="search" class="form-control form-control-solid w-250px ps-13" placeholder="Search user" />
        </div>
        <!--end::Search-->
    </div>
    <!--begin::Card title-->

    <!--begin::Card toolbar-->
    <div class="card-toolbar">
        <!--begin::Toolbar-->
        <div class="d-flex justify-content-end" data-kt-user-table-toolbar="base">
            <!--begin::Filter-->
            <button type="button" class="btn btn-light-primary me-3" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                <i class="ki-duotone ki-filter fs-2"><span class="path1"></span><span class="path2"></span></i>
                Filter
            </button>
            <!--begin::Menu 1-->
            <div class="menu menu-sub menu-sub-dropdown w-300px w-md-325px" data-kt-menu="true">
                <!--begin::Header-->
                <div class="px-7 py-5">
                    <div class="fs-5 text-dark fw-bold">Filter Options</div>
                </div>
                <!--end::Header-->

                <!--begin::Separator-->
                <div class="separator border-gray-200"></div>
                <!--end::Separator-->

                <!--begin::Content-->
                <div class="px-7 py-5" data-kt-user-table-filter="form">
                    <!--begin::Input group-->
                    <div class="mb-10">
                        <label class="form-label fs-6 fw-semibold">Matrícula:</label>
                        <input type="text" class="form-control form-control-solid fw-bold" data-kt-user-table-filter="matricula" placeholder="Digite a matrícula" />
                    </div>
                    <!--end::Input group-->

                    <!--begin::Input group-->
                    <div class="mb-10">
                        <label class="form-label fs-6 fw-semibold">Estado:</label>
                        <select class="form-select form-select-solid fw-bold" data-kt-select2="true" data-placeholder="Select option" data-allow-clear="true" data-kt-user-table-filter="estado" data-hide-search="true">
                            <option></option>
                            <option value="Ativo">Ativo</option>
                            <option value="Inativo">Inativo</option>
                        </select>
                    </div>
                    <!--end::Input group-->

                    <!--begin::Actions-->
                    <div class="d-flex justify-content-end">
                        <button type="reset" class="btn btn-light btn-active-light-primary fw-semibold me-2 px-6" data-kt-menu-dismiss="true" data-kt-user-table-filter="reset">Reset</button>
                        <button type="submit" class="btn btn-primary fw-semibold px-6" data-kt-menu-dismiss="true" data-kt-user-table-filter="filter">Apply</button>
                    </div>
                    <!--end::Actions-->
                </div>
                <!--end::Content-->
            </div>
            <!--end::Menu 1-->
            <!--end::Filter-->

            <!--begin::Export-->
            <button type="button" class="btn btn-light-primary me-3" data-bs-toggle="modal" data-bs-target="#kt_modal_export_users">
                <i class="ki-duotone ki-exit-up fs-2"><span class="path1"></span><span class="path2"></span></i>
                Exportar
            </button>
            <!--end::Export-->

            <!--begin::Add user-->
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#kt_modal_add_user">
                <i class="ki-duotone ki-plus fs-2"></i>
                Registar utilizador
            </button>
            <!--end::Add user-->
        </div>
        <!--end::Toolbar-->

        <!--begin::Group actions-->
        <div class="d-flex justify-content-end align-items-center d-none" data-kt-user-table-toolbar="selected">
            <div class="fw-bold me-5">
                <span class="me-2" data-kt-user-table-select="selected_count"></span> Selected
            </div>

            <button type="button" class="btn btn-danger" data-kt-user-table-select="delete_selected">
                Excluir selecionados
            </button>
        </div>
        <!--end::Group actions-->

        <UsuarioExportModal />
        <UsuarioCreateModal
            :form-component="formComponent"
            :utilizador="utilizadorEmEdicao"
            :perfis="perfis"
            :modulos="modulos"
            :acoes="acoes"
        />
    </div>
    <!--end::Card toolbar-->
</template>
