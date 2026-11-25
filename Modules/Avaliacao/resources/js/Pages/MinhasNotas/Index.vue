<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/** Serve o aluno («As minhas notas») e o encarregado (`educando` + `boletim_base` vêm do portal). */
defineProps({
    anos: { type: Array, required: true },
    educando: { type: Object, default: null },
    boletim_base: { type: String, default: '/minhas-notas' },
});
defineOptions({ layout: AppLayout });
</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="mb-6">
            <Link v-if="educando" :href="`/portal/${educando.id}`" class="text-muted fs-7 d-inline-block mb-2">← {{ educando.nome }}</Link>
            <h1 class="fs-2 fw-bold mb-1">{{ educando ? `Notas de ${educando.nome}` : 'As minhas notas' }}</h1>
            <div class="text-body-secondary">Resultados finais de cada ano lectivo. Só aparecem depois de a pauta ser aprovada pela escola.</div>
        </div>

        <div v-if="!anos.length" class="card card-flush">
            <div class="card-body text-center text-body-secondary py-10">Ainda não há notas aprovadas para consultar.</div>
        </div>

        <div v-for="ano in anos" :key="ano.matricula_id" class="card card-flush mb-6">
            <div class="card-header pt-5">
                <div class="card-title flex-column align-items-start">
                    <h3 class="fw-bold mb-1">{{ ano.ano_lectivo }}</h3>
                    <div class="text-body-secondary fs-7">{{ ano.turma }}</div>
                </div>
                <div class="card-toolbar">
                    <a :href="`${boletim_base}/${ano.matricula_id}/boletim`" class="btn btn-sm btn-light">Boletim (PDF)</a>
                </div>
            </div>
            <div class="card-body pt-3">
                <div class="table-responsive">
                    <table class="table table-row-dashed align-middle gs-0 gy-3 mb-0">
                        <thead>
                            <tr class="fw-bold text-body-secondary fs-7 text-uppercase">
                                <th>{{ ano.qualitativa ? 'Área' : 'Disciplina' }}</th>
                                <th v-for="c in ano.colunas" :key="c" class="text-center">{{ c }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in ano.linhas" :key="l.disciplina">
                                <td class="fw-semibold">{{ l.disciplina }}</td>
                                <td v-for="(v, i) in l.valores" :key="i" class="text-center">{{ v ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="ano.resultado" class="mt-4">
                    <span class="text-body-secondary me-2">Resultado:</span>
                    <span class="badge badge-light-primary fs-6">{{ ano.resultado }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
