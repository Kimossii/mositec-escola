<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';

defineProps({
    turno: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/turnos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ turno.nome }}</h1>
                <span v-if="turno.descricao" class="text-muted">{{ turno.descricao }}</span>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Estado</div>
                    <div class="col-md-9">
                        <EstadoBadge :estado="turno.estado" :estado-descricao="turno.estado_descricao" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header">
                <h3 class="card-title fw-bold">Horários</h3>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-50px">Ordem</th>
                            <th>Horário</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="turno.turno_horarios.length === 0">
                            <td colspan="2" class="text-center text-muted py-6">Nenhum horário associado a este turno.</td>
                        </tr>
                        <tr v-for="th in turno.turno_horarios" :key="th.id">
                            <td>{{ th.ordem }}</td>
                            <td>{{ th.horario?.nome }} ({{ th.horario?.hora_inicio }} - {{ th.horario?.hora_fim }})</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title fw-bold">Turmas</h3>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-150px">Ano Letivo</th>
                            <th class="min-w-150px">Nível Académico</th>
                            <th class="min-w-125px">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="turno.turmas.length === 0">
                            <td colspan="5" class="text-center text-muted py-6">Nenhuma turma associada a este turno.</td>
                        </tr>
                        <tr v-for="turma in turno.turmas" :key="turma.id">
                            <td>
                                <a :href="`/turmas/${turma.id}`" class="text-gray-800 text-hover-primary">{{ turma.codigo }}</a>
                            </td>
                            <td>{{ turma.nome }}</td>
                            <td>{{ turma.ano_lectivo?.nome ?? '—' }}</td>
                            <td>{{ turma.nivel_academico?.nome ?? '—' }}</td>
                            <td>
                                <EstadoBadge :estado="turma.estado" :estado-descricao="turma.estado_descricao" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
