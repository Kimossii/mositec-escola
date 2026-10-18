<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import PausaCobrancaFormModal from '../../Components/PausasCobranca/PausaCobrancaFormModal.vue';
import { dataCurta } from '../../Support/periodos';
import { rotuloMes } from '../../Support/pausas';

const BASE = '/financeiro/configuracao/pausas-cobranca';

const props = defineProps({
    pausas: { type: Array, required: true },
    anosLectivos: { type: Array, required: true },
    anoLectivoActualId: { type: [Number, String], default: null },
});
defineOptions({ layout: AppLayout });

const opcoesAno = computed(() => props.anosLectivos.map((a) => ({ value: a.id, label: a.nome })));

const anoLectivoId = ref(Number.isFinite(Number(props.anoLectivoActualId)) && props.anoLectivoActualId
    ? Number(props.anoLectivoActualId)
    : '');

function mudarAno(valor) {
    anoLectivoId.value = valor;
    router.get(BASE, { ano_lectivo_id: valor }, { preserveState: true, preserveScroll: true, replace: true });
}

function mesesTexto(pausa) {
    const isentos = pausa.meses.filter((m) => m.isento);
    const cobrados = pausa.meses.filter((m) => !m.isento);

    const partes = [];

    if (isentos.length) partes.push(`${isentos.length} isento(s)`);
    if (cobrados.length) partes.push(`${cobrados.length} cobrado(s)`);

    return partes.join(' · ');
}

function mesesDetalhe(pausa) {
    return pausa.meses.map((m) => `${rotuloMes(m.mes)} ${m.ano}${m.isento ? ' (isento)' : ''}`).join(', ');
}

const modalAberto = ref(false);
const pausaEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    pausaEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(pausa) {
    pausaEmEdicao.value = pausa;
    errors.value = {};
    modalAberto.value = true;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const edicao = pausaEmEdicao.value;
    const url = edicao ? `${BASE}/${edicao.id}` : BASE;

    router[edicao ? 'put' : 'post'](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Pausa de cobrança guardada.');
            modalAberto.value = false;
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const paraEliminar = ref(null);
const aProcessar = ref(false);

function confirmarEliminacao() {
    aProcessar.value = true;
    router.delete(`${BASE}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Pausa de cobrança eliminada.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Pausas de Cobrança</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Suspendem a cobrança de propinas num intervalo do ano lectivo. Uma pausa «não cobra» isenta os meses
                    tocados: as propinas desses meses não se geram e, se já existirem sem pagamentos, são canceladas.
                </p>
            </div>
            <div v-if="can('pausa-cobranca.criar')">
                <button class="btn btn-primary" @click="abrirCriacao">Nova Pausa</button>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                        <SelectSolid :model-value="anoLectivoId" :options="opcoesAno" @update:model-value="mudarAno" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Descrição</th>
                            <th class="min-w-125px">Período</th>
                            <th class="min-w-125px">Tipo</th>
                            <th class="min-w-200px">Meses</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="pausas.length === 0">
                            <td colspan="5" class="text-center text-muted py-6">Nenhuma pausa de cobrança neste ano lectivo.</td>
                        </tr>
                        <tr v-for="pausa in pausas" :key="pausa.id">
                            <td class="text-gray-800">{{ pausa.descricao }}</td>
                            <td>{{ dataCurta(pausa.data_inicio) }} → {{ dataCurta(pausa.data_fim) }}</td>
                            <td>
                                <span v-if="pausa.cobra_propina" class="badge badge-light-info">Cobra propina</span>
                                <span v-else class="badge badge-light-warning">Não cobra</span>
                            </td>
                            <td>
                                <span v-if="!pausa.cobra_propina && pausa.meses.length" :title="mesesDetalhe(pausa)">{{ mesesTexto(pausa) }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('pausa-cobranca.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(pausa)">
                                            <AcaoIcone acao="editar" class="me-2" /> Editar
                                        </a>
                                    </div>
                                    <div v-if="can('pausa-cobranca.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEliminar = pausa">
                                            <AcaoIcone acao="eliminar" class="me-2" /> Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <PausaCobrancaFormModal
            :show="modalAberto"
            :pausa="pausaEmEdicao"
            :anos-lectivos="anosLectivos"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="modalAberto = false"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar pausa de cobrança"
            :mensagem="`Eliminar a pausa «${paraEliminar?.descricao}»? Os meses deixam de estar isentos e as propinas voltam pelo fluxo normal de regeneração.`"
            :processando="aProcessar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
