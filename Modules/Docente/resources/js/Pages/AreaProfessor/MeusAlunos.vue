<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvatarUtilizador from '@/Components/Shared/AvatarUtilizador.vue';
import { iniciais } from './iniciais';

const props = defineProps({
    alunos: { type: Array, required: true }, // [{ aluno_id, numero_matricula, nome, foto_url, turmas: [rotulo], disciplinas: [nome] }]
    turmas: { type: Array, required: true }, // [{ id, rotulo }] das suas atribuições
    disciplinas: { type: Array, required: true }, // [{ id, nome }]
    filtros: { type: Object, required: true }, // { turma, disciplina, q }
});
defineOptions({ layout: AppLayout });

const form = reactive({ turma: props.filtros.turma ?? '', disciplina: props.filtros.disciplina ?? '', q: props.filtros.q ?? '' });

function filtrar() {
    router.get('/professor/alunos', Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null)), { preserveState: true, replace: true });
}
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Os meus alunos</h1>
            <div class="text-muted">Os alunos das turmas e disciplinas que lecciona hoje · {{ alunos.length }} {{ alunos.length === 1 ? 'aluno' : 'alunos' }}</div>
        </div>

        <div class="card card-flush">
            <div class="card-header pt-6">
                <div class="d-flex flex-wrap gap-3 w-100">
                    <input v-model="form.q" type="search" class="form-control form-control-solid w-250px" placeholder="Nome ou n.º de matrícula" @input="filtrar" />
                    <select v-model="form.turma" class="form-select form-select-solid w-250px" @change="filtrar">
                        <option value="">Todas as turmas</option>
                        <option v-for="t in turmas" :key="t.id" :value="t.id">{{ t.rotulo }}</option>
                    </select>
                    <select v-model="form.disciplina" class="form-select form-select-solid w-250px" @change="filtrar">
                        <option value="">Todas as disciplinas</option>
                        <option v-for="d in disciplinas" :key="d.id" :value="d.id">{{ d.nome }}</option>
                    </select>
                </div>
            </div>
            <div class="card-body py-4">
                <div v-if="!alunos.length" class="text-center text-muted py-10">Nenhum aluno encontrado.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase">
                                <th>Nome</th>
                                <th>N.º de matrícula</th>
                                <th>Turmas</th>
                                <th>Disciplinas</th>
                                <th class="text-end">Acções</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in alunos" :key="a.aluno_id">
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <AvatarUtilizador :foto="a.foto_url" :iniciais="iniciais(a.nome)" tamanho="symbol-40px" circulo />
                                        <span class="fw-semibold">{{ a.nome }}</span>
                                    </div>
                                </td>
                                <td>{{ a.numero_matricula ?? '—' }}</td>
                                <td>{{ a.turmas.join(', ') }}</td>
                                <td>{{ a.disciplinas.join(', ') }}</td>
                                <td class="text-end">
                                    <Link :href="`/professor/alunos/${a.aluno_id}`" class="btn btn-sm btn-light-primary">Ver ficha</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
