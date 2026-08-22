<script setup>
// Campos de criação/edição de usuário — hoje só usados dentro do modal
// "Add User" (Components/UsuarioCreateModal.vue), mas isolados aqui para
// serem reaproveitados por uma futura página Create/Edit dedicada sem
// duplicar markup. Ainda não tem submit real: falta a rota/controller de
// criação (UsuarioController@store já existe, só não está ligado ao form).
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#">
        <!--begin::Scroll-->
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <!--begin::Label-->
                <label class="d-block fw-semibold fs-6 mb-5">Avatar</label>
                <!--end::Label-->

                <!--begin::Image input-->
                <div class="image-input image-input-outline image-input-placeholder" data-kt-image-input="true">
                    <!--begin::Preview existing avatar-->
                    <div class="image-input-wrapper w-125px h-125px" style="background-image: url(/themes/metronic/assets/media/avatars/300-6.jpg);"></div>
                    <!--end::Preview existing avatar-->

                    <!--begin::Label-->
                    <label class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="change" data-bs-toggle="tooltip" title="Change avatar">
                        <i class="ki-duotone ki-pencil fs-7"><span class="path1"></span><span class="path2"></span></i>
                        <!--begin::Inputs-->
                        <input type="file" name="avatar" accept=".png, .jpg, .jpeg" />
                        <input type="hidden" name="avatar_remove" />
                        <!--end::Inputs-->
                    </label>
                    <!--end::Label-->

                    <!--begin::Cancel-->
                    <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="cancel" data-bs-toggle="tooltip" title="Cancel avatar">
                        <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
                    </span>
                    <!--end::Cancel-->

                    <!--begin::Remove-->
                    <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="remove" data-bs-toggle="tooltip" title="Remove avatar">
                        <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
                    </span>
                    <!--end::Remove-->
                </div>
                <!--end::Image input-->

                <!--begin::Hint-->
                <div class="form-text">Allowed file types: png, jpg, jpeg.</div>
                <!--end::Hint-->
            </div>
            <!--end::Input group-->

            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <!--begin::Label-->
                <label class="required fw-semibold fs-6 mb-2">Nome completo</label>
                <!--end::Label-->

                <!--begin::Input-->
                <input type="text" name="user_name" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="Nome completo" />
                <!--end::Input-->
            </div>
            <!--end::Input group-->

            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <!--begin::Label-->
                <label class="required fw-semibold fs-6 mb-2">Email</label>
                <!--end::Label-->

                <!--begin::Input-->
                <input type="email" name="user_email" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="exemplo@dominio.com" />
                <!--end::Input-->
            </div>
            <!--end::Input group-->

            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <!--begin::Label-->
                <label class="required fw-semibold fs-6 mb-2">Matrícula</label>
                <!--end::Label-->

                <!--begin::Input-->
                <input type="text" name="user_matricula" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="Matrícula" />
                <!--end::Input-->
            </div>
            <!--end::Input group-->

            <!--begin::Input group-->
            <div class="mb-7">
                <!--begin::Label-->
                <label class="required fw-semibold fs-6 mb-5">Tipo de pessoa</label>
                <!--end::Label-->

                <!--begin::Options-->
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="0" id="kt_modal_add_user_tipo_pessoa_0" checked="checked" />
                        <label class="form-check-label" for="kt_modal_add_user_tipo_pessoa_0">Aluno</label>
                    </div>
                </div>
                <div class="separator separator-dashed my-5"></div>
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="1" id="kt_modal_add_user_tipo_pessoa_1" />
                        <label class="form-check-label" for="kt_modal_add_user_tipo_pessoa_1">Professor</label>
                    </div>
                </div>
                <div class="separator separator-dashed my-5"></div>
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="2" id="kt_modal_add_user_tipo_pessoa_2" />
                        <label class="form-check-label" for="kt_modal_add_user_tipo_pessoa_2">Funcionário</label>
                    </div>
                </div>
                <div class="separator separator-dashed my-5"></div>
                <div class="d-flex fv-row">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input me-3" name="user_tipo_pessoa" type="radio" value="3" id="kt_modal_add_user_tipo_pessoa_3" />
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
                    Please wait... <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                </span>
            </button>
        </div>
        <!--end::Actions-->
    </form>
</template>
