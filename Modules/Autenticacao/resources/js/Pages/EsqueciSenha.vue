<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import PainelDeMarca from '../Components/PainelDeMarca.vue';

defineProps({
    // true depois de pedir: a mensagem é SEMPRE a mesma, exista ou não conta (nunca revelar quem tem conta).
    enviado: { type: Boolean, default: false },
});

const form = reactive({ email: '' });
const processing = ref(false);

function submit() {
    processing.value = true;
    router.post('/esqueci-a-senha', form, { onFinish: () => { processing.value = false; } });
}
</script>

<template>
    <div class="d-flex flex-column flex-lg-row flex-column-fluid" style="min-height: 100vh;">
        <div class="d-flex flex-column flex-lg-row-fluid w-lg-50 p-10 order-2 order-lg-1">
            <div class="d-flex flex-center flex-column flex-lg-row-fluid">
                <div class="w-lg-500px p-10">
                    <template v-if="!enviado">
                        <form class="form w-100" novalidate @submit.prevent="submit">
                            <div class="text-center mb-8">
                                <span class="symbol symbol-65px mb-5">
                                    <span class="symbol-label bg-light-primary">
                                        <i class="ki-duotone ki-key fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                                    </span>
                                </span>
                                <h1 class="text-dark fw-bolder mb-3">Esqueci a senha</h1>
                                <div class="text-gray-500 fw-semibold fs-6">
                                    Indique o email da sua conta e enviamos-lhe uma ligação para escolher uma nova senha.
                                </div>
                            </div>

                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Email</label>
                                <input v-model="form.email" type="email" class="form-control form-control-solid" placeholder="O email da sua conta" autocomplete="email" required />
                            </div>

                            <div class="d-grid mb-6">
                                <button type="submit" class="btn btn-primary" :disabled="processing || !form.email">
                                    <span v-if="!processing">Enviar ligação</span>
                                    <span v-else>A enviar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                                </button>
                            </div>

                            <div class="d-flex align-items-start bg-body-secondary rounded p-4 mb-6 text-gray-700 fs-7">
                                <i class="ki-duotone ki-information-5 fs-3 text-info me-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                                Esta opção é para encarregados de educação com o email confirmado. Se é outro tipo de utilizador, peça a redefinição da senha ao administrador da escola.
                            </div>

                            <div class="text-center"><a href="/login" class="link-primary fw-semibold fs-6">Voltar ao login</a></div>
                        </form>
                    </template>

                    <div v-else class="text-center">
                        <span class="symbol symbol-65px mb-5">
                            <span class="symbol-label bg-light-success">
                                <i class="ki-duotone ki-sms fs-2x text-success"><span class="path1"></span><span class="path2"></span></i>
                            </span>
                        </span>
                        <h1 class="text-dark fw-bolder mb-3">Verifique o seu email</h1>
                        <div class="text-gray-500 fw-semibold fs-6 mb-8">
                            Se existir uma conta elegível com esse email, enviámos-lhe uma ligação para escolher uma nova senha. A ligação é válida durante pouco tempo e só pode ser usada uma vez.
                        </div>
                        <a href="/login" class="btn btn-primary">Voltar ao login</a>
                    </div>
                </div>
            </div>
        </div>

        <PainelDeMarca />
    </div>
</template>
