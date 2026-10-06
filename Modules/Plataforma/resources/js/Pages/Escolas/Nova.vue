<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';

defineOptions({ layout: LayoutPlataforma });

const form = reactive({
    nome: '',
    admin_nome: '',
    admin_email: '',
    dominio: '',
    codigo: '',
});
const processing = ref(false);
const errors = ref({});

const CAMPOS = ['nome', 'admin_nome', 'admin_email', 'dominio', 'codigo'];

// Erros que não pertencem a nenhum campo (ex.: modo de instalação única, provisioning incompleto).
function errosGerais() {
    return Object.entries(errors.value).filter(([campo]) => !CAMPOS.includes(campo)).map(([, mensagem]) => mensagem);
}

function submit() {
    processing.value = true;
    errors.value = {};

    router.post('/plataforma/escolas', form, {
        onError: (erros) => {
            errors.value = erros;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Nova escola</h1>
            <Link href="/plataforma/escolas" class="btn btn-light">Voltar</Link>
        </div>

        <div class="card">
            <form class="card-body" novalidate @submit.prevent="submit">
                <div v-if="errosGerais().length" class="alert alert-danger">
                    <div v-for="mensagem in errosGerais()" :key="mensagem">{{ mensagem }}</div>
                </div>

                <h4 class="mb-4">Escola</h4>
                <div class="row g-6 mb-8">
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2">Nome da escola</label>
                        <input v-model="form.nome" type="text" name="nome" class="form-control form-control-solid" :class="{ 'is-invalid': errors.nome }" />
                        <div v-if="errors.nome" class="text-danger fs-7 mt-1">{{ errors.nome }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2">Domínio principal</label>
                        <input v-model="form.dominio" type="text" name="dominio" placeholder="escola.exemplo.ao" class="form-control form-control-solid" :class="{ 'is-invalid': errors.dominio }" />
                        <div v-if="errors.dominio" class="text-danger fs-7 mt-1">{{ errors.dominio }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="fw-semibold fs-6 mb-2">Código (opcional)</label>
                        <input v-model="form.codigo" type="text" name="codigo" placeholder="MOSI-000000" class="form-control form-control-solid" :class="{ 'is-invalid': errors.codigo }" />
                        <div class="text-muted fs-7 mt-1">Deixe em branco para gerar automaticamente.</div>
                        <div v-if="errors.codigo" class="text-danger fs-7 mt-1">{{ errors.codigo }}</div>
                    </div>
                </div>

                <h4 class="mb-4">Administrador inicial</h4>
                <div class="row g-6 mb-8">
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2">Nome do administrador</label>
                        <input v-model="form.admin_nome" type="text" name="admin_nome" class="form-control form-control-solid" :class="{ 'is-invalid': errors.admin_nome }" />
                        <div v-if="errors.admin_nome" class="text-danger fs-7 mt-1">{{ errors.admin_nome }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2">E-mail do administrador</label>
                        <input v-model="form.admin_email" type="email" name="admin_email" autocomplete="off" class="form-control form-control-solid" :class="{ 'is-invalid': errors.admin_email }" />
                        <div v-if="errors.admin_email" class="text-danger fs-7 mt-1">{{ errors.admin_email }}</div>
                    </div>
                </div>

                <p class="text-muted mb-6">
                    O administrador recebe uma senha temporária, mostrada uma única vez depois de criar a escola.
                </p>

                <div class="text-end">
                    <Link href="/plataforma/escolas" class="btn btn-light me-2" :class="{ disabled: processing }">Cancelar</Link>
                    <button type="submit" class="btn btn-primary" :disabled="processing">
                        <span v-if="!processing">Criar escola</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
