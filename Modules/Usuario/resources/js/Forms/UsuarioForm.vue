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
            <div class="fv-row mb-7">
                <!--begin::Label-->
                <label class="required fw-semibold fs-6 mb-2" for="kt_modal_add_user_tipo_pessoa">Tipo de pessoa</label>
                <!--end::Label-->

                <!--begin::Select-->
                <select name="user_tipo_pessoa" id="kt_modal_add_user_tipo_pessoa" data-control="select2"
                    data-placeholder="Selecione o tipo" data-hide-search="true"
                    class="form-select form-select-solid">
                    <option value="0" selected>Aluno</option>
                    <option value="1">Professor</option>
                    <option value="2">Funcionário</option>
                    <option value="3">Outro</option>
                </select>
                <!--end::Select-->
            </div>
            <!--end::Input group-->
        </div>
        <!--end::Scroll-->

        <!--begin::Actions-->
        <div class="text-center pt-15">
            <button type="reset" class="btn btn-danger me-3" data-kt-users-modal-action="cancel">
                Cancelar
            </button>

            <button type="button" class="btn btn-castanho me-3" data-kt-users-modal-action="draft">
                Guardar rascunho
            </button>

            <button type="submit" class="btn btn-primary" data-kt-users-modal-action="submit">
                <span class="indicator-label">
                    Guardar
                </span>
                <span class="indicator-progress">
                    Aguarde...
                    <Loader size="0.3px" class="align-middle ms-2" />
                </span>
            </button>
        </div>
        <!--end::Actions-->
    </form>
</template>
