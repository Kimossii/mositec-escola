<script setup>
import { ref } from 'vue';

// Campos comuns a qualquer pessoa (Aluno/Professor/Funcionário/Administrador/
// Encarregado): avatar, nome, email OU matrícula (conforme tipoLogin), senha.
// Usado pelos Forms de cada lista pra não repetir esse bloco — o que muda
// entre eles é só a secção de tipo/papel, que fica no próprio Form.
const name = defineModel('name', { default: '' });
const email = defineModel('email', { default: '' });
const password = defineModel('password', { default: '' });

defineProps({
    tipoLogin: {
        type: String,
        required: true,
        validator: (value) => ['email', 'matricula'].includes(value),
    },
    errors: {
        type: Object,
        default: () => ({}),
    },
});

// Pré-visualização local do avatar escolhido — ainda não há endpoint no
// backend a guardar isto (depende de dados_pessoas, que ainda não existe).
const avatarPreview = ref(null);

function onAvatarChange(event) {
    const file = event.target.files?.[0];
    if (file) {
        avatarPreview.value = URL.createObjectURL(file);
    }
}

function removerAvatar(event) {
    avatarPreview.value = null;
    const input = event.target.closest('.image-input')?.querySelector('input[type="file"]');
    if (input) input.value = '';
}
</script>

<template>
    <!--begin::Input group-->
    <div class="fv-row mb-7">
        <!--begin::Label-->
        <label class="d-block fw-semibold fs-6 mb-5">Avatar</label>
        <!--end::Label-->

        <!--begin::Image input-->
        <div class="image-input image-input-outline image-input-placeholder" data-kt-image-input="true">
            <!--begin::Preview-->
            <div class="image-input-wrapper w-125px h-125px" :style="avatarPreview ? `background-image: url(${avatarPreview})` : ''"></div>
            <!--end::Preview-->

            <!--begin::Label-->
            <label class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-bs-toggle="tooltip" title="Escolher avatar">
                <i class="ki-duotone ki-pencil fs-7"><span class="path1"></span><span class="path2"></span></i>
                <input type="file" name="avatar" accept=".png, .jpg, .jpeg" class="d-none" @change="onAvatarChange" />
            </label>
            <!--end::Label-->

            <!--begin::Remove-->
            <span v-if="avatarPreview" class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-bs-toggle="tooltip" title="Remover avatar" @click="removerAvatar">
                <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
            </span>
            <!--end::Remove-->
        </div>
        <!--end::Image input-->

        <!--begin::Hint-->
        <div class="form-text">Tipos de ficheiro permitidos: png, jpg, jpeg.</div>
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
    <div class="fv-row mb-7" v-if="tipoLogin === 'email'">
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
    <div class="fv-row mb-7" v-else>
        <!--begin::Label-->
        <label class="fw-semibold fs-6 mb-2">Matrícula</label>
        <!--end::Label-->

        <div class="form-text">Será gerada automaticamente ao guardar.</div>
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
</template>
