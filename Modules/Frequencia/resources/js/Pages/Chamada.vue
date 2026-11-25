<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import Loader from '@/Components/Shared/Loader.vue';
import { can } from '@/Composables/usePermissoes';
import LinhaDeAluno from '../Components/LinhaDeAluno.vue';
import MotivoModal from '../Components/MotivoModal.vue';
import { PRESENCA, formatarDia } from '../Support/frequencia';

const props = defineProps({
    chamada: { type: Object, required: true }, // { aula, registos, editavel, fora_da_janela }
    janelaDias: { type: Number, default: 7 },
});
defineOptions({ layout: AppLayout });

const aula = computed(() => props.chamada.aula);
const naoLeccionada = computed(() => aula.value.estado === 2);
const URL_AULA = computed(() => `/frequencia/aulas/${aula.value.id}`);

// Cópia editável do estado guardado. Quando o servidor devolve a chamada (depois de guardar, justificar ou de um erro),
// só se repõe o que mudou no servidor: as marcações ainda por guardar mantêm-se.
const edicao = reactive({});
let base = {}; // o último estado guardado conhecido, por matrícula
watch(() => props.chamada.registos, (registos) => {
    const novoBase = {};
    for (const r of registos) {
        const servidor = { estado: r.estado, observacao: r.observacao ?? null };
        const anterior = base[r.matricula_id];
        const local = edicao[r.matricula_id];
        const pendente = local && anterior && (local.estado !== anterior.estado || (local.observacao ?? null) !== anterior.observacao);
        const servidorMudou = !anterior || servidor.estado !== anterior.estado || servidor.observacao !== anterior.observacao;
        if (!local || servidorMudou || !pendente) edicao[r.matricula_id] = { ...servidor };
        novoBase[r.matricula_id] = servidor;
    }
    for (const k of Object.keys(edicao)) if (!novoBase[k]) delete edicao[k];
    base = novoBase;
}, { immediate: true });

const alterado = computed(() => props.chamada.registos.some((r) => edicao[r.matricula_id].estado !== r.estado || (edicao[r.matricula_id].observacao ?? null) !== (r.observacao ?? null)));
const contagem = computed(() => ({
    presentes: Object.values(edicao).filter((e) => e.estado === PRESENCA.PRESENTE).length,
    faltas: Object.values(edicao).filter((e) => e.estado === PRESENCA.FALTA).length,
    atrasos: Object.values(edicao).filter((e) => e.estado === PRESENCA.ATRASO).length,
}));

const todosPresentes = () => { for (const k of Object.keys(edicao)) edicao[k] = { estado: PRESENCA.PRESENTE, observacao: null }; };

// Quem tem `editar` (professor) ou `corrigir-fora-prazo` (secretaria) mexe na chamada.
const podeMexer = () => can('frequencia.editar') || can('frequencia.corrigir-fora-prazo');

// ── Guardar ──
const processing = ref(false);
const errors = ref({});
const motivoCorreccao = ref('');

function guardar() {
    processing.value = true;
    errors.value = {};
    router.put(`${URL_AULA.value}/presencas`, {
        presencas: props.chamada.registos.map((r) => ({ matricula_id: r.matricula_id, estado: edicao[r.matricula_id].estado, observacao: edicao[r.matricula_id].estado === PRESENCA.PRESENTE ? null : (edicao[r.matricula_id].observacao || null) })),
        motivo: props.chamada.fora_da_janela ? motivoCorreccao.value : null,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Chamada guardada.'),
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => { processing.value = false; },
    });
}

// ── Modais (justificar, retirar, não leccionada) ──
const modal = ref(null); // 'justificar' | 'retirar' | 'nao-leccionada'
const alvo = ref(null);
const modalErrors = ref({});
const modalProcessing = ref(false);

function abrir(tipo, registo = null) {
    modalErrors.value = {};
    alvo.value = registo;
    modal.value = tipo;
}

function enviarModal(metodo, url, dados, sucesso) {
    modalProcessing.value = true;
    modalErrors.value = {};
    router[metodo](url, dados, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(sucesso);
            modal.value = null;
        },
        onError: (erros) => {
            modalErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => { modalProcessing.value = false; },
    });
}

const reabrir = () => enviarModal('patch', `${URL_AULA.value}/reabrir`, {}, 'Aula reaberta: as presenças voltam a contar.');

const submeterModal = (dados) => {
    if (modal.value === 'justificar') return enviarModal('post', `/frequencia/registos/${alvo.value.registo_id}/justificacao`, dados, 'Falta justificada.');
    if (modal.value === 'retirar') return enviarModal('delete', `/frequencia/registos/${alvo.value.registo_id}/justificacao`, dados, 'Justificação retirada.');
    return enviarModal('patch', `${URL_AULA.value}/nao-leccionada`, dados, 'Aula marcada como não leccionada.');
};
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-4"><BotaoVoltar href="/frequencia" /></div>

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ aula.disciplina_nome ?? 'Chamada do dia' }}</h1>
                <div class="text-muted fs-6">{{ aula.turma_rotulo }} · {{ formatarDia(aula.data) }}<span v-if="aula.horario_nome"> · {{ aula.horario_nome }}</span></div>
            </div>
            <button v-if="chamada.editavel && podeMexer()" type="button" class="btn btn-light-warning" @click="abrir('nao-leccionada')">Aula não leccionada</button>
        </div>

        <div v-if="naoLeccionada" class="alert alert-warning py-3 px-4 fs-7 mb-6">
            Aula marcada como não leccionada<span v-if="aula.motivo_nao_leccionada">: {{ aula.motivo_nao_leccionada }}</span>. Não conta para faltas.
            <button v-if="podeMexer()" type="button" class="btn btn-sm btn-light-warning ms-3" :disabled="modalProcessing" @click="reabrir">Reabrir aula</button>
        </div>
        <div v-else-if="!chamada.editavel" class="alert alert-info py-3 px-4 fs-7 mb-6">
            A janela de correcção desta chamada ({{ janelaDias }} dias) já passou. Peça à secretaria para a corrigir.
        </div>
        <div v-else-if="chamada.fora_da_janela" class="alert alert-warning py-3 px-4 fs-7 mb-6">
            Esta chamada está fora da janela de correcção ({{ janelaDias }} dias). Para guardar, indique o motivo.
        </div>

        <div class="card">
            <div class="card-body">
                <EstadoVazio v-if="!chamada.registos.length" titulo="Sem alunos nesta chamada" descricao="Nenhum aluno activo e inscrito nesta aula na data indicada." />

                <template v-else>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pb-3">
                        <div class="fs-7 text-muted">
                            <span class="text-success fw-bold">{{ contagem.presentes }}</span> presentes ·
                            <span class="text-danger fw-bold">{{ contagem.faltas }}</span> faltas ·
                            <span class="text-warning fw-bold">{{ contagem.atrasos }}</span> atrasos
                        </div>
                        <button v-if="chamada.editavel" type="button" class="btn btn-sm btn-light" @click="todosPresentes">Todos presentes</button>
                    </div>

                    <LinhaDeAluno
                        v-for="r in chamada.registos"
                        :key="r.matricula_id"
                        v-model="edicao[r.matricula_id]"
                        :registo="r"
                        :editavel="chamada.editavel"
                        @justificar="(x) => abrir('justificar', x)"
                        @retirar="(x) => abrir('retirar', x)"
                    />

                    <div v-if="chamada.editavel" class="mt-6">
                        <div v-if="chamada.fora_da_janela" class="mb-4">
                            <label class="required fw-semibold fs-6 mb-2">Motivo da correcção</label>
                            <textarea v-model="motivoCorreccao" class="form-control form-control-solid" rows="2" maxlength="500"></textarea>
                        </div>
                        <div class="text-danger fs-7 mb-3" v-if="errors.aula || errors.presencas || errors.motivo">{{ errors.aula ?? errors.presencas ?? errors.motivo }}</div>
                        <div class="text-end">
                            <button type="button" class="btn btn-success" :disabled="processing || !alterado" @click="guardar">
                                <span v-if="processing">A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                                <span v-else>Guardar chamada</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <MotivoModal
            :show="modal === 'justificar'"
            titulo="Justificar falta"
            :contexto="alvo?.nome"
            rotulo-motivo="Motivo da justificação"
            com-documento
            texto-confirmar="Justificar"
            :processing="modalProcessing"
            :errors="modalErrors"
            @submit="submeterModal"
            @cancelar="modal = null"
        />
        <MotivoModal
            :show="modal === 'retirar'"
            titulo="Retirar justificação"
            :contexto="alvo?.nome"
            rotulo-motivo="Motivo de a retirar"
            texto-confirmar="Retirar"
            perigo
            :processing="modalProcessing"
            :errors="modalErrors"
            @submit="submeterModal"
            @cancelar="modal = null"
        />
        <MotivoModal
            :show="modal === 'nao-leccionada'"
            titulo="Aula não leccionada"
            :contexto="`${aula.disciplina_nome ?? 'Chamada do dia'} · ${aula.turma_rotulo}`"
            rotulo-motivo="Motivo"
            texto-confirmar="Marcar"
            :processing="modalProcessing"
            :errors="modalErrors"
            @submit="submeterModal"
            @cancelar="modal = null"
        />
    </div>
</template>
