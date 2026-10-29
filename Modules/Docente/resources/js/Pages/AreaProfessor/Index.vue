<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    turmas: { type: Array, required: true }, // [{ turma_id, turma_rotulo, disciplinas: [{ disciplina_id, disciplina_nome, papel, data_inicio, data_fim }] }]
});
defineOptions({ layout: AppLayout });

const PAPEL = { 0: 'Titular', 1: 'Substituto' };
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">As minhas turmas</h1>
            <div class="text-muted">As turmas e disciplinas que lecciona hoje.</div>
        </div>

        <div v-if="!turmas.length" class="card card-flush">
            <div class="card-body text-center text-muted py-15">
                Não tem turmas atribuídas neste momento. A secretaria atribui as turmas em Pessoal → Atribuições docentes.
            </div>
        </div>

        <div v-else class="row g-6">
            <div v-for="turma in turmas" :key="turma.turma_id" class="col-12 col-xl-6">
                <div class="card card-flush h-100">
                    <div class="card-header">
                        <h3 class="card-title fw-bold">{{ turma.turma_rotulo }}</h3>
                    </div>
                    <div class="card-body pt-0">
                        <Link
                            v-for="d in turma.disciplinas"
                            :key="d.disciplina_id"
                            :href="`/professor/turmas/${turma.turma_id}/disciplinas/${d.disciplina_id}/alunos`"
                            class="d-flex flex-stack py-3 border-bottom text-gray-800 text-hover-primary"
                        >
                            <span class="fw-semibold">{{ d.disciplina_nome }}</span>
                            <span class="d-flex align-items-center gap-3">
                                <span class="badge" :class="d.papel === 0 ? 'badge-light-primary' : 'badge-light-warning'">{{ PAPEL[d.papel] }}</span>
                                <span class="text-muted fs-7">Ver alunos</span>
                            </span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
