<script setup>
import { computed, reactive, watch } from 'vue';
import Loader from '@/Components/Shared/Loader.vue';
import PasswordInput from '@/Components/Shared/PasswordInput.vue';
import ErrosGerais from '../Shared/ErrosGerais.vue';

/**
 * «Criar acesso» (conta de utilizador com perfil Professor) para um docente sem conta. Só se pede o email de login e a
 * senha provisória: o nome e a pessoa vêm do docente, no servidor. As recusas de domínio (já tem acesso, inactivo)
 * chegam em `errors.docente_id`, sem campo no modal, e aparecem no aviso geral do topo.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    docente: { type: Object, default: null }, // linha da lista
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['email', 'password', 'password_confirmation'];

const form = reactive({ email: '', password: '', password_confirmation: '' });

// Feedback imediato só depois de começar a confirmar (igual ao formulário de utilizadores).
const senhasNaoCoincidem = computed(() => form.password_confirmation.length > 0 && form.password !== form.password_confirmation);

watch(() => props.show, (show) => {
    if (!show) return;
    // O email de contacto do docente serve de sugestão; pode ser outro.
    form.email = props.docente?.email ?? '';
    form.password = '';
    form.password_confirmation = '';
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">Criar acesso</h3>
                <div class="text-muted fs-7 mb-5">{{ docente?.nome }}</div>
                <form @submit.prevent="submeter">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="bg-body-secondary rounded p-4 mb-7 fs-7 text-gray-700">
                        O docente passa a entrar no sistema com o perfil Professor (p. ex. para lançar notas). O nome é o do
                        cadastro do docente. Comunique-lhe a senha provisória.
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Email de acesso</label>
                        <input v-model="form.email" type="email" name="email" autocomplete="off" class="form-control form-control-solid" placeholder="exemplo@dominio.com" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Senha provisória</label>
                        <PasswordInput v-model="form.password" name="password" placeholder="Mínimo 6 caracteres" :invalid="!!errors.password" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.password">{{ errors.password }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Confirmar senha</label>
                        <PasswordInput v-model="form.password_confirmation" name="password_confirmation" placeholder="Repita a senha" :invalid="senhasNaoCoincidem" />
                        <div class="text-danger fs-7 mt-1" v-if="senhasNaoCoincidem">As senhas não coincidem.</div>
                        <div class="text-danger fs-7 mt-1" v-else-if="errors.password_confirmation">{{ errors.password_confirmation }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="processing || senhasNaoCoincidem">
                            <span v-if="processing">A criar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>Criar acesso</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
