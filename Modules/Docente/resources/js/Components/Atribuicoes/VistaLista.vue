<script setup>
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';
import VigenciaBadge from './VigenciaBadge.vue';
import AcessoBadge from '../Docentes/AcessoBadge.vue';
import { VIGENCIA, formatarDia, papelLabel } from '../../Models/PapelDocente';

/** Todas as atribuições do ano, filtradas e paginadas, no padrão das outras listas. */
defineProps({
    atribuicoes: { type: Object, required: true }, // paginator
    comFiltros: { type: Boolean, default: false },
    docentesSemAcesso: { type: Array, default: () => [] }, // ids de docentes activos sem conta
});
const emit = defineEmits(['editar', 'encerrar', 'eliminar']);

const podeGerir = (a) => a.estado !== VIGENCIA.TERMINADA;
const temAccoes = (a) => podeGerir(a) && (can('atribuicao-docente.editar') || can('atribuicao-docente.encerrar')
    || (a.estado === VIGENCIA.AGENDADA && can('atribuicao-docente.eliminar')));
</script>

<template>
    <ListaComVistas chave="docentes-atribuicoes" prefixo="/docentes/atribuicoes">
    <div class="card">
        <div class="card-body p-0">
            <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                <thead>
                    <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                        <th class="min-w-175px">Docente</th>
                        <th class="min-w-125px">Turma</th>
                        <th class="min-w-150px">Disciplina</th>
                        <th class="min-w-100px">Papel</th>
                        <th class="min-w-150px">Vigência</th>
                        <th class="min-w-100px">Estado</th>
                        <th class="text-end min-w-125px">Ações</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600 fw-semibold">
                    <tr v-if="atribuicoes.data.length === 0">
                        <td colspan="7" class="text-center text-muted py-6">
                            {{ comFiltros ? 'Nenhuma atribuição encontrada com estes filtros.' : 'Ainda não há atribuições neste ano lectivo.' }}
                        </td>
                    </tr>
                    <tr v-for="a in atribuicoes.data" :key="a.id">
                        <td class="text-gray-800">
                            {{ a.docente_nome }}
                            <AcessoBadge v-if="docentesSemAcesso.includes(a.docente_id)" :tem-acesso="false" class="fs-8 ms-2" title="Ainda sem conta de utilizador: crie-lhe acesso em Pessoal → Docentes." />
                        </td>
                        <td>{{ a.turma_nome }}</td>
                        <td>{{ a.disciplina_nome }}</td>
                        <td>{{ a.papel_descricao || papelLabel(a.papel) }}</td>
                        <td>
                            {{ formatarDia(a.data_inicio) }} – {{ a.data_fim ? formatarDia(a.data_fim) : 'fim do ano' }}
                            <div v-if="a.motivo_encerramento" class="text-muted fs-8">{{ a.motivo_encerramento }}</div>
                        </td>
                        <td><VigenciaBadge :estado="a.estado" /></td>
                        <td class="text-end">
                            <template v-if="temAccoes(a)">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('atribuicao-docente.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="emit('editar', a)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar vigência
                                        </a>
                                    </div>
                                    <div v-if="can('atribuicao-docente.encerrar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="emit('encerrar', a)">
                                            <AcaoIcone acao="encerrar" class="me-2" />
                                            Encerrar
                                        </a>
                                    </div>
                                    <div v-if="a.estado === VIGENCIA.AGENDADA && can('atribuicao-docente.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="emit('eliminar', a)">
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
        <div v-if="atribuicoes.data.length" class="card-footer d-flex justify-content-end">
            <Pagination :links="atribuicoes.links" />
        </div>
    </div>
    </ListaComVistas>
</template>
