<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import ConfirmarEncerramentoModal from './ConfirmarEncerramentoModal.vue';

// Botões do ciclo de vida consoante as acções que o backend permite para o estado da escola
// (`escola.accoes_permitidas`). Sucesso e erros vêm do backend: sem textos de reserva.
const props = defineProps({
    escola: { type: Object, required: true },
});

const base = () => `/plataforma/escolas/${props.escola.codigo}`;

const modal = ref(null); // 'suspender' | 'encerrar' | null
const processando = ref(false);
const erros = ref({});
const motivo = ref('');
const revogarAcessos = ref(false);

function abrir(qual) {
    erros.value = {};
    motivo.value = '';
    revogarAcessos.value = false;
    modal.value = qual;
}

function fechar() {
    if (!processando.value) modal.value = null;
}

function enviar(caminho, dados) {
    processando.value = true;
    erros.value = {};

    router.post(`${base()}/${caminho}`, dados, {
        preserveScroll: true,
        onSuccess: () => {
            modal.value = null;
        },
        onError: (e) => {
            erros.value = e;
            // Erro que não é de um campo (ex.: transição recusada): mostra-se no topo da página.
            if (e.geral) modal.value = null;
        },
        onFinish: () => {
            processando.value = false;
        },
    });
}

function suspender() {
    enviar('suspender', { motivo: motivo.value, revogar_acessos: revogarAcessos.value });
}

function reactivar() {
    enviar('reactivar', {});
}

function encerrar(confirmacao) {
    enviar('encerrar', { confirmacao });
}
</script>

<template>
    <div class="d-flex align-items-center gap-3">
        <span v-if="!escola.accoes_permitidas.suspender && !escola.accoes_permitidas.reactivar && !escola.accoes_permitidas.encerrar" class="text-muted fs-7">
            Encerrada: estado terminal
        </span>

        <button v-if="escola.accoes_permitidas.suspender" type="button" class="btn btn-light-warning" :disabled="processando" @click="abrir('suspender')">
            Suspender
        </button>
        <button v-if="escola.accoes_permitidas.reactivar" type="button" class="btn btn-light-success" :disabled="processando" @click="reactivar">
            <span v-if="!processando">Reactivar</span>
            <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
        </button>
        <button v-if="escola.accoes_permitidas.encerrar" type="button" class="btn btn-light-danger" :disabled="processando" @click="abrir('encerrar')">
            Encerrar
        </button>
    </div>

    <div v-if="modal === 'suspender'" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="fechar">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content p-6" novalidate @submit.prevent="suspender">
                <h3 class="mb-4">Suspender {{ escola.nome }}</h3>
                <p class="text-gray-700 mb-4">
                    A escola deixa de ser acessível (os dados ficam intactos) e pode ser reactivada mais tarde.
                </p>
                <label class="required fw-semibold fs-6 mb-2" for="motivo-suspensao">Motivo da suspensão</label>
                <textarea
                    id="motivo-suspensao"
                    v-model="motivo"
                    name="motivo"
                    rows="3"
                    class="form-control form-control-solid"
                    :class="{ 'is-invalid': erros.motivo }"
                    :disabled="processando"
                />
                <div v-if="erros.motivo" class="text-danger fs-7 mt-1">{{ erros.motivo }}</div>
                <div class="form-check form-check-custom form-check-solid mt-5">
                    <input id="revogar-acessos" v-model="revogarAcessos" class="form-check-input" type="checkbox" name="revogar_acessos" :disabled="processando" />
                    <label class="form-check-label text-gray-700" for="revogar-acessos">
                        Terminar sessões e tokens activos desta escola
                    </label>
                </div>
                <div class="text-end mt-6">
                    <button type="button" class="btn btn-light-primary me-2" :disabled="processando" @click="fechar">Cancelar</button>
                    <button type="submit" class="btn btn-warning" :disabled="processando">
                        <span v-if="!processando">Suspender escola</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <ConfirmarEncerramentoModal
        :show="modal === 'encerrar'"
        :codigo="escola.codigo"
        :nome="escola.nome"
        :processando="processando"
        :erro="erros.confirmacao ?? ''"
        @confirmar="encerrar"
        @cancelar="fechar"
    />
</template>
