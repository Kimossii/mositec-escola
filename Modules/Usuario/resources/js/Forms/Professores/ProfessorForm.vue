<script setup>
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../../Components/UsuarioFormFields.vue';
import { TIPO_PESSOA } from '../../Models/Usuario';

// Form de criação/edição específico da lista Professores. Ver o mesmo
// comentário em AlunoForm.vue — espaço aqui pra campos futuros
// só de professor (disciplinas, turmas que leciona...).
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#">
        <!--begin::Scroll-->
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
            <UsuarioFormFields />

            <input type="hidden" name="user_tipo_pessoa" :value="TIPO_PESSOA.PROFESSOR" />

            <!--begin::Input group-->
            <div class="mb-7">
                <label class="fw-semibold fs-6 mb-2 d-block">Tipo</label>
                <span class="badge badge-light-success fs-6">Professor</span>
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
                    Please wait... <Loader size="0.3px" class="align-middle ms-2" />
                </span>
            </button>
        </div>
        <!--end::Actions-->
    </form>
</template>
