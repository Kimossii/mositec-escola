<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../Components/UsuarioFormFields.vue';

// Form genérico usado só pela lista "Todos os Utilizadores" (Pages/Index.vue),
// onde o perfil não é implícito e precisa ser escolhido manualmente. As
// listas filtradas (Alunos, Professores, Funcionarios, Administradores) têm
// cada uma o seu próprio Form na sua subpasta dentro de Forms/, com a sua
// própria rota. Encarregado fica fora daqui porque precisa do campo extra
// de ligação a educandos — usa sempre o EncarregadoForm dedicado.
const form = reactive({
    name: '',
    email: '',
    password: '',
});
const perfil = ref('aluno');
const tipoLogin = computed(() => (perfil.value === 'aluno' ? 'matricula' : 'email'));
const processing = ref(false);
const errors = ref({});
const errorMessage = ref('');

function criar(estado) {
    processing.value = true;
    errors.value = {};
    errorMessage.value = '';

    return new Promise((resolve, reject) => {
        router.post(
            '/usuarios/cadastrarUsuario',
            {
                name: form.name,
                email: tipoLogin.value === 'email' ? form.email : undefined,
                password: form.password,
                perfil: perfil.value,
                tipo_login: tipoLogin.value,
                estado,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.name = '';
                    form.email = '';
                    form.password = '';
                    toast.success('Utilizador criado com sucesso.');
                    resolve();
                },
                onError: (erros) => {
                    errors.value = erros;
                    if (Object.keys(erros).length === 0) {
                        errorMessage.value = 'Não foi possível guardar o utilizador. Tenta novamente.';
                    }
                    toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o utilizador.');
                    reject(erros);
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    });
}

function fecharModal() {
    window.bootstrap?.Modal.getInstance(document.getElementById('kt_modal_add_user'))?.hide();
}

async function onGuardar() {
    try {
        await criar(1); // 1 = ativo
        fecharModal();
    } catch {
        // erros de validação já ficam em `errors`/`errorMessage`, mostrados no template
    }
}

async function onGuardarRascunho() {
    try {
        await criar(0); // 0 = inativo/rascunho
        fecharModal();
    } catch {
        // erros de validação já ficam em `errors`/`errorMessage`, mostrados no template
    }
}

function onCancelar() {
    form.name = '';
    form.email = '';
    form.password = '';
    errors.value = {};
    errorMessage.value = '';
    fecharModal();
}
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#" @submit.prevent="onGuardar">
        <!--begin::Scroll-->
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true"
            data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto"
            data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll"
            data-kt-scroll-offset="300px">
            <div class="alert alert-danger" v-if="errorMessage">{{ errorMessage }}</div>

            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :tipo-login="tipoLogin" :errors="errors" />

            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <label class="required fw-semibold fs-6 mb-2" for="kt_modal_add_user_perfil">Perfil</label>

                <select v-model="perfil" name="user_perfil" id="kt_modal_add_user_perfil" data-control="select2"
                    data-placeholder="Selecione o perfil" data-hide-search="true"
                    class="form-select form-select-solid">
                    <option value="aluno">Aluno</option>
                    <option value="professor">Professor</option>
                    <option value="secretario">Funcionário</option>
                    <option value="admin_escola">Administrador</option>
                </select>
            </div>
            <!--end::Input group-->
        </div>
        <!--end::Scroll-->

        <!--begin::Actions-->
        <div class="text-center pt-15">
            <button type="button" class="btn btn-danger me-3" data-kt-users-modal-action="cancel"
                :disabled="processing" @click="onCancelar">
                Cancelar
            </button>

            <button type="button" class="btn btn-castanho me-3" data-kt-users-modal-action="draft"
                :disabled="processing" @click="onGuardarRascunho">
                Guardar rascunho
            </button>

            <button type="submit" class="btn btn-primary" data-kt-users-modal-action="submit"
                :data-kt-indicator="processing ? 'on' : 'off'" :disabled="processing">
                <span class="indicator-label">
                    Guardar
                </span>
                <span class="indicator-progress">
                    Aguarde...
                    <Loader size="0.3px" class="align-middle ms-2" />
                </span>
            </button>
        </div>
        <!--end::Actions-->
    </form>
</template>
