<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../../Components/UsuarioFormFields.vue';

// Form de criação específico da lista Encarregados — igual aos outros tipos
// de staff (login por email), mas com um campo extra para ligar aos
// educandos já cadastrados, por matrícula (ver AlunoForm.vue para a
// matrícula ser sempre gerada pelo sistema, nunca digitada).
const form = reactive({
    name: '',
    email: '',
    password: '',
});
const matriculaEducando = ref('');
const matriculasEducandos = ref([]);
const processing = ref(false);
const errors = ref({});
const errorMessage = ref('');

function adicionarEducando() {
    const matricula = matriculaEducando.value.trim();
    if (matricula && !matriculasEducandos.value.includes(matricula)) {
        matriculasEducandos.value.push(matricula);
    }
    matriculaEducando.value = '';
}

function removerEducando(matricula) {
    matriculasEducandos.value = matriculasEducandos.value.filter((m) => m !== matricula);
}

function criar(estado) {
    processing.value = true;
    errors.value = {};
    errorMessage.value = '';

    return new Promise((resolve, reject) => {
        router.post(
            '/usuarios/encarregados/cadastrar',
            {
                name: form.name,
                email: form.email,
                password: form.password,
                tipo_login: 'email',
                matriculas_educandos: matriculasEducandos.value,
                estado,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.name = '';
                    form.email = '';
                    form.password = '';
                    matriculasEducandos.value = [];
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

function temPeloMenosUmEducando() {
    if (matriculasEducandos.value.length === 0) {
        errorMessage.value = 'É preciso ligar pelo menos um educando.';
        return false;
    }
    return true;
}

async function onGuardar() {
    if (!temPeloMenosUmEducando()) {
        return;
    }
    try {
        await criar(1);
        fecharModal();
    } catch {
        // erros de validação já ficam em `errors`/`errorMessage`, mostrados no template
    }
}

async function onGuardarRascunho() {
    if (!temPeloMenosUmEducando()) {
        return;
    }
    try {
        await criar(0);
        fecharModal();
    } catch {
        // erros de validação já ficam em `errors`/`errorMessage`, mostrados no template
    }
}

function onCancelar() {
    form.name = '';
    form.email = '';
    form.password = '';
    matriculasEducandos.value = [];
    errors.value = {};
    errorMessage.value = '';
    fecharModal();
}
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#" @submit.prevent="onGuardar">
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
            <div class="alert alert-danger" v-if="errorMessage">{{ errorMessage }}</div>

            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                tipo-login="email" :errors="errors" />

            <div class="mb-7">
                <label class="fw-semibold fs-6 mb-2 d-block">Tipo</label>
                <span class="badge badge-light-info fs-6">Encarregado</span>
            </div>

            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <label class="fw-semibold fs-6 mb-2">Educandos</label>
                <div class="d-flex gap-2 mb-2">
                    <input v-model="matriculaEducando" type="text" class="form-control form-control-solid"
                        placeholder="Matrícula do educando" @keydown.enter.prevent="adicionarEducando" />
                    <button type="button" class="btn btn-light-primary" @click="adicionarEducando">Adicionar</button>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <span v-for="matricula in matriculasEducandos" :key="matricula" class="badge badge-light-primary fs-7">
                        {{ matricula }}
                        <a href="#" class="ms-2 text-danger" @click.prevent="removerEducando(matricula)">&times;</a>
                    </span>
                </div>
                <div class="text-danger fs-7 mt-1" v-if="errors.matriculas_educandos">{{ errors.matriculas_educandos[0] }}</div>
            </div>
            <!--end::Input group-->
        </div>

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
                <span class="indicator-label">Guardar</span>
                <span class="indicator-progress">
                    Aguarde... <Loader size="0.3px" class="align-middle ms-2" />
                </span>
            </button>
        </div>
    </form>
</template>
