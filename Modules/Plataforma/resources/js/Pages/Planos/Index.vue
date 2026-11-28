<script setup>
import { Link } from '@inertiajs/vue3';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import { rotuloLimite, rotuloModulos } from '../../Composables/licencas';

defineProps({
    planos: { type: Array, default: () => [] },
});
defineOptions({ layout: LayoutPlataforma });
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-0">Planos</h1>
                <div class="text-muted fs-7">{{ planos.length }} {{ planos.length === 1 ? 'plano' : 'planos' }}</div>
            </div>
            <Link href="/plataforma/planos/novo" class="btn btn-primary">Novo plano</Link>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <EstadoVazio v-if="planos.length === 0" titulo="Ainda não há planos." />

                <!-- Computador: tabela -->
                <table v-else class="table align-middle table-row-dashed fs-6 gy-5 mb-0 d-none d-md-table">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Plano</th>
                            <th>Modalidade</th>
                            <th>Validade</th>
                            <th>Alunos</th>
                            <th>Utilizadores</th>
                            <th>Emails/mês</th>
                            <th class="min-w-150px">Módulos</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-for="plano in planos" :key="plano.id">
                            <td class="text-gray-800 fw-bold">{{ plano.nome }}</td>
                            <td>{{ plano.modalidade_descricao }}</td>
                            <td>{{ plano.validade_dias }} dias</td>
                            <td>{{ rotuloLimite(plano.limite_alunos) }}</td>
                            <td>{{ rotuloLimite(plano.limite_utilizadores) }}</td>
                            <td>{{ rotuloLimite(plano.limite_emails_mes) }}</td>
                            <td>{{ rotuloModulos(plano.modulos) }}</td>
                            <td class="text-end">
                                <Link :href="`/plataforma/planos/${plano.id}/editar`" class="btn btn-sm btn-light-primary">Editar</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Telemóvel: um cartão por plano -->
                <div v-if="planos.length" class="d-md-none">
                    <div v-for="plano in planos" :key="plano.id" class="p-5 border-bottom border-gray-200">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                            <div class="text-gray-800 fw-bold fs-5">{{ plano.nome }}</div>
                            <span class="badge badge-light-primary">{{ plano.modalidade_descricao }}</span>
                        </div>
                        <div class="text-muted fs-7">{{ plano.validade_dias }} dias · Alunos: {{ rotuloLimite(plano.limite_alunos) }} · Utilizadores: {{ rotuloLimite(plano.limite_utilizadores) }} · Emails/mês: {{ rotuloLimite(plano.limite_emails_mes) }}</div>
                        <div class="text-gray-700 fs-7 mb-3">Módulos: {{ rotuloModulos(plano.modulos) }}</div>
                        <Link :href="`/plataforma/planos/${plano.id}/editar`" class="btn btn-sm btn-light-primary w-100">Editar</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
