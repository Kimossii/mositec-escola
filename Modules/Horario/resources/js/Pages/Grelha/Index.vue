<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import GrelhaSemanal from '../../Components/GrelhaSemanal.vue';
import EntradaFormModal from '../../Components/EntradaFormModal.vue';
import EncerrarEntradaModal from '../../Components/EncerrarEntradaModal.vue';
import { formatarDia, hojeIso } from '../../Support/grelha';

const props = defineProps({
    vista: { type: String, default: 'turma' }, // 'turma' | 'docente' | 'sala'
    data: { type: String, required: true }, // data a que a grelha se refere (AAAA-MM-DD)
    grelha: { type: Object, default: null }, // { tempos, entradas, disciplinas? } ou null sem escolha
    opcoes: { type: Object, required: true }, // { turmas, salas, docentes }
    filtros: { type: Object, default: () => ({}) },
});
defineOptions({ layout: AppLayout });

const URL_BASE = '/grelha-horaria';
const id = (v) => (v === undefined || v === null || v === '' ? '' : Number(v));

const VISTAS = [
    { valor: 'turma', texto: 'Por turma', icone: 'ki-people', paths: 5 },
    { valor: 'docente', texto: 'Por professor', icone: 'ki-teacher', paths: 2 },
    { valor: 'sala', texto: 'Por sala', icone: 'ki-home-2', paths: 2 },
];

const estado = reactive({
    vista: props.vista,
    turma_id: id(props.filtros.turma_id),
    docente_id: id(props.filtros.docente_id),
    sala_id: id(props.filtros.sala_id),
    data: props.data,
});

const opcoesTurma = computed(() => props.opcoes.turmas.map((t) => ({ value: t.id, label: t.rotulo })));
const opcoesDocente = computed(() => props.opcoes.docentes.map((d) => ({ value: d.id, label: d.nome })));
const opcoesSala = computed(() => props.opcoes.salas.map((s) => ({ value: s.id, label: s.nome })));
// A turma da grelha mostrada (não a do selector, que muda antes de o servidor responder).
const turmaEscolhida = computed(() => props.opcoes.turmas.find((t) => t.id === Number(props.filtros.turma_id)) ?? null);
// Só a vista por turma se edita: nas outras a grelha é só consulta.
const editavel = computed(() => props.vista === 'turma' && !!props.grelha);
const hoje = hojeIso();
const dataNoPassado = computed(() => props.data < hoje);

const parametros = () => {
    const todos = { vista: estado.vista, data: estado.data, turma_id: estado.turma_id, docente_id: estado.docente_id, sala_id: estado.sala_id };
    return Object.fromEntries(Object.entries(todos).filter(([, v]) => v !== ''));
};

let debounceId = null;
watch(estado, () => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(URL_BASE, parametros(), { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

// ── Modais ──
const modal = ref(null); // 'form' | 'encerrar'
const entradaAlvo = ref(null);
const celula = ref(null);
const processing = ref(false);
const errors = ref({});

function abrir(tipo, entrada = null, cel = null) {
    errors.value = {};
    entradaAlvo.value = entrada;
    celula.value = cel;
    modal.value = tipo;
}
const fechar = () => { modal.value = null; };

function enviar(metodo, url, dados, sucesso) {
    processing.value = true;
    errors.value = {};
    router[metodo](url, dados, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(sucesso);
            fechar();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => { processing.value = false; },
    });
}

const guardar = (dados) => (entradaAlvo.value
    ? enviar('put', `${URL_BASE}/${entradaAlvo.value.id}`, dados, 'Horário actualizado.')
    : enviar('post', URL_BASE, dados, 'Aula adicionada ao horário.'));
const encerrar = (dados) => enviar('patch', `${URL_BASE}/${entradaAlvo.value.id}/encerrar`, dados, 'Aula encerrada no horário.');

const paraEliminar = ref(null);
const eliminando = ref(false);

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`${URL_BASE}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Aula removida do horário.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-6">
            <h1 class="fs-2 fw-bold mb-0">Grelha de horários</h1>
            <div class="d-flex align-items-center gap-2">
                <label class="fw-semibold fs-7 text-muted mb-0" for="grelha-data">Em vigor a</label>
                <input id="grelha-data" v-model="estado.data" type="date" class="form-control form-control-solid form-control-sm w-150px" />
            </div>
        </div>

        <div class="d-flex mb-6">
            <div class="d-inline-flex p-1 rounded bg-body-secondary" role="group" aria-label="Vista da grelha">
                <button
                    v-for="o in VISTAS"
                    :key="o.valor"
                    type="button"
                    class="btn btn-sm d-flex align-items-center px-4 border-0"
                    :class="estado.vista === o.valor ? 'btn-success shadow-sm' : 'btn-transparent text-gray-600'"
                    :aria-pressed="estado.vista === o.valor"
                    @click="estado.vista = o.valor"
                >
                    <i class="ki-duotone fs-4 me-2" :class="o.icone"><span v-for="n in o.paths" :key="n" :class="`path${n}`"></span></i>{{ o.texto }}
                </button>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body py-4">
                <div class="row g-4 align-items-end">
                    <div v-if="estado.vista === 'turma'" class="col-12 col-md-6 col-xl-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Turma</label>
                        <SelectSolid v-model="estado.turma_id" :options="opcoesTurma" placeholder="Seleccione a turma" searchable />
                    </div>
                    <div v-else-if="estado.vista === 'docente'" class="col-12 col-md-6 col-xl-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Professor</label>
                        <SelectSolid v-model="estado.docente_id" :options="opcoesDocente" placeholder="Seleccione o professor" searchable />
                    </div>
                    <div v-else class="col-12 col-md-6 col-xl-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Sala</label>
                        <SelectSolid v-model="estado.sala_id" :options="opcoesSala" placeholder="Seleccione a sala" searchable />
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!grelha" class="card border-0 bg-body-secondary">
            <div class="card-body d-flex align-items-center gap-3 py-5">
                <i class="ki-duotone ki-information-5 fs-2x text-info"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                <div class="text-muted">Escolha {{ estado.vista === 'turma' ? 'uma turma' : estado.vista === 'docente' ? 'um professor' : 'uma sala' }} para ver o horário.</div>
            </div>
        </div>

        <template v-else>
            <div v-if="dataNoPassado" class="alert alert-warning py-3 px-4 fs-7 mb-4">
                A mostrar o horário que estava em vigor a {{ formatarDia(data) }}. As alterações criam uma nova versão e só se aplicam a partir de hoje.
            </div>
            <GrelhaSemanal
                :tempos="grelha.tempos"
                :entradas="grelha.entradas"
                :editavel="editavel"
                :mostrar-turma="vista !== 'turma'"
                :hoje="hoje"
                @criar="(c) => abrir('form', null, c)"
                @editar="(e) => abrir('form', e)"
                @encerrar="(e) => abrir('encerrar', e)"
                @eliminar="(e) => (paraEliminar = e)"
            />
        </template>

        <EntradaFormModal
            :show="modal === 'form'"
            :entrada="entradaAlvo"
            :celula="celula"
            :turma="turmaEscolhida"
            :tempos="grelha?.tempos ?? []"
            :disciplinas="grelha?.disciplinas ?? []"
            :salas="opcoes.salas"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fechar"
        />
        <EncerrarEntradaModal
            :show="modal === 'encerrar'"
            :entrada="entradaAlvo"
            :processing="processing"
            :errors="errors"
            @submit="encerrar"
            @cancelar="fechar"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar aula do horário"
            :mensagem="`Eliminar ${paraEliminar?.disciplina_nome} do horário? Só é possível porque ainda não começou.`"
            texto-confirmar="Eliminar"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
