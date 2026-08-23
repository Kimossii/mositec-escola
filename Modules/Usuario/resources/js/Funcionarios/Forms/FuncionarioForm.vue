<script setup>
import UsuarioFormFields from '../../Components/UsuarioFormFields.vue';
import { TIPO_PESSOA } from '../../Models/Usuario';

// Form de criação/edição específico da lista Funcionários. Ver o mesmo
// comentário em Alunos/Forms/AlunoForm.vue — espaço aqui pra campos futuros
// só de funcionário (cargo, setor...).
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#">
        <!--begin::Scroll-->
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
            <UsuarioFormFields />

            <input type="hidden" name="user_tipo_pessoa" :value="TIPO_PESSOA.FUNCIONARIO" />

            <!--begin::Input group-->
            <div class="mb-7">
                <label class="fw-semibold fs-6 mb-2 d-block">Tipo</label>
                <span class="badge badge-light-warning fs-6">Funcionário</span>
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
