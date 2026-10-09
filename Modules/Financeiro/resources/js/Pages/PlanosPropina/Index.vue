<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import CopiarPlanosModal from '../../Components/PlanosPropina/CopiarPlanosModal.vue';
import PlanoPropinaFormModal from '../../Components/PlanosPropina/PlanoPropinaFormModal.vue';
import { ESTADO } from '../../Models/Estado';
import { formatarDinheiro } from '../../Support/dinheiro';

const BASE = '/financeiro/configuracao/planos-propina';
const MESES_CURTOS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

const props = defineProps({
    planos: { type: Object, required: true }, // paginador: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
    anosLectivos: { type: Array, required: true },
    niveis: { type: Array, required: true },
    cursos: { type: Array, required: true },
    turnos: { type: Array, required: true },
    turmas: { type: Array, required: true },
    periodicidades: { type: Array, required: true },
    moeda: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
]);
const opcoesAno = computed(() => [{ value: TODOS, label: 'Todos os anos lectivos' }, ...props.anosLectivos.map((a) => ({ value: a.id, label: a.nome }))]);

// 'todos' distingue "sem filtro" de "filtro ausente" (que aplica o ano lectivo activo no servidor).
const TODOS = 'todos';

const numeroOuVazio = (valor) => (valor !== undefined && valor !== null && valor !== '' ? Number(valor) : '');

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: numeroOuVazio(props.filtros.estado),
    ano_lectivo_id: Number.isFinite(Number(props.filtros.ano_lectivo_id)) && props.filtros.ano_lectivo_id ? Number(props.filtros.ano_lectivo_id) : TODOS,
});

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

function periodoTexto(plano) {
    return `${MESES_CURTOS[plano.mes_inicio - 1]} → ${MESES_CURTOS[plano.mes_fim - 1]}`;
}

function alvoTexto(alvo) {
    if (alvo.turma_id) return `Turma ${alvo.turma_nome}`;

    return [alvo.curso_nome, alvo.nivel_nome, alvo.turno_nome].filter(Boolean).join(' · ');
}

function alvosTexto(plano) {
    return plano.alvos.length === 0 ? 'Todas as turmas' : plano.alvos.map((a) => alvoTexto(a) + (a.eliminado ? ' (eliminado)' : '')).join('; ');
}

const temAlvoEliminado = (plano) => plano.alvos.some((a) => a.eliminado);

const modalAberto = ref(false);
const planoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    planoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(plano) {
    planoEmEdicao.value = plano;
    errors.value = {};
    modalAberto.value = true;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const edicao = planoEmEdicao.value;
    const url = edicao ? `${BASE}/${edicao.id}` : BASE;

    router[edicao ? 'put' : 'post'](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(edicao ? 'Plano de propina atualizado com sucesso.' : 'Plano de propina criado com sucesso.');
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

const copiaAberta = ref(false);
const copiaProcessing = ref(false);
const copiaErrors = ref({});
const resumoCopia = ref(null);
const page = usePage();

function abrirCopia() {
    copiaErrors.value = {};
    resumoCopia.value = null;
    copiaAberta.value = true;
}

function copiar(payload) {
    copiaProcessing.value = true;
    copiaErrors.value = {};

    router.post(`${BASE}/copiar`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            resumoCopia.value = page.props.flash?.copia_planos ?? { copiados: [], ignorados: [] };
            toast.success(page.props.flash?.success ?? 'Planos copiados.');
        },
        onError: (erros) => {
            copiaErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            copiaProcessing.value = false;
        },
    });
}

const paraEstado = ref(null);
const novoEstado = ref(null);
const paraEliminar = ref(null);
const aProcessar = ref(false);

function confirmarEstado() {
    aProcessar.value = true;
    router.patch(`${BASE}/${paraEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do plano de propina atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEstado.value = null;
            novoEstado.value = null;
        },
    });
}

function confirmarEliminacao() {
    aProcessar.value = true;
    router.delete(`${BASE}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Plano de propina eliminado com sucesso.'),
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
                <h1 class="fs-2 fw-bold mb-1">Planos de Propina</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Definem como a escola cobra a propina em cada ano lectivo, por nível, curso, turno ou turma. Um plano é uma
                    configuração: não cria cobranças. Quando vários planos se aplicam, vence o mais específico (ver "Precedência").
                </p>
            </div>
            <div v-if="can('plano-propina.criar')" class="d-flex gap-2">
                <button class="btn btn-light-primary" @click="abrirCopia">Copiar de outro ano</button>
                <button class="btn btn-primary" @click="abrirCriacao">Novo Plano</button>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Nome" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                        <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAno" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Nome</th>
                            <th class="min-w-100px">Ano Lectivo</th>
                            <th class="min-w-100px">Periodicidade</th>
                            <th class="text-end min-w-125px">Valor</th>
                            <th class="min-w-125px">Período</th>
                            <th class="min-w-175px">Aplica-se a</th>
                            <th class="min-w-150px">Precedência</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="planos.data.length === 0">
                            <td colspan="9" class="text-center text-muted py-6">Nenhum plano de propina encontrado.</td>
                        </tr>
                        <tr v-for="plano in planos.data" :key="plano.id">
                            <td class="text-gray-800">{{ plano.nome }}</td>
                            <td>{{ plano.ano_lectivo_nome ?? '—' }}</td>
                            <td>{{ plano.periodicidade_descricao }}</td>
                            <td class="text-end">{{ formatarDinheiro(plano.valor, moeda) }}</td>
                            <td>{{ periodoTexto(plano) }} <span class="text-muted fs-7">({{ plano.periodos_total }} períodos)</span></td>
                            <td>
                                {{ alvosTexto(plano) }}
                                <span v-if="temAlvoEliminado(plano)" class="badge badge-light-warning ms-1" title="Alguns alvos foram eliminados e deixaram de se aplicar. Edite o plano para os retirar.">Alvo eliminado</span>
                            </td>
                            <td><span class="badge badge-light-info">{{ plano.precedencia }}</span></td>
                            <td><EstadoBadge :estado="plano.estado" :estado-descricao="plano.estado_descricao" /></td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('plano-propina.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(plano)">
                                            <AcaoIcone acao="editar" class="me-2" /> Editar
                                        </a>
                                    </div>
                                    <div v-if="can('plano-propina.editar') && plano.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = plano; novoEstado = ESTADO.ATIVO">
                                            <AcaoIcone acao="ativar" class="me-2" /> Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('plano-propina.editar') && plano.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = plano; novoEstado = ESTADO.INATIVO">
                                            <AcaoIcone acao="desativar" class="me-2" /> Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('plano-propina.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEliminar = plano">
                                            <AcaoIcone acao="eliminar" class="me-2" /> Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="planos.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="planos.links" />
            </div>
        </div>

        <PlanoPropinaFormModal
            :show="modalAberto"
            :plano="planoEmEdicao"
            :anos-lectivos="anosLectivos"
            :niveis="niveis"
            :cursos="cursos"
            :turnos="turnos"
            :turmas="turmas"
            :periodicidades="periodicidades"
            :moeda="moeda"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="modalAberto = false"
        />

        <CopiarPlanosModal
            :show="copiaAberta"
            :anos-lectivos="anosLectivos"
            :resumo="resumoCopia"
            :processing="copiaProcessing"
            :errors="copiaErrors"
            @submit="copiar"
            @cancelar="copiaAberta = false"
        />

        <ConfirmModal
            :show="!!paraEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado de ${paraEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="aProcessar"
            @confirmar="confirmarEstado"
            @cancelar="paraEstado = null; novoEstado = null"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar plano de propina"
            :mensagem="`Eliminar ${paraEliminar?.nome}? Se já tiver registos financeiros, desative-o em vez disso.`"
            :processando="aProcessar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
