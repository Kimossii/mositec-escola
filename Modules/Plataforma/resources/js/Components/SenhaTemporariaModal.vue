<script setup>
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import { copiarParaAreaDeTransferencia } from '@/Composables/useCopiar';

// Modal único das duas fases de uma operação que gera uma senha temporária (criar escola, recuperar
// administrador): confirmação (`pedido` definido, sem resultado) e resultado (`resultado` com a senha).
// A senha vive só nas props do pai e é descartada ao fechar; aqui não é copiada para nenhum outro
// estado, storage, URL ou consola.
defineProps({
    /** { titulo, mensagem, textoConfirmar } da fase de confirmação, ou null */
    pedido: { type: Object, default: null },
    /** { codigo, email, senha } devolvido pelo backend (fase de resultado), ou null */
    resultado: { type: Object, default: null },
    processando: { type: Boolean, default: false },
});
const emit = defineEmits(['confirmar', 'fechar']);

const copiado = ref(false);

async function copiar(senha) {
    if (await copiarParaAreaDeTransferencia(senha)) {
        copiado.value = true;
        setTimeout(() => { copiado.value = false; }, 2000);
    } else {
        toast.error('Não foi possível copiar. Seleccione a senha e copie manualmente.');
    }
}

function fechar() {
    copiado.value = false;
    emit('fechar');
}
</script>

<template>
    <div
        v-if="pedido || resultado"
        class="modal d-block"
        style="background: rgba(0,0,0,0.5);"
        @click.self="!processando && fechar()"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <template v-if="resultado">
                    <h3 class="mb-4">Senha temporária</h3>
                    <dl class="mb-4">
                        <dt class="text-muted fs-7 fw-semibold">Código da escola</dt>
                        <dd class="fw-bold text-gray-800 mb-3" data-testid="codigo-escola">{{ resultado.codigo }}</dd>
                        <dt class="text-muted fs-7 fw-semibold">E-mail do administrador</dt>
                        <dd class="fw-bold text-gray-800 mb-0" data-testid="email-administrador">{{ resultado.email }}</dd>
                    </dl>
                    <div class="bg-body-secondary rounded p-4 mb-4 d-flex align-items-center justify-content-between">
                        <code class="fs-3 fw-bold text-gray-800 user-select-all" data-testid="senha-temporaria">{{ resultado.senha }}</code>
                        <button type="button" class="btn btn-sm btn-light-primary ms-3" @click="copiar(resultado.senha)">
                            {{ copiado ? 'Copiada' : 'Copiar' }}
                        </button>
                    </div>
                    <p class="text-gray-700 mb-6">
                        Esta senha só é mostrada <strong>agora</strong>; ao fechar não será possível voltar a vê-la.
                        Será exigida a troca no primeiro acesso.
                    </p>
                    <div class="text-end">
                        <button type="button" class="btn btn-primary" @click="fechar">Fechar</button>
                    </div>
                </template>
                <template v-else>
                    <h3 class="mb-4">{{ pedido.titulo }}</h3>
                    <p class="text-gray-700 mb-6">{{ pedido.mensagem }}</p>
                    <div class="text-end">
                        <button type="button" class="btn btn-light-primary me-2" :disabled="processando" @click="fechar">
                            Cancelar
                        </button>
                        <button type="button" class="btn btn-primary" :disabled="processando" @click="emit('confirmar')">
                            <span v-if="!processando">{{ pedido.textoConfirmar }}</span>
                            <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
