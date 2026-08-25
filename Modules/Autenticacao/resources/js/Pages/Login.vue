<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const form = reactive({
    email: '',
    password: '',
    remember: false,
});
const processing = ref(false);
const errors = ref({});

function submit() {
    processing.value = true;
    errors.value = {};

    router.post('/login', form, {
        onError: (erros) => {
            errors.value = erros;
            processing.value = false;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="d-flex flex-column flex-lg-row flex-column-fluid" style="min-height: 100vh;">
        <div class="d-flex flex-column flex-lg-row-fluid w-lg-50 p-10 order-2 order-lg-1">
            <div class="d-flex flex-center flex-column flex-lg-row-fluid">
                <div class="w-lg-500px p-10">
                    <form class="form w-100" novalidate @submit.prevent="submit">
                        <div class="text-center mb-11">
                            <img
                                alt="Logo"
                                src="/themes/metronic/assets/media/logos/default-dark.svg"
                                class="h-40px mb-5"
                            />
                            <h1 class="text-dark fw-bolder mb-3">Entrar</h1>
                            <div class="text-gray-500 fw-semibold fs-6">
                                Acesse o sistema de gestão acadêmica
                            </div>
                        </div>

                        <div class="fv-row mb-8">
                            <label class="required fw-semibold fs-6 mb-2">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                name="email"
                                autocomplete="username"
                                class="form-control form-control-solid"
                                placeholder="exemplo@dominio.com"
                            />
                            <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email }}</div>
                        </div>

                        <div class="fv-row mb-3">
                            <label class="required fw-semibold fs-6 mb-2">Senha</label>
                            <input
                                v-model="form.password"
                                type="password"
                                name="password"
                                autocomplete="current-password"
                                class="form-control form-control-solid"
                                placeholder="Senha"
                            />
                            <div class="text-danger fs-7 mt-1" v-if="errors.password">{{ errors.password }}</div>
                        </div>

                        <div class="d-flex flex-stack flex-wrap gap-3 fs-base fw-semibold mb-8">
                            <label class="form-check form-check-custom form-check-solid">
                                <input v-model="form.remember" class="form-check-input" type="checkbox" name="remember" />
                                <span class="form-check-label text-gray-500 fs-6">Lembrar-me</span>
                            </label>
                        </div>

                        <div class="d-grid mb-10">
                            <button type="submit" class="btn btn-primary" :disabled="processing">
                                <span v-if="!processing">Entrar</span>
                                <span v-else>
                                    A entrar...
                                    <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div
            class="d-flex flex-lg-row-fluid w-lg-50 order-1 order-lg-2"
            style="background-color: #0F172A;"
        >
            <div class="d-flex flex-column flex-center py-15 px-10 w-100">
                <img
                    alt="Logo"
                    src="/themes/metronic/assets/media/logos/default-small.svg"
                    class="h-30px mb-10"
                />
                <h1 class="d-none d-lg-block text-white fs-2qx fw-bolder text-center mb-7">
                    Gestão Acadêmica MosiTec
                </h1>
                <div class="d-none d-lg-block text-white fs-base text-center opacity-75">
                    Matrículas, turmas, notas e frequência num só lugar.
                </div>
            </div>
        </div>
    </div>
</template>
