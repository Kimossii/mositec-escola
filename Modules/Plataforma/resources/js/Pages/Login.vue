<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import PasswordInput from '@/Components/Shared/PasswordInput.vue';
import PainelDeMarca from '../../../../Autenticacao/resources/js/Components/PainelDeMarca.vue';
import { useCsrfPlataforma } from '../Composables/useCsrfPlataforma';

// Esta página não usa o LayoutPlataforma: configura ela própria o cabeçalho X-CSRF-TOKEN.
useCsrfPlataforma();

const form = reactive({
    email: '',
    password: '',
});
const processing = ref(false);
const errors = ref({});

function submit() {
    processing.value = true;
    errors.value = {};

    router.post('/plataforma/login', form, {
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
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
                                Acesso reservado à equipa MosiTec
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
                                :class="{ 'is-invalid': errors.email }"
                            />
                            <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email }}</div>
                        </div>

                        <div class="fv-row mb-10">
                            <label class="required fw-semibold fs-6 mb-2">Senha</label>
                            <PasswordInput
                                v-model="form.password"
                                name="password"
                                autocomplete="current-password"
                                placeholder="Senha"
                                :invalid="!!errors.password"
                            />
                            <div class="text-danger fs-7 mt-1" v-if="errors.password">{{ errors.password }}</div>
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

        <PainelDeMarca titulo="Plataforma MosiTec" subtitulo="Gestão central das escolas." />
    </div>
</template>
