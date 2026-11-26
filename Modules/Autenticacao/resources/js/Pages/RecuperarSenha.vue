<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import CampoSenha from '../Components/CampoSenha.vue';
import ForcaDaSenha from '../Components/ForcaDaSenha.vue';
import PainelDeMarca from '../Components/PainelDeMarca.vue';

defineProps({
    // formulario | invalido | concluido
    estado: { type: String, required: true },
});

const form = reactive({ password: '', password_confirmation: '' });
const processing = ref(false);
const errors = ref({});

// A ligação assinada do email (com `expires` e `signature`) é a própria autorização: submete-se para o MESMO endereço.
function submit() {
    processing.value = true;
    errors.value = {};

    router.post(window.location.href, form, {
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => { processing.value = false; },
    });
}
</script>

<template>
    <div class="d-flex flex-column flex-lg-row flex-column-fluid" style="min-height: 100vh;">
        <div class="d-flex flex-column flex-lg-row-fluid w-lg-50 p-10 order-2 order-lg-1">
            <div class="d-flex flex-center flex-column flex-lg-row-fluid">
                <div class="w-lg-500px p-10">
                    <form v-if="estado === 'formulario'" class="form w-100" novalidate @submit.prevent="submit">
                        <div class="text-center mb-8">
                            <span class="symbol symbol-65px mb-5">
                                <span class="symbol-label bg-light-success">
                                    <i class="ki-duotone ki-shield-tick fs-2x text-success"><span class="path1"></span><span class="path2"></span></i>
                                </span>
                            </span>
                            <h1 class="text-dark fw-bolder mb-3">Escolha a sua nova senha</h1>
                            <div class="text-gray-500 fw-semibold fs-6">Escolha uma senha forte, só sua. Depois disto terminamos as sessões abertas.</div>
                        </div>

                        <CampoSenha v-model="form.password" rotulo="Nova senha" placeholder="Escolha uma senha forte" autocomplete="new-password" :erro="errors.password" />
                        <ForcaDaSenha :senha="form.password" :confirmacao="form.password_confirmation" />
                        <CampoSenha v-model="form.password_confirmation" rotulo="Confirmar nova senha" placeholder="Repita a nova senha" autocomplete="new-password" :erro="errors.password_confirmation" />

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" :disabled="processing">
                                <span v-if="!processing">Guardar nova senha</span>
                                <span v-else>A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            </button>
                        </div>
                    </form>

                    <div v-else-if="estado === 'concluido'" class="text-center">
                        <span class="symbol symbol-65px mb-5">
                            <span class="symbol-label bg-light-success">
                                <i class="ki-duotone ki-check-circle fs-2x text-success"><span class="path1"></span><span class="path2"></span></i>
                            </span>
                        </span>
                        <h1 class="text-dark fw-bolder mb-3">Senha alterada</h1>
                        <div class="text-gray-500 fw-semibold fs-6 mb-8">A sua nova senha já está activa. Entre com ela.</div>
                        <a href="/login" class="btn btn-primary">Ir para o login</a>
                    </div>

                    <div v-else class="text-center">
                        <span class="symbol symbol-65px mb-5">
                            <span class="symbol-label bg-light-danger">
                                <i class="ki-duotone ki-cross-circle fs-2x text-danger"><span class="path1"></span><span class="path2"></span></i>
                            </span>
                        </span>
                        <h1 class="text-dark fw-bolder mb-3">Ligação inválida</h1>
                        <div class="text-gray-500 fw-semibold fs-6 mb-8">Esta ligação não é válida, já foi usada ou expirou. Peça uma nova ligação, ou fale com o administrador da escola.</div>
                        <a href="/login" class="btn btn-primary">Voltar ao login</a>
                    </div>
                </div>
            </div>
        </div>

        <PainelDeMarca />
    </div>
</template>
