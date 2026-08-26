<script setup>
// Campos comuns a qualquer pessoa (Aluno/Professor/Funcionário/Administrador/
// Encarregado): nome, email OU matrícula (conforme tipoLogin), senha. Usado
// pelos Forms de cada lista pra não repetir esse bloco — o que muda entre
// eles é só a secção de tipo/papel, que fica no próprio Form.
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
        <label class="required fw-semibold fs-6 mb-2">Senha</label>
        <!--end::Label-->

        <!--begin::Input-->
        <input v-model="password" type="password" name="user_password" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="Mínimo 6 caracteres" autocomplete="new-password" />
        <!--end::Input-->

        <div class="text-danger fs-7 mt-1" v-if="errors.password">{{ errors.password[0] }}</div>
    </div>
    <!--end::Input group-->
</template>
