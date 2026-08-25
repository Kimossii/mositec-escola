<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../../Components/UsuarioFormFields.vue';

// Form de criação/edição específico da lista Administradores — submete
// direto pra POST /usuarios/administradores/cadastrar via router do Inertia
// (não axios). Diferente dos outros 3 (Aluno/Professor/Funcionário),
// "Administrador" não é um tipo_pessoa no banco — é um papel que ainda vai
// depender da integração com roles/permissoes do módulo Permissao (ver
// Models/Usuario.js#isAdministrador). Por isso não tem input hidden de
// tipo_pessoa aqui: falta decidir com o backend como esse papel será
// atribuído no submit.
//
// O submit ainda só cria o User (name/email/password/estado) — o papel de
// administrador não é atribuído aqui.
const form = reactive({
    name: '',
    email: '',
    password: '',
});
const processing = ref(false);
const errors = ref({});
const errorMessage = ref('');

function criar(estado) {
    processing.value = true;
    errors.value = {};
    errorMessage.value = '';

    return new Promise((resolve, reject) => {
        router.post(
            '/usuarios/administradores/cadastrar',
            { name: form.name, email: form.email, password: form.password, estado },
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.name = '';
                    form.email = '';
                    form.password = '';
                    resolve();
                },
                onError: (erros) => {
                    errors.value = erros;
                    if (Object.keys(erros).length === 0) {
                        errorMessage.value = 'Não foi possível guardar o utilizador. Tenta novamente.';
                    }
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
}
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#" @submit.prevent="onGuardar">
        <!--begin::Scroll-->
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
            <div class="alert alert-danger" v-if="errorMessage">{{ errorMessage }}</div>

            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :errors="errors" />

            <!--begin::Input group-->
            <div class="mb-7">
                <label class="fw-semibold fs-6 mb-2 d-block">Papel</label>
                <span class="badge badge-light-danger fs-6">Administrador</span>
                <div class="form-text mt-2">
                    A atribuição de administrador ainda depende da integração com Permissões (roles).
                </div>
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
                    Aguarde... <Loader size="0.3px" class="align-middle ms-2" />
                </span>
            </button>
        </div>
        <!--end::Actions-->
    </form>
</template>
