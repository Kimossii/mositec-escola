<script setup>
import { computed, reactive, ref, watch } from 'vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { unidadesMenoresParaDecimal } from '../../Support/dinheiro';

const OUTRA = 0;
const AVISO_PLANO_GERAL = 'Este plano não tem alvos definidos e será aplicado a todas as turmas do ano lectivo. Confirme que pretende criar um plano geral.';

const props = defineProps({
    show: { type: Boolean, default: false },
    plano: { type: Object, default: null },
    anosLectivos: { type: Array, required: true },
    niveis: { type: Array, required: true },
    cursos: { type: Array, required: true },
    turnos: { type: Array, required: true },
    turmas: { type: Array, required: true },
    periodicidades: { type: Array, required: true },
    moeda: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const placeholderValor = computed(() => props.moeda.decimais === 0
    ? 'ex: 25000'
    : 'ex: 25000 ou 25000,' + '50'.padEnd(props.moeda.decimais, '0').slice(0, props.moeda.decimais));

const MESES = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
].map((label, indice) => ({ value: indice + 1, label }));

const form = reactive({
    ano_lectivo_id: '',
    nome: '',
    descricao: '',
    periodicidade: 1,
    intervalo_meses: '',
    valor: '',
    mes_inicio: 9,
    mes_fim: 6,
    alvos: [],
});

const opcoesAno = computed(() => props.anosLectivos.map((a) => ({ value: a.id, label: a.nome })));
const opcoesNivel = computed(() => [{ value: '', label: 'Todos os níveis' }, ...props.niveis.map((n) => ({ value: n.id, label: n.nome }))]);
const opcoesCurso = computed(() => [{ value: '', label: 'Todos os cursos' }, ...props.cursos.map((c) => ({ value: c.id, label: c.nome }))]);
const opcoesTurno = computed(() => [{ value: '', label: 'Todos os turnos' }, ...props.turnos.map((t) => ({ value: t.id, label: t.nome }))]);
const opcoesTurma = computed(() => [
    { value: '', label: 'Nenhuma (usar nível/curso/turno)' },
    ...props.turmas.filter((t) => t.ano_lectivo_id === form.ano_lectivo_id).map((t) => ({ value: t.id, label: t.nome })),
]);

function textoDoAlvo(a) {
    if (a.turma_id) return `Turma ${a.turma_nome}`;

    return [a.curso_nome, a.nivel_nome, a.turno_nome].filter(Boolean).join(' · ');
}

const alvosEliminados = computed(() => form.alvos.filter((a) => a.eliminado));

watch(() => props.show, (show) => {
    if (!show) return;
    form.ano_lectivo_id = props.plano?.ano_lectivo_id ?? props.anosLectivos[0]?.id ?? '';
    form.nome = props.plano?.nome ?? '';
    form.descricao = props.plano?.descricao ?? '';
    form.periodicidade = props.plano?.periodicidade ?? 1;
    form.intervalo_meses = props.plano && props.plano.periodicidade === OUTRA ? props.plano.intervalo_meses : '';
    form.valor = props.plano ? unidadesMenoresParaDecimal(props.plano.valor, props.moeda) : '';
    form.mes_inicio = props.plano?.mes_inicio ?? 9;
    form.mes_fim = props.plano?.mes_fim ?? 6;
    form.alvos = (props.plano?.alvos ?? []).map((a) => ({
        eliminado: a.eliminado === true,
        descricao: textoDoAlvo(a),
        nivel_academico_id: a.nivel_academico_id ?? '',
        curso_id: a.curso_id ?? '',
        turno_id: a.turno_id ?? '',
        turma_id: a.turma_id ?? '',
    }));
});

// Ao mudar o ano lectivo (criação), as turmas escolhidas que já não existem nas opções são limpas.
watch(() => form.ano_lectivo_id, () => {
    if (props.plano) return;
    const validas = new Set(opcoesTurma.value.map((o) => o.value));
    form.alvos.forEach((alvo) => {
        if (alvo.turma_id !== '' && !validas.has(alvo.turma_id)) alvo.turma_id = '';
    });
});

const erroAlvos = computed(() => {
    if (props.errors.alvos) return props.errors.alvos;
    const chave = Object.keys(props.errors).find((k) => k.startsWith('alvos.'));
    return chave ? props.errors[chave] : '';
});

function adicionarAlvo() {
    form.alvos.push({ eliminado: false, nivel_academico_id: '', curso_id: '', turno_id: '', turma_id: '' });
}

function removerAlvo(indice) {
    form.alvos.splice(indice, 1);
}

// Uma turma específica é exclusiva: escolhê-la limpa nível, curso e turno, e vice-versa.
function definirCampo(alvo, campo, valor) {
    alvo[campo] = valor;

    if (valor === '') return;

    if (campo === 'turma_id') {
        alvo.nivel_academico_id = '';
        alvo.curso_id = '';
        alvo.turno_id = '';
    } else {
        alvo.turma_id = '';
    }
}

const vazioParaNulo = (valor) => (valor === '' ? null : valor);

const confirmarGeralAberto = ref(false);

const alvosPreenchidos = (alvos) => alvos.filter((a) => !a.eliminado && ['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'].some((c) => a[c] !== ''));

// Pede confirmação ao ficar sem alvos, excepto ao editar um plano que já era geral.
function submeter() {
    const jaEraGeral = props.plano && (props.plano.alvos ?? []).length === 0;

    if (alvosPreenchidos(form.alvos).length === 0 && !jaEraGeral) {
        confirmarGeralAberto.value = true;
        return;
    }

    enviar(false);
}

function confirmarGeral() {
    confirmarGeralAberto.value = false;
    enviar(true);
}

function enviar(confirmado) {
    const payload = {
        nome: form.nome,
        descricao: form.descricao,
        periodicidade: form.periodicidade,
        valor: form.valor,
        mes_inicio: form.mes_inicio,
        mes_fim: form.mes_fim,
        alvos: form.alvos.map((a) => ({
            nivel_academico_id: vazioParaNulo(a.nivel_academico_id),
            curso_id: vazioParaNulo(a.curso_id),
            turno_id: vazioParaNulo(a.turno_id),
            turma_id: vazioParaNulo(a.turma_id),
        })),
    };

    if (confirmado) payload.confirmar_plano_geral = true;
    if (form.periodicidade === OUTRA) payload.intervalo_meses = form.intervalo_meses;
    if (!props.plano) payload.ano_lectivo_id = form.ano_lectivo_id;

    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ plano ? 'Editar Plano de Propina' : 'Novo Plano de Propina' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Propina Informática 11.ª" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ano Lectivo</label>
                            <input v-if="plano" type="text" class="form-control form-control-solid" :value="plano.ano_lectivo_nome" disabled />
                            <SelectSolid v-else v-model="form.ano_lectivo_id" :options="opcoesAno" />
                            <div class="form-text" v-if="plano">O ano lectivo não pode ser alterado.</div>
                            <div class="text-danger fs-7 mt-1" v-if="errors.ano_lectivo_id">{{ errors.ano_lectivo_id }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Periodicidade</label>
                            <SelectSolid v-model="form.periodicidade" :options="periodicidades" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.periodicidade">{{ errors.periodicidade }}</div>
                        </div>
                        <div v-if="form.periodicidade === OUTRA" class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Intervalo (meses)</label>
                            <input v-model.number="form.intervalo_meses" type="number" min="1" max="12" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.intervalo_meses">{{ errors.intervalo_meses }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Valor por período ({{ moeda.simbolo }})</label>
                            <input v-model="form.valor" type="text" inputmode="decimal" class="form-control form-control-solid" :placeholder="placeholderValor" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.valor">{{ errors.valor }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Mês de início</label>
                            <SelectSolid v-model="form.mes_inicio" :options="MESES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.mes_inicio">{{ errors.mes_inicio }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Mês de fim</label>
                            <SelectSolid v-model="form.mes_fim" :options="MESES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.mes_fim">{{ errors.mes_fim }}</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end mb-7">
                            <div class="form-text">
                                O período pode atravessar o ano civil (ex.: Setembro → Junho). Para mudar o preço a meio do ano,
                                crie outro plano com os mesmos alvos e o período seguinte.
                            </div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="2"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="fw-semibold fs-6">Aplica-se a</label>
                            <button type="button" class="btn btn-sm btn-light-primary" @click="adicionarAlvo">Adicionar alvo</button>
                        </div>
                        <div v-if="form.alvos.length === 0" class="bg-body-secondary rounded fs-7 text-muted px-4 py-3 mb-2">
                            Sem alvos: plano geral, aplica-se a todas as turmas do ano lectivo.
                        </div>
                        <div v-else class="text-muted fs-7 mb-2">
                            Vence o alvo mais específico: turma &gt; mais campos preenchidos &gt; curso &gt; nível &gt; turno. Uma turma específica não se combina com os outros campos.
                        </div>
                        <div v-if="alvosEliminados.length" class="alert alert-warning d-flex flex-column fs-7 py-3 mb-3">
                            <span>
                                {{ alvosEliminados.length === 1 ? 'Um alvo deste plano foi eliminado' : alvosEliminados.length + ' alvos deste plano foram eliminados' }}
                                e será removido ao guardar. Se todos os alvos tiverem sido eliminados, escolha novos alvos ou desactive o plano.
                            </span>
                        </div>
                        <div v-for="(alvo, indice) in form.alvos" :key="indice" class="row g-3 align-items-center mb-2">
                          <template v-if="alvo.eliminado">
                            <div class="col-md-10">
                                <div class="form-control form-control-solid bg-body-secondary text-muted text-decoration-line-through">{{ alvo.descricao }}</div>
                            </div>
                            <div class="col-md-2 text-end"><span class="badge badge-light-warning">Eliminado</span></div>
                          </template>
                          <template v-else>
                            <div class="col-md-2"><SelectSolid :model-value="alvo.nivel_academico_id" :options="opcoesNivel" @update:model-value="(v) => definirCampo(alvo, 'nivel_academico_id', v)" /></div>
                            <div class="col-md-3"><SelectSolid :model-value="alvo.curso_id" :options="opcoesCurso" @update:model-value="(v) => definirCampo(alvo, 'curso_id', v)" /></div>
                            <div class="col-md-2"><SelectSolid :model-value="alvo.turno_id" :options="opcoesTurno" @update:model-value="(v) => definirCampo(alvo, 'turno_id', v)" /></div>
                            <div class="col-md-3"><SelectSolid :model-value="alvo.turma_id" :options="opcoesTurma" searchable @update:model-value="(v) => definirCampo(alvo, 'turma_id', v)" /></div>
                            <div class="col-md-2 text-end">
                                <button type="button" class="btn btn-sm btn-light-danger" @click="removerAlvo(indice)">Remover</button>
                            </div>
                          </template>
                        </div>
                        <div class="text-danger fs-7 mt-1" v-if="erroAlvos">{{ erroAlvos }}</div>
                        <div class="text-danger fs-7 mt-1" v-if="errors.confirmar_plano_geral">{{ errors.confirmar_plano_geral }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmModal
            :show="confirmarGeralAberto"
            titulo="Criar plano geral"
            :mensagem="AVISO_PLANO_GERAL"
            texto-confirmar="Confirmar plano geral"
            @confirmar="confirmarGeral"
            @cancelar="confirmarGeralAberto = false"
        />
    </div>
</template>
