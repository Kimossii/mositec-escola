<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import AnoLectivoStatusBadge from '../Components/AnoLectivoStatusBadge.vue';
import AnoLectivoFormModal from '../Components/AnoLectivoFormModal.vue';
import PeriodoTable from '../Components/Periodos/PeriodoTable.vue';
import PeriodoFormModal from '../Components/Periodos/PeriodoFormModal.vue';
import AnoLectivoCalendario from '../Components/Calendario/AnoLectivoCalendario.vue';
import EventoFormModal from '../Components/Calendario/EventoFormModal.vue';

const props = defineProps({
    anoLectivo: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const abaAtual = ref('periodos'); // 'periodos' | 'calendario'

// ========== Editar Ano Lectivo ==========
const editModalAberto = ref(false);
const editProcessing = ref(false);
const editErrors = ref({});

function abrirEdicaoAnoLectivo() {
    editErrors.value = {};
    editModalAberto.value = true;
}

function fecharEdicaoAnoLectivo() {
    editModalAberto.value = false;
}

function guardarAnoLectivo(payload) {
    editProcessing.value = true;
    editErrors.value = {};
    router.put(`/ano-lectivos/${props.anoLectivo.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Ano Lectivo atualizado com sucesso.');
            fecharEdicaoAnoLectivo();
        },
        onError: (erros) => {
            editErrors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível atualizar o Ano Lectivo.');
        },
        onFinish: () => {
            editProcessing.value = false;
        },
    });
}

// ========== Períodos ==========
const periodoModalAberto = ref(false);
const periodoEmEdicao = ref(null);
const periodoProcessing = ref(false);
const periodoErrors = ref({});

function abrirCriacaoPeriodo() {
    periodoEmEdicao.value = null;
    periodoErrors.value = {};
    periodoModalAberto.value = true;
}

function abrirEdicaoPeriodo(periodo) {
    periodoEmEdicao.value = periodo;
    periodoErrors.value = {};
    periodoModalAberto.value = true;
}

function fecharPeriodoModal() {
    periodoModalAberto.value = false;
}

function guardarPeriodo(payload) {
    periodoProcessing.value = true;
    periodoErrors.value = {};

    const url = periodoEmEdicao.value
        ? `/periodos/${periodoEmEdicao.value.id}`
        : `/ano-lectivos/${props.anoLectivo.id}/periodos`;
    const metodo = periodoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(periodoEmEdicao.value ? 'Período atualizado com sucesso.' : 'Período criado com sucesso.');
            fecharPeriodoModal();
        },
        onError: (erros) => {
            periodoErrors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o período.');
        },
        onFinish: () => {
            periodoProcessing.value = false;
        },
    });
}

const periodoParaEliminar = ref(null);
const eliminandoPeriodo = ref(false);

function pedirEliminacaoPeriodo(periodo) {
    periodoParaEliminar.value = periodo;
}

function cancelarEliminacaoPeriodo() {
    periodoParaEliminar.value = null;
}

function confirmarEliminacaoPeriodo() {
    eliminandoPeriodo.value = true;
    router.delete(`/periodos/${periodoParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Período eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o período.'),
        onFinish: () => {
            eliminandoPeriodo.value = false;
            periodoParaEliminar.value = null;
        },
    });
}

// ========== Eventos do Calendário ==========
const eventoModalAberto = ref(false);
const eventoEmEdicao = ref(null);
const eventoDataInicial = ref(null);
const eventoProcessing = ref(false);
const eventoErrors = ref({});

function abrirCriacaoEvento(dataISO) {
    eventoEmEdicao.value = null;
    eventoDataInicial.value = dataISO;
    eventoErrors.value = {};
    eventoModalAberto.value = true;
}

function abrirEdicaoEvento(evento) {
    eventoEmEdicao.value = evento;
    eventoDataInicial.value = null;
    eventoErrors.value = {};
    eventoModalAberto.value = true;
}

function fecharEventoModal() {
    eventoModalAberto.value = false;
}

function guardarEvento(payload) {
    eventoProcessing.value = true;
    eventoErrors.value = {};

    const url = eventoEmEdicao.value
        ? `/eventos-calendario/${eventoEmEdicao.value.id}`
        : `/ano-lectivos/${props.anoLectivo.id}/eventos-calendario`;
    const metodo = eventoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(eventoEmEdicao.value ? 'Evento atualizado com sucesso.' : 'Evento criado com sucesso.');
            fecharEventoModal();
        },
        onError: (erros) => {
            eventoErrors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o evento.');
        },
        onFinish: () => {
            eventoProcessing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="card mb-6">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="fs-2 fw-bold mb-2">{{ anoLectivo.nome }}</h1>
                    <div class="text-muted fs-6 mb-2">{{ anoLectivo.data_inicio }} — {{ anoLectivo.data_fim }}</div>
                    <AnoLectivoStatusBadge :estado="anoLectivo.estado" :estado-descricao="anoLectivo.estado_descricao" />
                </div>
                <button v-if="can('ano-lectivo.editar')" class="btn btn-light-primary" @click="abrirEdicaoAnoLectivo">Editar</button>
            </div>
        </div>

        <ul class="nav nav-line-tabs nav-line-tabs-2x fs-6 mb-8">
            <li class="nav-item">
                <a class="nav-link" :class="{ active: abaAtual === 'periodos' }" href="#" @click.prevent="abaAtual = 'periodos'">
                    Períodos
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: abaAtual === 'calendario' }" href="#" @click.prevent="abaAtual = 'calendario'">
                    Calendário Escolar
                </a>
            </li>
        </ul>

        <div v-if="abaAtual === 'periodos'" class="card">
            <div class="card-header d-flex justify-content-end align-items-center">
                <button v-if="can('ano-lectivo.criar')" class="btn btn-primary btn-sm" @click="abrirCriacaoPeriodo">Novo Período</button>
            </div>
            <div class="card-body p-0">
                <PeriodoTable
                    :periodos="anoLectivo.periodos"
                    :pode-editar="can('ano-lectivo.editar')"
                    :pode-eliminar="can('ano-lectivo.eliminar')"
                    @editar-periodo="abrirEdicaoPeriodo"
                    @eliminar-periodo="pedirEliminacaoPeriodo"
                />
            </div>
        </div>

        <div v-if="abaAtual === 'calendario'" class="card">
            <div class="card-header d-flex justify-content-end align-items-center">
                <button v-if="can('ano-lectivo.criar')" class="btn btn-primary btn-sm" @click="abrirCriacaoEvento(anoLectivo.data_inicio)">Novo Evento</button>
            </div>
            <div class="card-body">
                <AnoLectivoCalendario
                    :ano-lectivo="anoLectivo"
                    :eventos="anoLectivo.eventos_calendario"
                    :pode-criar="can('ano-lectivo.criar')"
                    :pode-editar="can('ano-lectivo.editar')"
                    @criar-evento="abrirCriacaoEvento"
                    @editar-evento="abrirEdicaoEvento"
                />
            </div>
        </div>

        <AnoLectivoFormModal
            :show="editModalAberto"
            :ano-lectivo="anoLectivo"
            :processing="editProcessing"
            :errors="editErrors"
            @submit="guardarAnoLectivo"
            @cancelar="fecharEdicaoAnoLectivo"
        />

        <PeriodoFormModal
            :show="periodoModalAberto"
            :periodo="periodoEmEdicao"
            :processing="periodoProcessing"
            :errors="periodoErrors"
            @submit="guardarPeriodo"
            @cancelar="fecharPeriodoModal"
        />

        <ConfirmModal
            :show="!!periodoParaEliminar"
            titulo="Eliminar Período"
            :mensagem="`Tem certeza que deseja eliminar o período ${periodoParaEliminar?.nome}?`"
            :processando="eliminandoPeriodo"
            @confirmar="confirmarEliminacaoPeriodo"
            @cancelar="cancelarEliminacaoPeriodo"
        />

        <EventoFormModal
            :show="eventoModalAberto"
            :evento="eventoEmEdicao"
            :data-inicial="eventoDataInicial"
            :processing="eventoProcessing"
            :errors="eventoErrors"
            @submit="guardarEvento"
            @cancelar="fecharEventoModal"
        />
    </div>
</template>
