<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import CampoSenha from '../Components/CampoSenha.vue';
import ForcaDaSenha from '../Components/ForcaDaSenha.vue';
import PainelDeMarca from '../Components/PainelDeMarca.vue';

// Troca OBRIGATÓRIA (senha temporária): a troca voluntária vive em Definições → Segurança.
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const processing = ref(false);
const errors = ref({});

function submit() {
    processing.value = true;
    errors.value = {};

    router.put('/alterar-senha', form, {
        onError: (erros) => {
            errors.value = erros;
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
                        <div class="text-center mb-8">
                            <span class="symbol symbol-65px mb-5">
                                <span class="symbol-label bg-light-success">
                                    <i class="ki-duotone ki-shield-tick fs-2x text-success"><span class="path1"></span><span class="path2"></span></i>
                                </span>
                            </span>
                            <h1 class="text-dark fw-bolder mb-3">Defina a sua senha pessoal</h1>
                            <div class="text-gray-500 fw-semibold fs-6">
                                A senha atual é temporária. Escolha uma senha só sua para proteger a sua conta.
                            </div>
                        </div>

                        <CampoSenha v-model="form.current_password" rotulo="Senha temporária" placeholder="A senha que recebeu" autocomplete="current-password" :erro="errors.current_password" />
                        <CampoSenha v-model="form.password" rotulo="Nova senha" placeholder="Escolha uma senha forte" autocomplete="new-password" :erro="errors.password" />
                        <ForcaDaSenha :senha="form.password" :confirmacao="form.password_confirmation" />
                        <CampoSenha v-model="form.password_confirmation" rotulo="Confirmar nova senha" placeholder="Repita a nova senha" autocomplete="new-password" :erro="errors.password_confirmation" />

                        <div class="d-grid mb-5">
                            <button type="submit" class="btn btn-primary" :disabled="processing">
                                <span v-if="!processing">Guardar e continuar</span>
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
