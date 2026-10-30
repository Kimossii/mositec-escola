<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import ResultadoGeracao from './ResultadoGeracao.vue';
import { ESTADO } from '../../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    anosLectivos: { type: Array, required: true },
    anoLectivoId: { type: [Number, String], default: null },
    turmas: { type: Array, required: true },
    niveis: { type: Array, required: true },
    planos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    podeIncluirAnteriores: { type: Boolean, default: false },
    resultado: { type: Object, default: null }, // flash.geracao_propinas depois de confirmar
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const PRE_VISUALIZAR = '/financeiro/propinas/geracao/pre-visualizar';
const AMBITOS = [
    { value: 'aluno', label: 'Um aluno (nº de estudante)' },
    { value: 'turma', label: 'Uma turma' },
    { value: 'nivel', label: 'Um nível académico' },
    { value: 'plano', label: 'Todas as turmas de um plano' },
    { value: 'matricula', label: 'Uma matrícula' },
];

const vazio = () => ({
    ano_lectivo_id: '',
    ambito: 'aluno',
    turma_id: '',
    nivel_academico_id: '',
    plano_propina_id: '',
    matricula_numero: '',
    aluno_numero: '',
    incluir_periodos_anteriores: false,
    motivo_geracao: '',
    ate: '',
});

const form = reactive(vazio());
const previsao = ref(null);
const aCalcular = ref(false);
const errosPrevia = ref({});

watch(() => props.show, (show) => {
    if (!show) return;
    Object.assign(form, vazio(), { ano_lectivo_id: Number(props.anoLectivoId) || props.anosLectivos[0]?.id || '' });
    previsao.value = null;
    errosPrevia.value = {};
});

// Mudar um parâmetro invalida a pré-visualização: nunca se confirma algo diferente do que foi visto.
watch(form, () => {
    previsao.value = null;
}, { deep: true });

// Mudar o ano lectivo invalida as selecções que dependem dele.
watch(() => form.ano_lectivo_id, (novo, antigo) => {
    if (antigo === undefined || antigo === '' || novo === antigo) return;
    form.turma_id = '';
    form.plano_propina_id = '';
    previsao.value = null;
    errosPrevia.value = {};
});

const opcoesAno = computed(() => props.anosLectivos.map((a) => ({ value: a.id, label: a.nome })));
const opcoesTurma = computed(() => props.turmas
    .filter((t) => t.ano_lectivo_id === form.ano_lectivo_id)
    .map((t) => ({ value: t.id, label: t.rotulo })));
const opcoesNivel = computed(() => props.niveis.map((n) => ({ value: n.id, label: n.nome })));
const opcoesPlano = computed(() => [
    ...(form.ambito === 'plano' ? [] : [{ value: '', label: 'Qualquer plano aplicável' }]),
    ...props.planos
        .filter((p) => p.ano_lectivo_id === form.ano_lectivo_id && p.estado === ESTADO.ATIVO)
        .map((p) => ({ value: p.id, label: p.nome })),
]);

const erro = (campo) => errosPrevia.value[campo] ?? props.errors[campo];

function payload() {
    const dados = { ano_lectivo_id: form.ano_lectivo_id, ambito: form.ambito };

    if (form.ambito === 'turma') dados.turma_id = form.turma_id;
    if (form.ambito === 'nivel') dados.nivel_academico_id = form.nivel_academico_id;
    if (form.ambito === 'aluno') dados.aluno_numero = form.aluno_numero.trim();
    if (form.ambito === 'matricula') dados.matricula_numero = form.matricula_numero.trim();
    if (form.plano_propina_id !== '') dados.plano_propina_id = form.plano_propina_id;

    if (form.incluir_periodos_anteriores) {
        dados.incluir_periodos_anteriores = true;
        dados.motivo_geracao = form.motivo_geracao;
    }

    if ((form.ate ?? '').trim() !== '') {
        const [ano, mes] = form.ate.split('-');
        dados.ate_ano = Number(ano);
        dados.ate_mes = Number(mes);
    }

    return dados;
}

async function preVisualizar() {
    aCalcular.value = true;
    errosPrevia.value = {};

    try {
        const { data } = await axios.post(PRE_VISUALIZAR, payload());
        previsao.value = data.resultado;
    } catch (falha) {
        const estado = falha.response?.status;

        if (estado === 422) {
            errosPrevia.value = Object.fromEntries(Object.entries(falha.response.data.errors ?? {}).map(([campo, mensagens]) => [campo, mensagens[0]]));
            toast.error(Object.values(errosPrevia.value)[0] ?? 'Verifique os dados.');
        } else if (estado === 403) {
            toast.error('Não tem permissão para esta geração.');
        } else {
            toast.error('Não foi possível pré-visualizar. Tente de novo.');
        }
    } finally {
        aCalcular.value = false;
    }
}

const aConfirmarGeracao = ref(false); // modal «Gerar estas propinas?» antes de gravar

const mensagemDeConfirmacao = computed(() => {
    const totais = previsao.value?.totais ?? { criadas: 0, matriculas: 0 };
    const multas = previsao.value?.inclui_multas_estimadas ? ' As multas por atraso devidas serão aplicadas.' : '';

    return `Gerar ${totais.criadas} propina(s) para ${totais.matriculas} matrícula(s)? As propinas ficam registadas e só podem ser corrigidas cancelando-as.${multas}`;
});

function confirmarNoModal() {
    aConfirmarGeracao.value = false;
    confirmar();
}

function confirmar() {
    emit('confirmar', payload());
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6">
                <h3 class="mb-5">Gerar propinas</h3>

                <template v-if="resultado">
                    <div v-if="resultado.totais.criadas > 0" class="text-center mb-4">
                        <img src="/images/mosi/mosi-parabens.svg" width="90" alt="" />
                    </div>
                    <ResultadoGeracao :resultado="resultado" :moeda="moeda" />
                    <div class="text-end mt-5">
                        <button type="button" class="btn btn-primary" @click="emit('cancelar')">Fechar</button>
                    </div>
                </template>

                <template v-else-if="previsao">
                    <div class="alert alert-warning py-3 fs-7 mb-5">
                        Pré-visualização: <strong>nada foi gravado</strong>. Ao confirmar, tudo é recalculado e gravado matrícula a matrícula;
                        o resultado final pode diferir se alguém alterar matrículas ou planos entretanto.
                    </div>
                    <ResultadoGeracao :resultado="previsao" :moeda="moeda" />
                    <div v-if="Object.keys(props.errors).length" class="alert alert-danger py-3 fs-7 mt-5 mb-0">
                        <div v-for="(mensagem, campo) in props.errors" :key="campo">{{ mensagem }}</div>
                    </div>
                    <div class="text-end mt-5">
                        <button type="button" class="btn btn-light me-2" :disabled="processing" @click="previsao = null">Voltar</button>
                        <button type="button" class="btn btn-primary" :disabled="processing || previsao.totais.criadas === 0" @click="aConfirmarGeracao = true">
                            {{ processing ? 'A gerar…' : `Confirmar e gerar ${previsao.totais.criadas} propina(s)` }}
                        </button>
                    </div>
                </template>

                <form v-else @submit.prevent="preVisualizar">
                    <p class="text-muted fs-6">
                        Gera, para as matrículas <strong>activas</strong> do âmbito, as propinas em falta pelo plano que se aplica a cada mês.
                        Os períodos já cobertos são ignorados. Nada é gravado antes de confirmar.
                    </p>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ano lectivo</label>
                            <SelectSolid v-model="form.ano_lectivo_id" :options="opcoesAno" />
                            <div v-if="erro('ano_lectivo_id')" class="text-danger fs-7 mt-1">{{ erro('ano_lectivo_id') }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Para quem</label>
                            <SelectSolid v-model="form.ambito" :options="AMBITOS" />
                            <div v-if="erro('ambito')" class="text-danger fs-7 mt-1">{{ erro('ambito') }}</div>
                        </div>

                        <div v-if="form.ambito === 'turma'" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Turma</label>
                            <SelectSolid v-model="form.turma_id" :options="opcoesTurma" searchable />
                            <div v-if="erro('turma_id')" class="text-danger fs-7 mt-1">{{ erro('turma_id') }}</div>
                        </div>
                        <div v-if="form.ambito === 'nivel'" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nível académico</label>
                            <SelectSolid v-model="form.nivel_academico_id" :options="opcoesNivel" searchable />
                            <div v-if="erro('nivel_academico_id')" class="text-danger fs-7 mt-1">{{ erro('nivel_academico_id') }}</div>
                        </div>
                        <div v-if="form.ambito === 'aluno'" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nº de estudante</label>
                            <input v-model="form.aluno_numero" type="text" class="form-control form-control-solid" maxlength="50" />
                            <div v-if="erro('aluno_numero')" class="text-danger fs-7 mt-1">{{ erro('aluno_numero') }}</div>
                        </div>
                        <div v-if="form.ambito === 'matricula'" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nº de registo da matrícula</label>
                            <input v-model="form.matricula_numero" type="text" class="form-control form-control-solid" maxlength="50" />
                            <div v-if="erro('matricula_numero')" class="text-danger fs-7 mt-1">{{ erro('matricula_numero') }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Até à data</label>
                            <input v-model="form.ate" type="date" class="form-control form-control-solid" />
                            <div class="text-muted fs-7 mt-1">Opcional: gera só as propinas dos meses até esta data (o mês da data conta por inteiro).</div>
                            <div v-if="erro('ate_mes')" class="text-danger fs-7 mt-1">{{ erro('ate_mes') }}</div>
                        </div>

                        <div class="col-md-6 fv-row mb-7">
                            <label :class="['fw-semibold fs-6 mb-2', { required: form.ambito === 'plano' }]">Plano de propina</label>
                            <SelectSolid v-model="form.plano_propina_id" :options="opcoesPlano" />
                            <div class="text-muted fs-7 mt-1">
                                {{ form.ambito === 'plano' ? 'As turmas a que este plano se aplica em algum mês.' : 'Opcional: com um plano escolhido, os períodos de outros planos ficam de fora.' }}
                            </div>
                            <div v-if="erro('plano_propina_id')" class="text-danger fs-7 mt-1">{{ erro('plano_propina_id') }}</div>
                        </div>
                    </div>

                    <div v-if="podeIncluirAnteriores" class="bg-body-secondary rounded p-4 mb-7">
                        <div class="form-check form-switch form-check-custom interruptor-visivel">
                            <input id="incluir_periodos_anteriores" v-model="form.incluir_periodos_anteriores" class="form-check-input" type="checkbox" />
                            <label for="incluir_periodos_anteriores" class="form-check-label fw-semibold text-gray-900">
                                Incluir períodos anteriores ao mês da matrícula (geração retroactiva)
                            </label>
                        </div>
                        <div class="form-text ms-12">Gera também as propinas dos meses anteriores à matrícula do aluno. Exige um motivo.</div>
                        <template v-if="form.incluir_periodos_anteriores">
                            <textarea v-model="form.motivo_geracao" class="form-control form-control-solid mt-3" rows="2" maxlength="500" placeholder="Motivo (obrigatório)"></textarea>
                            <div v-if="erro('motivo_geracao')" class="text-danger fs-7 mt-1">{{ erro('motivo_geracao') }}</div>
                        </template>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="aCalcular" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="aCalcular">{{ aCalcular ? 'A calcular…' : 'Pré-visualizar' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <ConfirmModal
        :show="aConfirmarGeracao"
        titulo="Gerar propinas"
        :mensagem="mensagemDeConfirmacao"
        texto-confirmar="Gerar propinas"
        variante="primary"
        :processando="processing"
        @confirmar="confirmarNoModal"
        @cancelar="aConfirmarGeracao = false"
    />
</template>

<style scoped>
/* Interruptor bem visível nos dois temas: contorno forte quando desligado, verde quando ligado. */
.interruptor-visivel .form-check-input {
    width: 2.75rem;
    height: 1.5rem;
    border: 2px solid #64748B !important;
    background-color: transparent !important;
}
.interruptor-visivel .form-check-input:checked {
    background-color: #166534 !important;
    border-color: #166534 !important;
}
</style>
