<script setup>
import { computed } from 'vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import VigenciaBadge from './VigenciaBadge.vue';
import { VIGENCIA, formatarDia, papelLabel } from '../../Models/PapelDocente';

/**
 * Uma linha por disciplina atribuível da turma: titular em vigor (ou agendado), substitutos e estado de cobertura.
 * As que contam como em falta (sem titular e obrigatórias ou com alunos inscritos) ficam destacadas.
 */
const props = defineProps({
    porTurma: { type: Object, default: null }, // { linhas, motivo_vazio } ou null sem turma escolhida
    turma: { type: Object, default: null },
    semTurmas: { type: Boolean, default: false },
});
const emit = defineEmits(['atribuir', 'substituir', 'editar', 'encerrar', 'eliminar']);

const linhas = computed(() => props.porTurma?.linhas ?? []);

// «Esta turma ainda não tem disciplinas definidas: <razão>» → título + explicação.
const vazio = computed(() => {
    const motivo = props.porTurma?.motivo_vazio;
    if (!motivo) return null;
    const i = motivo.indexOf(': ');
    return i > 0 ? { titulo: motivo.slice(0, i), detalhe: motivo.slice(i + 2) } : { titulo: motivo, detalhe: '' };
});

const pessoasDaLinha = (linha) => [linha.titular, linha.titular_agendado, ...(linha.substitutos ?? [])].filter(Boolean);

const podeGerir = (a) => a.estado !== VIGENCIA.TERMINADA;

const temAccoes = (linha) => can('atribuicao-docente.criar')
    || pessoasDaLinha(linha).some((a) => podeGerir(a) && (can('atribuicao-docente.editar') || can('atribuicao-docente.encerrar')
        || (a.estado === VIGENCIA.AGENDADA && can('atribuicao-docente.eliminar'))));

const cobertura = (linha) => {
    if (linha.conta_como_em_falta) return { texto: 'Em falta', classe: 'badge-light-danger' };
    if (linha.sem_titular) return { texto: 'Sem titular', classe: 'badge-light-warning' };
    return { texto: 'Coberta', classe: 'badge-light-success' };
};
</script>

<template>
    <div v-if="semTurmas" class="card border-0 bg-body-secondary">
        <div class="card-body d-flex align-items-center gap-3 py-5">
            <i class="ki-duotone ki-information-5 fs-2x text-warning"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
            <div>
                <div class="fw-bold">Este ano lectivo ainda não tem turmas activas</div>
                <div class="text-muted fs-7">Crie ou active as turmas do ano para poder atribuir docentes.</div>
            </div>
        </div>
    </div>

    <div v-else-if="!porTurma" class="card border-0 bg-body-secondary">
        <div class="card-body d-flex align-items-center gap-3 py-5">
            <i class="ki-duotone ki-teacher fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
            <div>
                <div class="fw-bold">Escolha uma turma</div>
                <div class="text-muted fs-7">Vê as disciplinas da turma, quem as lecciona e o que falta cobrir.</div>
            </div>
        </div>
    </div>

    <div v-else-if="vazio" class="card border-0 bg-body-secondary">
        <div class="card-body d-flex align-items-center gap-3 py-5">
            <i class="ki-duotone ki-information-5 fs-2x text-warning"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
            <div>
                <div class="fw-bold">{{ vazio.titulo }}</div>
                <div v-if="vazio.detalhe" class="text-muted fs-7">{{ vazio.detalhe }}</div>
            </div>
        </div>
    </div>

    <div v-else class="card">
        <div class="card-body p-0">
            <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                <thead>
                    <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                        <th class="min-w-175px">Disciplina</th>
                        <th class="min-w-200px">Titular</th>
                        <th class="min-w-200px">Substitutos</th>
                        <th class="min-w-100px">Estado</th>
                        <th class="text-end min-w-125px">Ações</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600 fw-semibold">
                    <tr v-for="linha in linhas" :key="linha.disciplina_id" :class="{ 'bg-light-danger': linha.conta_como_em_falta }">
                        <td>
                            <div class="text-gray-800 fw-bold">{{ linha.disciplina_nome }}</div>
                            <div v-if="!linha.obrigatoria" class="text-muted fs-8">Opcional</div>
                        </td>
                        <td>
                            <template v-if="linha.titular">
                                <div class="text-gray-800">{{ linha.titular.professor_nome }}</div>
                                <div class="text-muted fs-8">
                                    desde {{ formatarDia(linha.titular.data_inicio) }}<span v-if="linha.titular.data_fim"> até {{ formatarDia(linha.titular.data_fim) }}</span>
                                </div>
                            </template>
                            <span v-else class="text-danger fs-7">Sem titular</span>
                            <div v-if="linha.titular_agendado" class="mt-1 fs-8">
                                <VigenciaBadge :estado="linha.titular_agendado.estado" class="me-1" />
                                {{ linha.titular_agendado.professor_nome }} a partir de {{ formatarDia(linha.titular_agendado.data_inicio) }}
                            </div>
                        </td>
                        <td>
                            <div v-for="s in linha.substitutos" :key="s.id" class="mb-1">
                                <span class="text-gray-800">{{ s.professor_nome }}</span>
                                <VigenciaBadge :estado="s.estado" class="ms-2" />
                                <div class="text-muted fs-8">{{ formatarDia(s.data_inicio) }}<span v-if="s.data_fim"> – {{ formatarDia(s.data_fim) }}</span></div>
                            </div>
                            <span v-if="!linha.substitutos?.length" class="text-muted">—</span>
                        </td>
                        <td>
                            <span class="badge fw-bold" :class="cobertura(linha).classe">{{ cobertura(linha).texto }}</span>
                        </td>
                        <td class="text-end">
                            <template v-if="temAccoes(linha)">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-250px py-4" data-kt-menu="true">
                                    <div v-if="can('atribuicao-docente.criar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="emit('atribuir', linha)">
                                            <AcaoIcone acao="disciplinas" class="me-2" />
                                            Atribuir
                                        </a>
                                    </div>
                                    <div v-if="can('atribuicao-docente.criar') && linha.titular" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="emit('substituir', linha)">
                                            <AcaoIcone acao="renovar" class="me-2" />
                                            Substituir titular
                                        </a>
                                    </div>
                                    <template v-for="a in pessoasDaLinha(linha).filter(podeGerir)" :key="a.id">
                                        <div class="menu-item px-3 pt-3">
                                            <div class="menu-content fs-8 text-muted text-uppercase px-3 py-1">{{ a.professor_nome }} · {{ papelLabel(a.papel) }}</div>
                                        </div>
                                        <div v-if="can('atribuicao-docente.editar')" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="emit('editar', a, linha)">
                                                <AcaoIcone acao="editar" class="me-2" />
                                                Editar vigência
                                            </a>
                                        </div>
                                        <div v-if="can('atribuicao-docente.encerrar')" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="emit('encerrar', a, linha)">
                                                <AcaoIcone acao="encerrar" class="me-2" />
                                                Encerrar
                                            </a>
                                        </div>
                                        <div v-if="a.estado === VIGENCIA.AGENDADA && can('atribuicao-docente.eliminar')" class="menu-item px-3">
                                            <a href="#" class="menu-link px-3" @click.prevent="emit('eliminar', a, linha)">
                                                <AcaoIcone acao="eliminar" class="me-2" />
                                                Eliminar
                                            </a>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
