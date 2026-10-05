<script setup>
import { computed, ref, watch } from 'vue';
import Loader from '@/Components/Shared/Loader.vue';

// Salvaguarda da interface: encerrar é terminal, por isso o operador escreve o código da escola.
// A confirmação é validada de novo no servidor; aqui só se desactiva o botão até coincidir.
const props = defineProps({
    show: { type: Boolean, default: false },
    codigo: { type: String, required: true },
    nome: { type: String, required: true },
    processando: { type: Boolean, default: false },
    /** Mensagem de erro do backend para o campo de confirmação */
    erro: { type: String, default: '' },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const escrito = ref('');
const coincide = computed(() => escrito.value === props.codigo);

// Cada abertura começa com o campo vazio.
watch(() => props.show, (aberto) => {
    if (aberto) escrito.value = '';
});

function confirmar() {
    if (coincide.value && !props.processando) emit('confirmar', escrito.value);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="!processando && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content p-6" novalidate @submit.prevent="confirmar">
                <h3 class="mb-4">Encerrar {{ nome }}</h3>
                <div class="alert alert-danger">
                    O encerramento é <strong>terminal</strong>: a escola deixa de ser acessível, os domínios ficam reservados
                    e <strong>não existe forma de a reactivar pelo painel</strong>. Reabrir é um procedimento manual.
                </div>
                <label class="fw-semibold fs-6 mb-2" for="confirmacao-encerramento">
                    Para confirmar, escreva o código da escola: <code class="text-gray-800">{{ codigo }}</code>
                </label>
                <input
                    id="confirmacao-encerramento"
                    v-model="escrito"
                    type="text"
                    name="confirmacao"
                    autocomplete="off"
                    class="form-control form-control-solid"
                    :class="{ 'is-invalid': erro }"
                    :disabled="processando"
                />
                <div v-if="erro" class="text-danger fs-7 mt-1">{{ erro }}</div>
                <div class="text-end mt-6">
                    <button type="button" class="btn btn-light-primary me-2" :disabled="processando" @click="emit('cancelar')">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-danger" :disabled="!coincide || processando">
                        <span v-if="!processando">Encerrar escola</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
