<script setup>
import { computed, reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import { ESTADO_MATRICULA_LABEL } from '../Models/Estado';

const props = defineProps({
    matriculas: { type: Object, required: true }, // paginator: { data, links, ... }
    turmasDisponiveis: { type: Array, required: true },
    filtros: { type: Object, default: () => ({}) },
});
defineOptions({ layout: AppLayout });

const opcoesTurma = computed(() => [
    { value: '', label: 'Todas as turmas' },
    ...props.turmasDisponiveis.map((turma) => {
        const partes = [
            `${turma.codigo} — ${turma.nome}`,
            turma.ano_lectivo?.nome,
            turma.turno?.nome,
            turma.curso?.nome,
            turma.nivel_academico?.nome,
        ].filter(Boolean);

        return { value: turma.id, label: partes.join(' · ') };
    }),
]);

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    ...Object.entries(ESTADO_MATRICULA_LABEL).map(([value, label]) => ({ value: Number(value), label })),
]);

const filtros = reactive({
    turma_id: props.filtros.turma_id ?? '',
    estado: props.filtros.estado ?? '',
    pesquisa: props.filtros.pesquisa ?? '',
});

let debounceId = null;

watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get('/matriculas', valor, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

function formatarData(data) {
    if (!data) return '—';
    const [ano, mes, dia] = data.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Matrículas</h1>
        </div>

        <div class="card mb-6">
            <div class="card-body d-flex flex-wrap gap-4">
                <div style="min-width: 280px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Aluno</label>
                    <input
                        v-model="filtros.pesquisa"
                        type="text"
                        class="form-control form-control-solid"
                        placeholder="Nº de matrícula, nome ou nº de identificação"
                    />
                </div>
                <div style="min-width: 260px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Turma</label>
                    <SelectSolid v-model="filtros.turma_id" :options="opcoesTurma" searchable />
                </div>
                <div style="min-width: 200px;">
                    <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                    <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-125px">Nº Registo</th>
                            <th class="min-w-200px">Aluno</th>
                            <th class="min-w-150px">Turma</th>
                            <th class="min-w-100px">Ano Lectivo</th>
                            <th class="min-w-100px">Data</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-100px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="matriculas.data.length === 0">
                            <td colspan="7" class="text-center text-muted py-6">Nenhuma matrícula encontrada.</td>
                        </tr>
                        <tr v-for="matricula in matriculas.data" :key="matricula.id">
                            <td>{{ matricula.numero_registo_matricula }}</td>
                            <td>{{ matricula.aluno?.dados_pessoa?.nome_completo ?? '—' }}</td>
                            <td>{{ matricula.turma?.codigo }} — {{ matricula.turma?.nome }}</td>
                            <td>{{ matricula.ano_lectivo?.nome ?? '—' }}</td>
                            <td>{{ formatarData(matricula.data_matricula) }}</td>
                            <td><EstadoBadge :estado="matricula.estado" /></td>
                            <td class="text-end">
                                <a :href="`/alunos/${matricula.aluno_id}`" class="btn btn-light btn-active-light-primary btn-sm">
                                    Ver Aluno
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="matriculas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="matriculas.links" />
            </div>
        </div>
    </div>
</template>
