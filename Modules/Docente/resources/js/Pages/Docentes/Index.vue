<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';
import FiltrosRecolhiveis from '@/Components/Shared/FiltrosRecolhiveis.vue';
import IndicadoresDiferidos from '@/Components/Shared/IndicadoresDiferidos.vue';
import MenuExportar from '@/Components/Shared/MenuExportar.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { can } from '@/Composables/usePermissoes';
import EstadoBadge from '../../Components/Docentes/EstadoBadge.vue';
import AcessoBadge from '../../Components/Docentes/AcessoBadge.vue';
import DocenteFormModal from '../../Components/Docentes/DocenteFormModal.vue';
import CriarAcessoModal from '../../Components/Docentes/CriarAcessoModal.vue';
import { ACESSO, ESTADO } from '../../Models/Docente';

const props = defineProps({
    docentes: { type: Object, required: true }, // paginator: { data, links, ... }
    filtros: { type: Object, default: () => ({}) }, // { pesquisa, estado, acesso }
    indicadores: { type: Object, default: null }, // diferido: { total, activos, com_acesso, sem_acesso }
});
defineOptions({ layout: AppLayout });

const URL_BASE = '/docentes';

const opcoesEstado = [
    { value: '', label: 'Todos os estados' },
    { value: String(ESTADO.ATIVO), label: 'Ativo' },
    { value: String(ESTADO.INATIVO), label: 'Inativo' },
];
const opcoesAcesso = [
    { value: '', label: 'Com e sem acesso' },
    { value: ACESSO.COM, label: 'Com acesso' },
    { value: ACESSO.SEM, label: 'Sem acesso' },
];

// O estado chega da query string como texto («0»/«1»): mantém-se texto para casar com as opções.
const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: props.filtros.estado != null ? String(props.filtros.estado) : '',
    acesso: props.filtros.acesso ?? '',
});

const filtrosAtivos = computed(() => ['estado', 'acesso'].filter((c) => filtros[c] !== '').length);
const comFiltros = computed(() => filtrosAtivos.value > 0 || filtros.pesquisa !== '');
const limparFiltros = () => Object.assign(filtros, { estado: '', acesso: '' });
const parametros = () => Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== ''));

let debounceId = null;
watch(filtros, () => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(URL_BASE, parametros(), { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const montarIndicadores = (i) => [
    { rotulo: 'Docentes', valor: String(i.total), nota: 'registados na escola', cor: 'primary' },
    { rotulo: 'Activos', valor: String(i.activos), nota: 'disponíveis para atribuições', cor: 'success' },
    { rotulo: 'Com acesso', valor: String(i.com_acesso), nota: 'podem entrar no sistema', cor: 'info' },
    { rotulo: 'Sem acesso', valor: String(i.sem_acesso), nota: i.sem_acesso ? 'ainda sem conta de utilizador' : 'todos têm conta', cor: i.sem_acesso ? 'warning' : 'success' },
];

const ou = (v) => (v === null || v === undefined || v === '' ? '—' : v);

// ── Permissões por linha ──
const podeCriarAcesso = (d) => !d.tem_acesso && d.estado === ESTADO.ATIVO && can('docente.editar') && can('usuario.criar');
const temAccoes = (d) => can('docente.editar') || can('docente.eliminar') || podeCriarAcesso(d);

// ── Criar / editar / criar acesso ──
const modal = ref(null); // 'form' | 'acesso'
const docenteAlvo = ref(null);
const processing = ref(false);
const errors = ref({});

function abrir(tipo, docente = null) {
    errors.value = {};
    docenteAlvo.value = docente;
    modal.value = tipo;
}

function fechar() {
    modal.value = null;
}

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

function guardar(dados) {
    if (docenteAlvo.value) {
        enviar('put', `${URL_BASE}/${docenteAlvo.value.id}`, dados, 'Docente atualizado com sucesso.');
    } else {
        enviar('post', URL_BASE, dados, 'Docente criado com sucesso.');
    }
}

const criarAcesso = (dados) => enviar('post', `${URL_BASE}/${docenteAlvo.value.id}/acesso`, dados, 'Acesso do docente criado com sucesso.');

// ── Ativar / desativar ──
const paraAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(docente, estado) {
    paraAlterarEstado.value = docente;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    paraAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`${URL_BASE}/${paraAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do docente atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            cancelarAlteracaoEstado();
        },
    });
}

const mensagemEstado = computed(() => (novoEstado.value === ESTADO.INATIVO
    ? `Desativar o docente ${paraAlterarEstado.value?.nome}? As atribuições em vigor mantêm-se, mas deixa de aparecer para novas atribuições.`
    : `Ativar o docente ${paraAlterarEstado.value?.nome}? Volta a poder receber atribuições.`));

// ── Eliminar ──
// Bloqueado pelo servidor se o docente tiver atribuições (erro na chave `docente`): o aviso substitui a pergunta no
// modal, que fica aberto até o fechar (o toast desaparece; o modal não).
const paraEliminar = ref(null);
const eliminando = ref(false);
const erroEliminar = ref('');

function pedirEliminacao(docente) {
    erroEliminar.value = '';
    paraEliminar.value = docente;
}

function cancelarEliminacao() {
    paraEliminar.value = null;
    erroEliminar.value = '';
}

function confirmarEliminacao() {
    eliminando.value = true;
    erroEliminar.value = '';
    router.delete(`${URL_BASE}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Docente eliminado com sucesso.');
            paraEliminar.value = null;
        },
        onError: (erros) => {
            erroEliminar.value = Object.values(erros)[0] ?? 'Não foi possível eliminar o docente.';
            toast.error(erroEliminar.value);
        },
        onFinish: () => { eliminando.value = false; },
    });
}

const mensagemEliminar = computed(() => (erroEliminar.value
    ? erroEliminar.value
    : `Eliminar o docente ${paraEliminar.value?.nome}? Só é possível se não tiver nenhuma atribuição; no dia-a-dia, prefira desativá-lo.`));
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-6">
            <h1 class="fs-2 fw-bold mb-0">Docentes</h1>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <MenuExportar v-if="can('docente.exportar')" :url="`${URL_BASE}/exportar`" :params="parametros()" />
                <button v-if="can('docente.criar')" class="btn btn-primary" @click="abrir('form')">Novo Docente</button>
            </div>
        </div>

        <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" :quantidade="4" />

        <FiltrosRecolhiveis v-model="filtros.pesquisa" placeholder="Pesquisar por nome ou nº de identificação" :ativos="filtrosAtivos" @limpar="limparFiltros">
            <div class="col-12 col-md-6">
                <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
            </div>
            <div class="col-12 col-md-6">
                <label class="fw-semibold fs-7 text-muted mb-1">Acesso ao sistema</label>
                <SelectSolid v-model="filtros.acesso" :options="opcoesAcesso" />
            </div>
        </FiltrosRecolhiveis>

        <ListaComVistas chave="pessoal-docentes" prefixo="/docentes">
        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Nº de identificação</th>
                            <th class="min-w-125px">Telefone</th>
                            <th class="min-w-150px">Email</th>
                            <th class="min-w-100px">Acesso</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="docentes.data.length === 0">
                            <td colspan="7" class="text-center text-muted py-6">
                                {{ comFiltros ? 'Nenhum docente encontrado com estes filtros.' : 'Ainda não há docentes registados.' }}
                            </td>
                        </tr>
                        <tr v-for="d in docentes.data" :key="d.id">
                            <td class="text-gray-800">{{ ou(d.nome) }}</td>
                            <td>{{ ou(d.bi) }}</td>
                            <td>{{ ou(d.telefone) }}</td>
                            <td>{{ ou(d.email) }}</td>
                            <td><AcessoBadge :tem-acesso="d.tem_acesso" /></td>
                            <td><EstadoBadge :estado="d.estado" /></td>
                            <td class="text-end">
                                <template v-if="temAccoes(d)">
                                    <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                        Ações
                                        <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                    </a>
                                    <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                        <div v-if="can('docente.editar')" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="abrir('form', d)">
                                                <AcaoIcone acao="editar" class="me-2" />
                                                Editar
                                            </a>
                                        </div>
                                        <div v-if="podeCriarAcesso(d)" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="abrir('acesso', d)">
                                                <AcaoIcone acao="redefinirSenha" class="me-2" />
                                                Criar acesso
                                            </a>
                                        </div>
                                        <div v-if="can('docente.editar') && d.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(d, ESTADO.ATIVO)">
                                                <AcaoIcone acao="ativar" class="me-2" />
                                                Ativar
                                            </a>
                                        </div>
                                        <div v-if="can('docente.editar') && d.estado === ESTADO.ATIVO" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(d, ESTADO.INATIVO)">
                                                <AcaoIcone acao="desativar" class="me-2" />
                                                Desativar
                                            </a>
                                        </div>
                                        <div v-if="can('docente.eliminar')" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="pedirEliminacao(d)">
                                                <AcaoIcone acao="eliminar" class="me-2" />
                                                Eliminar
                                            </a>
                                        </div>
                                    </div>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="docentes.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="docentes.links" />
            </div>
        </div>
        </ListaComVistas>

        <DocenteFormModal
            :show="modal === 'form'"
            :docente="docenteAlvo"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fechar"
        />
        <CriarAcessoModal
            :show="modal === 'acesso'"
            :docente="docenteAlvo"
            :processing="processing"
            :errors="errors"
            @submit="criarAcesso"
            @cancelar="fechar"
        />

        <ConfirmModal
            :show="!!paraAlterarEstado"
            titulo="Alterar estado"
            :mensagem="mensagemEstado"
            texto-confirmar="Confirmar"
            variante="primary"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />
        <ConfirmModal
            :show="!!paraEliminar"
            :titulo="erroEliminar ? 'Não é possível eliminar' : 'Eliminar docente'"
            :mensagem="mensagemEliminar"
            texto-confirmar="Eliminar"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
