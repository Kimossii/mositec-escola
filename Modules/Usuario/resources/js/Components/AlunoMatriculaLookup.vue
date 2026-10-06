<script setup>
// Cadastro de utilizador com perfil aluno: o funcionário escreve o número de matrícula, procura e vê
// o nome (só leitura) que vem da base de dados. O servidor volta a resolver tudo ao criar — este
// ecrã só mostra; nunca envia nome, email, tipo de login nem dados pessoais.
import { computed, ref } from 'vue';
import axios from 'axios';
import Loader from '@/Components/Shared/Loader.vue';

const matricula = defineModel('matricula', { default: '' });
// { nome, estado, estado_descricao, ja_tem_conta } ou null enquanto não foi encontrado.
const aluno = defineModel('aluno', { default: null });

defineProps({
    // Erro do servidor para o campo numero_matricula (ao guardar).
    erro: { type: String, default: '' },
});

const procurando = ref(false);
const mensagemPesquisa = ref('');

const jaTemConta = computed(() => aluno.value?.ja_tem_conta === true);
// Sem aluno.ver o servidor responde só com nome/estado: nada de linhas vazias nem "—".
const temDadosCompletos = computed(() => aluno.value !== null && 'data_nascimento' in aluno.value);
const semCursoNemTurma = computed(() => !aluno.value?.curso && !aluno.value?.turma);

// Formata a string ISO (AAAA-MM-DD) sem passar por Date, para não haver desvio de fuso.
function formatarData(iso) {
    if (!iso) return '—';
    const [ano, mes, dia] = iso.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}

// Alterar a matrícula depois de encontrar limpa o resultado.
function aoEditar() {
    aluno.value = null;
    mensagemPesquisa.value = '';
}

async function procurar() {
    const valor = matricula.value.trim();
    aluno.value = null;
    mensagemPesquisa.value = '';
    procurando.value = true;

    try {
        const { data } = await axios.get('/usuarios/alunos/procurar-matricula', { params: { matricula: valor } });
        aluno.value = data;
    } catch (e) {
        // A mensagem vem sempre do servidor (404 genérico, 422 de validação, 429 do throttle).
        const dados = e.response?.data;
        mensagemPesquisa.value = dados?.errors?.matricula?.[0] ?? dados?.message ?? '';
    } finally {
        procurando.value = false;
    }
}
</script>

<template>
    <div class="fv-row mb-7">
        <label class="required fw-semibold fs-6 mb-2">Número de matrícula</label>
        <div class="d-flex gap-2">
            <input v-model="matricula" type="text" name="numero_matricula" class="form-control form-control-solid"
                placeholder="Número de matrícula do aluno" autocomplete="off" @input="aoEditar"
                @keydown.enter.prevent="procurar" />
            <button type="button" class="btn btn-light-primary" :disabled="procurando || !matricula.trim()" @click="procurar">
                <span v-if="!procurando">Procurar</span>
                <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
            </button>
        </div>

        <div class="text-danger fs-7 mt-1" v-if="mensagemPesquisa">{{ mensagemPesquisa }}</div>
        <div class="text-danger fs-7 mt-1" v-else-if="erro">{{ erro }}</div>

        <div v-if="aluno" class="bg-body-secondary rounded p-4 mt-4">
            <div class="text-muted fs-7 mb-1">Nome</div>
            <div class="fw-bold fs-5 mb-3">{{ aluno.nome }}</div>
            <template v-if="temDadosCompletos">
                <div class="row mb-3">
                    <div class="col-sm-6 mb-3 mb-sm-0">
                        <div class="text-muted fs-7 mb-1">Data de nascimento</div>
                        <div class="fw-semibold">{{ formatarData(aluno.data_nascimento) }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted fs-7 mb-1">Nº de identificação</div>
                        <div class="fw-semibold">{{ aluno.numero_identificacao || '—' }}</div>
                    </div>
                </div>
                <div v-if="semCursoNemTurma" class="text-muted fs-7 mb-3">Sem turma ou curso atribuído</div>
                <div v-else class="row mb-3">
                    <div class="col-sm-6 mb-3 mb-sm-0">
                        <div class="text-muted fs-7 mb-1">Curso</div>
                        <div class="fw-semibold">{{ aluno.curso || '—' }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted fs-7 mb-1">Turma</div>
                        <div class="fw-semibold">{{ aluno.turma || '—' }}</div>
                    </div>
                </div>
            </template>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted fs-7">Estado do aluno</span>
                <span class="badge" :class="aluno.estado === 1 ? 'badge-light-success' : 'badge-light-danger'">
                    {{ aluno.estado_descricao }}
                </span>
            </div>
            <div class="text-danger fs-7 mt-3" v-if="jaTemConta">Este aluno já tem conta.</div>
        </div>
    </div>
</template>
