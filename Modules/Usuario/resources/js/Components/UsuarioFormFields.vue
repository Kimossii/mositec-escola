<script setup>
// Campos comuns a qualquer pessoa (Aluno/Professor/Funcionário/Administrador/
// Encarregado): nome, email OU matrícula (conforme tipoLogin), senha. Usado
// pelos Forms de cada lista pra não repetir esse bloco — o que muda entre
// eles é só a secção de tipo/papel, que fica no próprio Form.
import PasswordInput from '@/Components/Shared/PasswordInput.vue';

const name = defineModel('name', { default: '' });
const email = defineModel('email', { default: '' });
const password = defineModel('password', { default: '' });
const passwordConfirmation = defineModel('passwordConfirmation', { default: '' });

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
    edicao: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
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
        <label class="fw-semibold fs-6 mb-2" :class="{ required: !edicao }">Senha</label>
        <!--end::Label-->

        <!--begin::Input-->
        <PasswordInput v-model="password" name="user_password" :placeholder="edicao ? 'Deixe em branco para manter a senha atual' : 'Mínimo 6 caracteres'" />
        <!--end::Input-->

        <div class="form-text" v-if="edicao">Preencha apenas se quiser definir uma nova senha.</div>

        <div class="text-danger fs-7 mt-1" v-if="errors.password">{{ errors.password[0] }}</div>
    </div>
    <!--end::Input group-->

    <!--begin::Input group-->
    <div class="fv-row mb-7" v-if="!edicao || password">
        <!--begin::Label-->
        <label class="fw-semibold fs-6 mb-2" :class="{ required: !edicao }">Confirmar senha</label>
        <!--end::Label-->

        <!--begin::Input-->
        <PasswordInput v-model="passwordConfirmation" name="user_password_confirmation" placeholder="Repita a senha" />
        <!--end::Input-->

        <div class="text-danger fs-7 mt-1" v-if="errors.password_confirmation">{{ errors.password_confirmation[0] }}</div>
    </div>
    <!--end::Input group-->
</template>
