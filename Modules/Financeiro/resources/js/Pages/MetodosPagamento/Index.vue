<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import MetodoPagamentoFormModal from '../../Components/MetodosPagamento/MetodoPagamentoFormModal.vue';
import { ESTADO } from '../../Models/Estado';

const BASE = '/financeiro/configuracao/metodos-pagamento';

const props = defineProps({
    metodos: { type: Object, required: true }, // paginador: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
    tipos: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
]);

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: props.filtros.estado !== undefined && props.filtros.estado !== null && props.filtros.estado !== '' ? Number(props.filtros.estado) : '',
});

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const modalAberto = ref(false);
const metodoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    metodoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(metodo) {
    metodoEmEdicao.value = metodo;
    errors.value = {};
    modalAberto.value = true;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = metodoEmEdicao.value ? `${BASE}/${metodoEmEdicao.value.id}` : BASE;
    const metodo = metodoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(metodoEmEdicao.value ? 'Método de pagamento atualizado com sucesso.' : 'Método de pagamento criado com sucesso.');
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

const paraEstado = ref(null);
const novoEstado = ref(null);
const paraEliminar = ref(null);
const aProcessar = ref(false);

function confirmarEstado() {
    aProcessar.value = true;
    router.patch(`${BASE}/${paraEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do método de pagamento atualizado com sucesso.'),
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
        onSuccess: () => toast.success('Método de pagamento eliminado com sucesso.'),
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
                <h1 class="fs-2 fw-bold mb-1">Métodos de Pagamento</h1>
                <p class="text-muted fs-6 mb-0">Os métodos que a escola aceita receber.</p>
            </div>
            <button v-if="can('metodo-pagamento.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Método</button>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Nome" />
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
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-150px">Tipo</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="metodos.data.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum método de pagamento encontrado.</td>
                        </tr>
                        <tr v-for="metodo in metodos.data" :key="metodo.id">
                            <td class="text-gray-800">{{ metodo.nome }}</td>
                            <td>{{ metodo.tipo_descricao }}</td>
                            <td><EstadoBadge :estado="metodo.estado" :estado-descricao="metodo.estado_descricao" /></td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('metodo-pagamento.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(metodo)">
                                            <AcaoIcone acao="editar" class="me-2" /> Editar
                                        </a>
                                    </div>
                                    <div v-if="can('metodo-pagamento.editar') && metodo.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = metodo; novoEstado = ESTADO.ATIVO">
                                            <AcaoIcone acao="ativar" class="me-2" /> Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('metodo-pagamento.editar') && metodo.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = metodo; novoEstado = ESTADO.INATIVO">
                                            <AcaoIcone acao="desativar" class="me-2" /> Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('metodo-pagamento.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEliminar = metodo">
                                            <AcaoIcone acao="eliminar" class="me-2" /> Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="metodos.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="metodos.links" />
            </div>
        </div>

        <MetodoPagamentoFormModal
            :show="modalAberto"
            :metodo="metodoEmEdicao"
            :tipos="tipos"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="modalAberto = false"
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
            titulo="Eliminar método de pagamento"
            :mensagem="`Eliminar ${paraEliminar?.nome}? Se já tiver registos financeiros, desative-o em vez disso.`"
            :processando="aProcessar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
