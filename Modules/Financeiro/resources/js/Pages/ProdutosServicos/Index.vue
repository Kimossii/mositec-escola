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
import CatalogoItemFormModal from '../../Components/ProdutosServicos/CatalogoItemFormModal.vue';
import { ESTADO } from '../../Models/Estado';
import { formatKz } from '../../Support/dinheiro';

const LISTA = '/financeiro/configuracao/produtos-servicos';
const BASE = { produto: '/financeiro/configuracao/produtos', servico: '/financeiro/configuracao/servicos' };
const ROTULO = { produto: 'Produto', servico: 'Serviço' };
const MENSAGEM_GUARDAR = {
    produto: { criado: 'Produto criado com sucesso.', atualizado: 'Produto atualizado com sucesso.' },
    servico: { criado: 'Serviço criado com sucesso.', atualizado: 'Serviço atualizado com sucesso.' },
};

const props = defineProps({
    itens: { type: Object, required: true }, // paginador: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
});
defineOptions({ layout: AppLayout });

const separadores = [
    { valor: '', texto: 'Todos' },
    { valor: 'produto', texto: 'Produtos' },
    { valor: 'servico', texto: 'Serviços' },
];

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
]);

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: props.filtros.estado !== undefined && props.filtros.estado !== null && props.filtros.estado !== '' ? Number(props.filtros.estado) : '',
    tipo: props.filtros.tipo ?? '',
});

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(LISTA, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const modalAberto = ref(false);
const tipoDoModal = ref('produto');
const itemEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao(tipo) {
    tipoDoModal.value = tipo;
    itemEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(item) {
    tipoDoModal.value = item.tipo;
    itemEmEdicao.value = item;
    errors.value = {};
    modalAberto.value = true;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const tipo = tipoDoModal.value;
    const edicao = itemEmEdicao.value;
    const url = edicao ? `${BASE[tipo]}/${edicao.id}` : BASE[tipo];

    router[edicao ? 'put' : 'post'](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(MENSAGEM_GUARDAR[tipo][edicao ? 'atualizado' : 'criado']);
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
    router.patch(`${BASE[paraEstado.value.tipo]}/${paraEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado atualizado com sucesso.'),
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
    router.delete(`${BASE[paraEliminar.value.tipo]}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Item eliminado com sucesso.'),
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
                <h1 class="fs-2 fw-bold mb-1">Produtos / Serviços</h1>
                <p class="text-muted fs-6 mb-0">O catálogo de itens que a escola disponibiliza. Cadastrar um item não cria cobranças.</p>
            </div>
            <div v-if="can('catalogo-financeiro.criar')" class="d-flex gap-2">
                <button class="btn btn-primary" @click="abrirCriacao('produto')">Novo Produto</button>
                <button class="btn btn-light-primary" @click="abrirCriacao('servico')">Novo Serviço</button>
            </div>
        </div>

        <ul class="nav nav-tabs nav-line-tabs fs-6 mb-6">
            <li v-for="separador in separadores" :key="separador.valor" class="nav-item">
                <a href="#" class="nav-link" :class="{ active: filtros.tipo === separador.valor }" @click.prevent="filtros.tipo = separador.valor">
                    {{ separador.texto }}
                </a>
            </li>
        </ul>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Código ou nome" />
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
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-100px">Tipo</th>
                            <th class="text-end min-w-125px">Preço</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="itens.data.length === 0">
                            <td colspan="6" class="text-center text-muted py-6">Nenhum item encontrado.</td>
                        </tr>
                        <tr v-for="item in itens.data" :key="`${item.tipo}-${item.id}`">
                            <td>{{ item.codigo ?? '—' }}</td>
                            <td class="text-gray-800">{{ item.nome }}</td>
                            <td>
                                <span class="badge" :class="item.tipo === 'produto' ? 'badge-light-primary' : 'badge-light-info'">{{ ROTULO[item.tipo] }}</span>
                            </td>
                            <td class="text-end">{{ formatKz(item.preco) }}</td>
                            <td><EstadoBadge :estado="item.estado" :estado-descricao="item.estado_descricao" /></td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('catalogo-financeiro.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(item)">
                                            <AcaoIcone acao="editar" class="me-2" /> Editar
                                        </a>
                                    </div>
                                    <div v-if="can('catalogo-financeiro.editar') && item.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = item; novoEstado = ESTADO.ATIVO">
                                            <AcaoIcone acao="ativar" class="me-2" /> Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('catalogo-financeiro.editar') && item.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = item; novoEstado = ESTADO.INATIVO">
                                            <AcaoIcone acao="desativar" class="me-2" /> Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('catalogo-financeiro.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEliminar = item">
                                            <AcaoIcone acao="eliminar" class="me-2" /> Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="itens.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="itens.links" />
            </div>
        </div>

        <CatalogoItemFormModal
            :show="modalAberto"
            :tipo="tipoDoModal"
            :item="itemEmEdicao"
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
            titulo="Eliminar item"
            :mensagem="`Eliminar ${paraEliminar?.nome}? Se já tiver registos financeiros, desative-o em vez disso.`"
            :processando="aProcessar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
