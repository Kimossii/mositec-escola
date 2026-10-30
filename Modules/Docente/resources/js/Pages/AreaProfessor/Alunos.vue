<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';

defineProps({
    turma_rotulo: { type: String, required: true },
    disciplina_nome: { type: String, required: true },
    nivel: { type: String, default: null }, // só quando o nome da turma não o diz
    curso: { type: String, default: null }, // só turmas de curso
    alunos: { type: Array, required: true }, // [{ matricula_id, aluno_id, numero_matricula, nome }]
});
defineOptions({ layout: AppLayout });
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6">
            <BotaoVoltar href="/professor/turmas" />
            <div class="text-muted fs-8 text-uppercase fw-bold">Disciplina</div>
            <h1 class="fs-2 fw-bold mb-1">{{ disciplina_nome }}</h1>
            <div class="text-muted">{{ [turma_rotulo, nivel, curso ? `Curso: ${curso}` : null].filter(Boolean).join(' · ') }} · {{ alunos.length }} {{ alunos.length === 1 ? 'aluno' : 'alunos' }}</div>
        </div>

        <div class="card card-flush">
            <div class="card-body py-4">
                <div v-if="!alunos.length" class="text-center text-muted py-10">Nenhum aluno inscrito nesta disciplina.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase">
                                <th style="width: 3rem">#</th>
                                <th>Nome</th>
                                <th>N.º de matrícula</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, i) in alunos" :key="a.matricula_id">
                                <td class="text-muted">{{ i + 1 }}</td>
                                <td class="fw-semibold">{{ a.nome }}</td>
                                <td>{{ a.numero_matricula ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
