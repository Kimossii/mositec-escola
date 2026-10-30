<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvatarUtilizador from '@/Components/Shared/AvatarUtilizador.vue';
import { iniciais } from './iniciais';

defineProps({
    aluno: { type: Object, required: true }, // { aluno_id, nome, numero_matricula, foto_url, turmas: [{ turma_id, turma_rotulo, disciplinas: [nome] }] }
});
defineOptions({ layout: AppLayout });
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6">
            <Link href="/professor/alunos" class="text-muted fs-7">← Os meus alunos</Link>
            <div class="d-flex align-items-center gap-4 mt-3">
                <AvatarUtilizador :foto="aluno.foto_url" :iniciais="iniciais(aluno.nome)" tamanho="symbol-75px" circulo />
                <div>
                    <h1 class="fs-2 fw-bold mb-1">{{ aluno.nome }}</h1>
                    <div class="text-muted">N.º de matrícula {{ aluno.numero_matricula ?? '—' }}</div>
                </div>
            </div>
        </div>

        <div class="card card-flush">
            <div class="card-header">
                <h3 class="card-title fw-bold">Turmas e disciplinas em comum</h3>
            </div>
            <div class="card-body pt-0">
                <div v-for="t in aluno.turmas" :key="t.turma_id" class="d-flex flex-stack py-3 border-bottom">
                    <span class="fw-semibold">{{ t.turma_rotulo }}</span>
                    <span class="text-muted">{{ t.disciplinas.join(', ') }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
