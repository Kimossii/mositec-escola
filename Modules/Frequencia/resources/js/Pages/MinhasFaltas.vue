<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';

/** «As minhas faltas»: um bloco por matrícula, com o total, o que está justificado e o desdobramento por período e disciplina. */
defineProps({
    blocos: { type: Array, required: true },
    educando: { type: Object, default: null }, // só no portal do encarregado
});
defineOptions({ layout: AppLayout });

const unidade = (b, n) => (b.resumo.unidade === 'dias' ? (n === 1 ? 'dia' : 'dias') : (n === 1 ? 'aula' : 'aulas'));
</script>

<template>
    <div class="app-container container-xxl py-6">
        <Link v-if="educando" :href="`/portal/${educando.id}`" class="text-muted fs-7 d-inline-block mb-2">← {{ educando.nome }}</Link>
        <h1 class="fs-2 fw-bold mb-6">{{ educando ? `Faltas de ${educando.nome}` : 'As minhas faltas' }}</h1>

        <div v-if="!blocos.length" class="card">
            <div class="card-body"><EstadoVazio titulo="Sem matrículas" descricao="Quando tiver uma matrícula, as suas faltas aparecem aqui." /></div>
        </div>

        <div v-for="b in blocos" :key="b.matricula_id" class="card mb-6">
            <div class="card-header min-h-60px">
                <h3 class="card-title fs-5">{{ b.ano_lectivo }} · {{ b.turma }}</h3>
            </div>
            <div class="card-body">
                <div class="row g-4 mb-6">
                    <div class="col-12 col-md-4">
                        <div class="rounded bg-body-secondary p-4">
                            <div class="fs-2 fw-bold">{{ b.resumo.total }}</div>
                            <div class="text-muted fs-7">{{ unidade(b, b.resumo.total) }} de falta</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="rounded bg-body-secondary p-4">
                            <div class="fs-2 fw-bold text-success">{{ b.resumo.justificadas }}</div>
                            <div class="text-muted fs-7">justificadas</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="rounded bg-body-secondary p-4">
                            <div class="fs-2 fw-bold" :class="b.resumo.injustificadas ? 'text-danger' : ''">{{ b.resumo.injustificadas }}</div>
                            <div class="text-muted fs-7">por justificar</div>
                        </div>
                    </div>
                </div>

                <div class="row g-6">
                    <div v-if="b.resumo.por_periodo.length" class="col-12 col-lg-6">
                        <h4 class="fs-6 fw-bold mb-3">Por período</h4>
                        <div class="table-responsive">
                            <table class="table table-row-bordered align-middle gs-3 gy-2 mb-0">
                                <thead><tr class="fw-bold fs-8 text-muted text-uppercase"><th>Período</th><th class="text-end">Faltas</th><th class="text-end">Justificadas</th></tr></thead>
                                <tbody>
                                    <tr v-for="p in b.resumo.por_periodo" :key="p.periodo_id">
                                        <td>{{ p.nome }}</td><td class="text-end">{{ p.total }}</td><td class="text-end">{{ p.justificadas }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div v-if="b.por_disciplina.length" class="col-12 col-lg-6">
                        <h4 class="fs-6 fw-bold mb-3">Por disciplina</h4>
                        <div class="table-responsive">
                            <table class="table table-row-bordered align-middle gs-3 gy-2 mb-0">
                                <thead><tr class="fw-bold fs-8 text-muted text-uppercase"><th>Disciplina</th><th class="text-end">Faltas</th><th class="text-end">Justificadas</th></tr></thead>
                                <tbody>
                                    <tr v-for="d in b.por_disciplina" :key="d.disciplina_id">
                                        <td>{{ d.nome }}</td><td class="text-end">{{ d.total }}</td><td class="text-end">{{ d.justificadas }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
