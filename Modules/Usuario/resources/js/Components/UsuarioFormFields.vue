<script setup>
// Campos comuns a qualquer pessoa (Aluno/Professor/Funcionário/Administrador):
// avatar, nome, email, senha, matrícula. Usado pelos Forms de cada lista pra
// não repetir esse bloco 5x — o que muda entre eles é só a seção de tipo/papel,
// que fica no próprio Form de cada lista.
//
// name/email/password têm v-model porque são os únicos campos que a rota
// POST /api/v1/usuarios/store aceita hoje (ver Composables/useUsuarioCriar.js).
// Avatar e matrícula continuam sem binding — pertencem a dados_pessoas, que
// ainda não tem endpoint próprio.
const name = defineModel('name', { default: '' });
const email = defineModel('email', { default: '' });
const password = defineModel('password', { default: '' });

defineProps({
    errors: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
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
        <input v-model="name" type="text" name="user_name" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="Nome completo" />
        <!--end::Input-->

        <div class="text-danger fs-7 mt-1" v-if="errors.name">{{ errors.name[0] }}</div>
    </div>
    <!--end::Input group-->

    <!--begin::Input group-->
    <div class="fv-row mb-7">
        <!--begin::Label-->
        <label class="required fw-semibold fs-6 mb-2">Email</label>
        <!--end::Label-->

        <!--begin::Input-->
        <input v-model="email" type="email" name="user_email" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="exemplo@dominio.com" />
        <!--end::Input-->

        <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email[0] }}</div>
    </div>
    <!--end::Input group-->

    <!--begin::Input group-->
    <div class="fv-row mb-7">
        <!--begin::Label-->
        <label class="required fw-semibold fs-6 mb-2">Senha</label>
        <!--end::Label-->

        <!--begin::Input-->
        <input v-model="password" type="password" name="user_password" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="Mínimo 6 caracteres" autocomplete="new-password" />
        <!--end::Input-->

        <div class="text-danger fs-7 mt-1" v-if="errors.password">{{ errors.password[0] }}</div>
    </div>
    <!--end::Input group-->

    <!--begin::Input group-->
    <div class="fv-row mb-7">
        <!--begin::Label-->
        <label class="fw-semibold fs-6 mb-2">Matrícula</label>
        <!--end::Label-->

        <!--begin::Input-->
        <input type="text" name="user_matricula" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="Matrícula" />
        <!--end::Input-->
    </div>
    <!--end::Input group-->
</template>
