<script setup>
import UsuarioFormFields from '../../Components/UsuarioFormFields.vue';

// Form de criação/edição específico da lista Administradores. Diferente dos
// outros 3 (Aluno/Professor/Funcionário), "Administrador" não é um
// tipo_pessoa no banco — é um papel que ainda vai depender da integração com
// roles/permissoes do módulo Permissao (ver Models/Usuario.js#isAdministrador).
// Por isso não tem input hidden de tipo_pessoa aqui: falta decidir com o
// backend como esse papel será atribuído no submit.
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#">
        <!--begin::Scroll-->
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
            <UsuarioFormFields />

            <!--begin::Input group-->
            <div class="mb-7">
                <label class="fw-semibold fs-6 mb-2 d-block">Papel</label>
                <span class="badge badge-light-danger fs-6">Administrador</span>
                <div class="form-text mt-2">
                    A atribuição de administrador ainda depende da integração com Permissões (roles).
                </div>
            </div>
            <!--end::Input group-->
        </div>
        <!--end::Scroll-->

        <!--begin::Actions-->
        <div class="text-center pt-15">
            <button type="reset" class="btn btn-light me-3" data-kt-users-modal-action="cancel">
                Discard
            </button>

            <button type="submit" class="btn btn-primary" data-kt-users-modal-action="submit">
                <span class="indicator-label">
                    Submit
                </span>
                <span class="indicator-progress">
                    Please wait... <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                </span>
            </button>
        </div>
        <!--end::Actions-->
    </form>
</template>
