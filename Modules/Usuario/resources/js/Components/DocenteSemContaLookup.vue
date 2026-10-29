<script setup>
// Novo professor a partir de um docente já registado (Pessoal → Docentes) que ainda não tem conta: o funcionário
// procura por nome ou nº de identificação e escolhe um. O nome e a pessoa vêm do docente, no servidor; este ecrã só
// mostra e envia o `docente_id` (nunca nome nem dados pessoais).
import { ref } from 'vue';
import axios from 'axios';
import Loader from '@/Components/Shared/Loader.vue';

// { id, nome, numero_identificacao?, email? } ou null. BI e email só vêm a quem tem docente.ver.
const docente = defineModel('docente', { default: null });

defineProps({
    // Erro do servidor para `docente_id` (ao guardar: inexistente, inactivo, já tem acesso).
    erro: { type: String, default: '' },
});

const pesquisa = ref('');
const resultados = ref(null); // null = ainda não procurou
const procurando = ref(false);
const mensagemPesquisa = ref('');

async function procurar() {
    mensagemPesquisa.value = '';
    procurando.value = true;

    try {
        const { data } = await axios.get('/usuarios/professores/procurar-docente', { params: { pesquisa: pesquisa.value.trim() } });
        resultados.value = data.docentes ?? [];
    } catch (e) {
        // A mensagem vem do servidor (422 de validação, 403, 429 do throttle).
        const dados = e.response?.data;
        resultados.value = null;
        mensagemPesquisa.value = dados?.errors?.pesquisa?.[0] ?? dados?.message ?? 'Não foi possível procurar docentes.';
    } finally {
        procurando.value = false;
    }
}

function escolher(d) {
    docente.value = d;
}

function trocar() {
    docente.value = null;
}
</script>

<template>
    <div class="fv-row mb-7">
        <label class="required fw-semibold fs-6 mb-2">Docente</label>

        <div v-if="docente" class="bg-body-secondary rounded p-4">
            <div class="d-flex align-items-start justify-content-between gap-3">
                <div>
                    <div class="text-muted fs-7 mb-1">Nome</div>
                    <div class="fw-bold fs-5">{{ docente.nome }}</div>
                    <div v-if="docente.numero_identificacao" class="text-muted fs-7 mt-1">Nº de identificação: {{ docente.numero_identificacao }}</div>
                </div>
                <button type="button" class="btn btn-sm btn-light-primary" @click="trocar">Trocar</button>
            </div>
            <div class="form-text mt-3">O nome vem do cadastro do docente e não se altera aqui.</div>
        </div>

        <template v-else>
            <div class="d-flex gap-2">
                <input v-model="pesquisa" type="text" class="form-control form-control-solid" placeholder="Nome ou nº de identificação do docente"
                    autocomplete="off" @keydown.enter.prevent="procurar" />
                <button type="button" class="btn btn-light-primary" :disabled="procurando" @click="procurar">
                    <span v-if="!procurando">Procurar</span>
                    <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                </button>
            </div>
            <div class="text-muted fs-8 mt-1" v-if="resultados === null && !mensagemPesquisa">Só aparecem docentes activos que ainda não têm acesso.</div>

            <div v-if="resultados !== null" class="bg-body-secondary rounded mt-4 overflow-auto" style="max-height: 240px">
                <div v-if="!resultados.length" class="text-muted fs-7 p-4">Nenhum docente activo sem acesso encontrado.</div>
                <a v-for="d in resultados" :key="d.id" href="#"
                    class="d-flex align-items-center justify-content-between gap-3 px-4 py-3 border-bottom border-gray-300 border-dashed text-hover-primary"
                    @click.prevent="escolher(d)">
                    <span>
                        <span class="fw-semibold text-gray-800 d-block">{{ d.nome }}</span>
                        <span v-if="d.numero_identificacao" class="text-muted fs-8">{{ d.numero_identificacao }}</span>
                    </span>
                    <span class="text-primary fs-7 fw-semibold">Escolher</span>
                </a>
            </div>
        </template>

        <div class="text-danger fs-7 mt-1" v-if="mensagemPesquisa">{{ mensagemPesquisa }}</div>
        <div class="text-danger fs-7 mt-1" v-else-if="erro">{{ erro }}</div>
    </div>
</template>
