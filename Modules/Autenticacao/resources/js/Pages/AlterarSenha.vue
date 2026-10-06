<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import PainelDeMarca from '../Components/PainelDeMarca.vue';

const form = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const processing = ref(false);
const errors = ref({});
const mostrarActual = ref(false);
const mostrarNova = ref(false);
const mostrarConfirmacao = ref(false);

function submit() {
    processing.value = true;
    errors.value = {};

    router.put('/alterar-senha', form, {
        onError: (erros) => {
            errors.value = erros;
            processing.value = false;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function terminarSessao() {
    router.post('/logout');
}
</script>

<template>
    <div class="d-flex flex-column flex-lg-row flex-column-fluid" style="min-height: 100vh;">
        <div class="d-flex flex-column flex-lg-row-fluid w-lg-50 p-10 order-2 order-lg-1">
            <div class="d-flex flex-center flex-column flex-lg-row-fluid">
                <div class="w-lg-500px p-10">
                    <form class="form w-100" novalidate @submit.prevent="submit">
                        <div class="text-center mb-11">
                            <h1 class="text-dark fw-bolder mb-3">Alterar senha</h1>
                            <div class="text-gray-500 fw-semibold fs-6">
                                Defina uma nova senha para continuar
                            </div>
                        </div>

                        <div class="fv-row mb-8">
                            <label class="required fw-semibold fs-6 mb-2">Senha actual</label>
                            <div class="position-relative">
                                <input
                                    v-model="form.current_password"
                                    :type="mostrarActual ? 'text' : 'password'"
                                    name="current_password"
                                    autocomplete="current-password"
                                    class="form-control form-control-solid"
                                    style="padding-right: 3rem;"
                                    placeholder="Senha actual"
                                />
                                <button
                                    type="button"
                                    class="btn btn-icon btn-sm position-absolute top-50 translate-middle-y end-0 me-2 text-gray-500"
                                    tabindex="-1"
                                    @click="mostrarActual = !mostrarActual"
                                >
                                    <svg v-if="mostrarActual" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                                        <line x1="1" y1="1" x2="23" y2="23" />
                                    </svg>
                                    <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                </button>
                            </div>
                            <div class="text-danger fs-7 mt-1" v-if="errors.current_password">{{ errors.current_password }}</div>
                        </div>

                        <div class="fv-row mb-8">
                            <label class="required fw-semibold fs-6 mb-2">Nova senha</label>
                            <div class="position-relative">
                                <input
                                    v-model="form.password"
                                    :type="mostrarNova ? 'text' : 'password'"
                                    name="password"
                                    autocomplete="new-password"
                                    class="form-control form-control-solid"
                                    style="padding-right: 3rem;"
                                    placeholder="Nova senha"
                                />
                                <button
                                    type="button"
                                    class="btn btn-icon btn-sm position-absolute top-50 translate-middle-y end-0 me-2 text-gray-500"
                                    tabindex="-1"
                                    @click="mostrarNova = !mostrarNova"
                                >
                                    <svg v-if="mostrarNova" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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

                        <div class="fv-row mb-8">
                            <label class="required fw-semibold fs-6 mb-2">Confirmar nova senha</label>
                            <div class="position-relative">
                                <input
                                    v-model="form.password_confirmation"
                                    :type="mostrarConfirmacao ? 'text' : 'password'"
                                    name="password_confirmation"
                                    autocomplete="new-password"
                                    class="form-control form-control-solid"
                                    style="padding-right: 3rem;"
                                    placeholder="Confirmar nova senha"
                                />
                                <button
                                    type="button"
                                    class="btn btn-icon btn-sm position-absolute top-50 translate-middle-y end-0 me-2 text-gray-500"
                                    tabindex="-1"
                                    @click="mostrarConfirmacao = !mostrarConfirmacao"
                                >
                                    <svg v-if="mostrarConfirmacao" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                                        <line x1="1" y1="1" x2="23" y2="23" />
                                    </svg>
                                    <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                </button>
                            </div>
                            <div class="text-danger fs-7 mt-1" v-if="errors.password_confirmation">{{ errors.password_confirmation }}</div>
                        </div>

                        <div class="d-grid mb-5">
                            <button type="submit" class="btn btn-primary" :disabled="processing">
                                <span v-if="!processing">Guardar nova senha</span>
                                <span v-else>
                                    A guardar...
                                    <Loader size="0.3px" class="align-middle ms-2" />
                                </span>
                            </button>
                        </div>

                        <div class="text-center">
                            <a href="#" class="link-primary fw-semibold fs-6" @click.prevent="terminarSessao">Terminar sessão</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <PainelDeMarca />
    </div>
</template>
