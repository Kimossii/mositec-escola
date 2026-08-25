<script setup>
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../../Components/UsuarioFormFields.vue';
import { TIPO_PESSOA } from '../../Models/Usuario';
import { useUsuarioCriar } from '../../Composables/useUsuarioCriar';

// Form de criação/edição específico da lista Funcionários. Ver o mesmo
// comentário em AlunoForm.vue — espaço aqui pra campos futuros
// só de funcionário (cargo, setor...).
//
// TIPO_PESSOA.FUNCIONARIO só é exibido no badge — não é enviado no submit,
// pois a rota /usuarios/store ainda só grava em `users` (ver Composables/useUsuarioCriar.js).
const { form, processing, errors, errorMessage, criar } = useUsuarioCriar();

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

            <input type="hidden" name="user_tipo_pessoa" :value="TIPO_PESSOA.FUNCIONARIO" />

            <!--begin::Input group-->
            <div class="mb-7">
                <label class="fw-semibold fs-6 mb-2 d-block">Tipo</label>
                <span class="badge badge-light-warning fs-6">Funcionário</span>
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
