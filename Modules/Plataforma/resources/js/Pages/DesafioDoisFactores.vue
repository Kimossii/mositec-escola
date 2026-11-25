<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import PainelDeMarca from '../../../../Autenticacao/resources/js/Components/PainelDeMarca.vue';
import { useCsrfPlataforma } from '../Composables/useCsrfPlataforma';

// Sem LayoutPlataforma (como o Login): configura ela própria o cabeçalho X-CSRF-TOKEN.
useCsrfPlataforma();

// Passo 2 do login: código da aplicação ou, em alternativa, um código de recuperação.
const usarRecuperacao = ref(false);
const form = reactive({ code: '', recovery_code: '' });
const processing = ref(false);
const errors = ref({});

function alternar() {
    usarRecuperacao.value = !usarRecuperacao.value;
    form.code = '';
    form.recovery_code = '';
    errors.value = {};
}

function submit() {
    processing.value = true;
    errors.value = {};

    router.post('/plataforma/desafio', usarRecuperacao.value ? { recovery_code: form.recovery_code } : { code: form.code }, {
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
                            <h1 class="text-dark fw-bolder mb-3">Verificação em dois factores</h1>
                            <div class="text-gray-500 fw-semibold fs-6">
                                {{ usarRecuperacao ? 'Introduza um dos seus códigos de recuperação' : 'Introduza o código de 6 dígitos da sua aplicação' }}
                            </div>
                        </div>

                        <div class="fv-row mb-8">
                            <template v-if="!usarRecuperacao">
                                <label class="required fw-semibold fs-6 mb-2">Código</label>
                                <input v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus class="form-control form-control-solid" placeholder="000000" />
                                <div v-if="errors.code" class="text-danger fs-7 mt-1">{{ errors.code }}</div>
                            </template>
                            <template v-else>
                                <label class="required fw-semibold fs-6 mb-2">Código de recuperação</label>
                                <input v-model="form.recovery_code" type="text" autocomplete="off" autofocus class="form-control form-control-solid" />
                                <div v-if="errors.recovery_code" class="text-danger fs-7 mt-1">{{ errors.recovery_code }}</div>
                            </template>
                        </div>

                        <div class="d-grid mb-5">
                            <button type="submit" class="btn btn-primary" :disabled="processing || !(usarRecuperacao ? form.recovery_code : form.code)">
                                <span v-if="!processing">Verificar</span>
                                <span v-else>A verificar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            </button>
                        </div>
                        <div class="text-center fs-6">
                            <a href="#" class="link-primary fw-semibold" @click.prevent="alternar">
                                {{ usarRecuperacao ? 'Usar o código da aplicação' : 'Usar um código de recuperação' }}
                            </a>
                        </div>
                        <div class="text-center fs-7 mt-4"><a href="/plataforma/login" class="text-gray-500">Voltar ao início de sessão</a></div>
                    </form>
                </div>
            </div>
        </div>

        <PainelDeMarca titulo="Plataforma MosiTec" subtitulo="Gestão central das escolas." />
    </div>
</template>
