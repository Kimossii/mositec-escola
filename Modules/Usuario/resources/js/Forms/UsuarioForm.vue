<script setup>
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../Components/UsuarioFormFields.vue';

// Form genérico usado só pela lista "Todos os Utilizadores" (Pages/Index.vue),
// onde o tipo de pessoa não é implícito e precisa ser escolhido manualmente.
// As listas filtradas (Alunos, Professores, Funcionarios, Administradores)
// têm cada uma o seu próprio Form na sua subpasta dentro de Forms/, que já
// sabe o tipo e não precisa desse rádio — só reaproveitam Components/UsuarioFormFields.
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#">
        <!--begin::Scroll-->
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true"
            data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto"
            data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll"
            data-kt-scroll-offset="300px">
            <UsuarioFormFields />

            <!--begin::Input group-->
            <div class="mb-7">
                <!--begin::Label-->
                <label class="required fw-semibold fs-6 mb-5">Tipo de pessoa</label>
                <!--end::Label-->

                <!--begin::Options-->
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="0"
                            id="kt_modal_add_user_tipo_pessoa_0" checked="checked" />
                        <label class="form-check-label" for="kt_modal_add_user_tipo_pessoa_0">Aluno</label>
                    </div>
                </div>
                <div class="separator separator-dashed my-5"></div>
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="1"
                            id="kt_modal_add_user_tipo_pessoa_1" />
                        <label class="form-check-label" for="kt_modal_add_user_tipo_pessoa_1">Professor</label>
                    </div>
                </div>
                <div class="separator separator-dashed my-5"></div>
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="2"
                            id="kt_modal_add_user_tipo_pessoa_2" />
                        <label class="form-check-label" for="kt_modal_add_user_tipo_pessoa_2">Funcionário</label>
                    </div>
                </div>
                <div class="separator separator-dashed my-5"></div>
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="3"
                            id="kt_modal_add_user_tipo_pessoa_3" />
                        <label class="form-check-label" for="kt_modal_add_user_tipo_pessoa_3">Outro</label>
                    </div>
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
                    Please wait...
                    <Loader size="0.3px" class="align-middle ms-2" />
                </span>
            </button>
        </div>
        <!--end::Actions-->
    </form>
</template>
