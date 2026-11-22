<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import { errosForaDosCampos } from '../Composables/licencas';

// Botões e modais do ciclo de vida de uma licença, consoante as acções que o backend permite
// (`accoes_permitidas`). Todas exigem motivo; sucesso e erros vêm do backend.
const props = defineProps({
    licenca: { type: Object, required: true },
    accoes: { type: Object, required: true },
});

const DEFINICOES = {
    renovar: { titulo: 'Renovar licença', texto: 'É emitida uma licença nova, com nova validade, que substitui a actual.', botao: 'Renovar', variante: 'primary' },
    estender: { titulo: 'Estender tolerância', texto: 'É emitida uma licença nova, igual à actual mas com mais dias de tolerância. Fica registado no histórico.', botao: 'Estender', variante: 'primary' },
    suspender: { titulo: 'Suspender licença', texto: 'A licença deixa de valer (nas escolas desta instalação, de imediato) e pode ser reactivada.', botao: 'Suspender', variante: 'warning' },
    reactivar: { titulo: 'Reactivar licença', texto: 'A licença volta a valer.', botao: 'Reactivar', variante: 'success' },
    cancelar: { titulo: 'Cancelar licença', texto: 'O cancelamento é definitivo.', botao: 'Cancelar licença', variante: 'danger' },
};

const modal = ref(null);
const processando = ref(false);
const erros = ref({});
const motivo = ref('');
const tolerancia = ref('');

const CAMPOS_DO_MODAL = ['motivo', 'tolerancia_dias'];
const errosGerais = computed(() => errosForaDosCampos(erros.value, CAMPOS_DO_MODAL));

const definicao = computed(() => DEFINICOES[modal.value] ?? null);

function abrir(qual) {
    erros.value = {};
    motivo.value = '';
    tolerancia.value = qual === 'estender' ? (props.licenca.tolerancia_dias ?? 0) + 7 : '';
    modal.value = qual;
}

function fechar() {
    if (!processando.value) modal.value = null;
}

function enviar() {
    processando.value = true;
    erros.value = {};
    const dados = { motivo: motivo.value };
    if (modal.value === 'estender') dados.tolerancia_dias = tolerancia.value;

    router.post(`/plataforma/licencas/${props.licenca.id}/${modal.value}`, dados, {
        preserveScroll: true,
        onSuccess: () => {
            modal.value = null;
        },
        onError: (e) => {
            erros.value = e;
            // O modal fica aberto: o erro (de campo ou da acção) vê-se no contexto, sem se perder.
        },
        onFinish: () => {
            processando.value = false;
        },
    });
}
</script>

<template>
    <div class="d-flex flex-wrap align-items-center gap-3">
        <button v-if="accoes.renovar" type="button" class="btn btn-light-primary" @click="abrir('renovar')">Renovar</button>
        <button v-if="accoes.estender" type="button" class="btn btn-light-primary" @click="abrir('estender')">Estender tolerância</button>
        <button v-if="accoes.suspender" type="button" class="btn btn-light-warning" @click="abrir('suspender')">Suspender</button>
        <button v-if="accoes.reactivar" type="button" class="btn btn-light-success" @click="abrir('reactivar')">Reactivar</button>
        <button v-if="accoes.cancelar" type="button" class="btn btn-light-danger" @click="abrir('cancelar')">Cancelar licença</button>
    </div>

    <div v-if="definicao" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="fechar">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content p-6" novalidate @submit.prevent="enviar">
                <h3 class="mb-4">{{ definicao.titulo }}</h3>
                <p class="text-gray-700 mb-4">{{ definicao.texto }}</p>
                <div v-if="errosGerais.length" class="alert alert-danger" role="alert">
                    <div v-for="mensagem in errosGerais" :key="mensagem">{{ mensagem }}</div>
                </div>
                <template v-if="modal === 'estender'">
                    <label class="required fw-semibold fs-6 mb-2" for="licenca-nova-tolerancia">Nova tolerância (dias; actual: {{ licenca.tolerancia_dias }})</label>
                    <input id="licenca-nova-tolerancia" v-model="tolerancia" type="number" min="0" inputmode="numeric" name="tolerancia_dias" class="form-control form-control-solid mb-1" :class="{ 'is-invalid': erros.tolerancia_dias }" :disabled="processando" />
                    <div v-if="erros.tolerancia_dias" class="text-danger fs-7 mb-3">{{ erros.tolerancia_dias }}</div>
                    <div class="mb-4" />
                </template>
                <label class="required fw-semibold fs-6 mb-2" for="licenca-motivo">Motivo</label>
                <textarea id="licenca-motivo" v-model="motivo" name="motivo" rows="3" maxlength="255" class="form-control form-control-solid" :class="{ 'is-invalid': erros.motivo }" :disabled="processando" />
                <div v-if="erros.motivo" class="text-danger fs-7 mt-1">{{ erros.motivo }}</div>
                <div class="text-end mt-6">
                    <button type="button" class="btn btn-light-danger me-2" :disabled="processando" @click="fechar">Cancelar</button>
                    <button type="submit" class="btn" :class="`btn-${definicao.variante}`" :disabled="processando">
                        <span v-if="!processando">{{ definicao.botao }}</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
