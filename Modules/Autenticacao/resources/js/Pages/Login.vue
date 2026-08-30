<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';

const form = reactive({
    login: '',
    password: '',
    remember: false,
});
const processing = ref(false);
const errors = ref({});
const showPassword = ref(false);

function submit() {
    processing.value = true;
    errors.value = {};

    router.post('/login', form, {
        onError: (erros) => {
            errors.value = erros;
            processing.value = false;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível iniciar sessão.');
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
                            <h1 class="text-dark fw-bolder mb-3">Iniciar sessão</h1>
                            <div class="text-gray-500 fw-semibold fs-6">
                                Introduza os seus dados de acesso
                            </div>
                        </div>

                        <div class="fv-row mb-8">
                            <label class="required fw-semibold fs-6 mb-2">Email ou Matrícula</label>
                            <input
                                v-model="form.login"
                                type="text"
                                name="login"
                                autocomplete="username"
                                class="form-control form-control-solid"
                                placeholder="exemplo@dominio.com ou 2026-0001"
                            />
                            <div class="text-danger fs-7 mt-1" v-if="errors.login">{{ errors.login }}</div>
                        </div>

                        <div class="fv-row mb-3">
                            <label class="required fw-semibold fs-6 mb-2">Senha</label>
                            <div class="position-relative">
                                <input
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    name="password"
                                    autocomplete="current-password"
                                    class="form-control form-control-solid"
                                    style="padding-right: 3rem;"
                                    placeholder="Senha"
                                />
                                <button
                                    type="button"
                                    class="btn btn-icon btn-sm position-absolute top-50 translate-middle-y end-0 me-2 text-gray-500"
                                    tabindex="-1"
                                    @click="showPassword = !showPassword"
                                >
                                    <svg v-if="showPassword" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                                        <line x1="1" y1="1" x2="23" y2="23" />
                                    </svg>
                                    <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                </button>
                            </div>
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
                                    <Loader size="0.3px" class="align-middle ms-2" />
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
