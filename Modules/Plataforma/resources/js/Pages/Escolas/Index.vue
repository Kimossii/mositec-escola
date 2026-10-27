<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Pagination from '@/Components/Shared/Pagination.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import EstadoBadge from '../../Components/EstadoBadge.vue';
import { formatarData } from '../../Composables/formatarData';

const props = defineProps({
    escolas: { type: Object, required: true }, // paginator: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
});
defineOptions({ layout: LayoutPlataforma });

const opcoesEstado = [
    { value: '', label: 'Todos os estados' },
    { value: '1', label: 'Activa' },
    { value: '2', label: 'Suspensa' },
    { value: '3', label: 'Encerrada' },
];

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: props.filtros.estado ?? '',
});

const temFiltros = computed(() => filtros.pesquisa !== '' || filtros.estado !== '');
const limparFiltros = () => {
    filtros.pesquisa = '';
    filtros.estado = '';
};
const abrir = (escola) => router.visit(`/plataforma/escolas/${escola.codigo}`);

let debounceId = null;

watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get('/plataforma/escolas', { pesquisa: valor.pesquisa || undefined, estado: valor.estado || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-0">Escolas</h1>
                <div class="text-muted fs-7">{{ escolas.total ?? escolas.data.length }} {{ (escolas.total ?? escolas.data.length) === 1 ? 'escola' : 'escolas' }}{{ temFiltros ? ' com estes filtros' : '' }}</div>
            </div>
            <Link href="/plataforma/escolas/nova" class="btn btn-primary">Nova escola</Link>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-12 col-md-8">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input
                            v-model="filtros.pesquisa"
                            type="text"
                            class="form-control form-control-solid"
                            placeholder="Nome, código ou domínio"
                        />
                    </div>
                    <div class="col-12 col-md-4">
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
                            <th class="min-w-125px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="min-w-200px">Domínio principal</th>
                            <th class="min-w-125px">Criada em</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="escolas.data.length === 0">
                            <td colspan="5" class="text-center text-muted py-6">
                                Nenhuma escola encontrada.
                                <button v-if="temFiltros" type="button" class="btn btn-link btn-sm p-0 ms-2 align-baseline" @click="limparFiltros">Limpar filtros</button>
                            </td>
                        </tr>
                        <tr v-for="escola in escolas.data" :key="escola.codigo" role="button" @click="abrir(escola)">
                            <td>
                                <Link :href="`/plataforma/escolas/${escola.codigo}`" class="text-gray-800 text-hover-primary fw-bold" @click.stop>
                                    {{ escola.codigo }}
                                </Link>
                            </td>
                            <td class="text-gray-800">{{ escola.nome }}</td>
                            <td><EstadoBadge :estado="escola.estado" /></td>
                            <td>{{ escola.dominio_principal ?? '—' }}</td>
                            <td>{{ formatarData(escola.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="escolas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="escolas.links" />
            </div>
        </div>
    </div>
</template>
