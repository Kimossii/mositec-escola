<script setup>
import { tipoPeriodoLabel } from '../../Models/AnoLectivo';

defineProps({
    periodos: { type: Array, required: true },
    podeEditar: { type: Boolean, default: false },
    podeEliminar: { type: Boolean, default: false },
});
defineEmits(['editar-periodo', 'eliminar-periodo']);
</script>

<template>
    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
        <thead>
            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                <th class="min-w-150px">Nome</th>
                <th class="min-w-100px">Tipo</th>
                <th class="min-w-75px">Número</th>
                <th class="min-w-200px">Período</th>
                <th class="text-end min-w-125px">Ações</th>
            </tr>
        </thead>
        <tbody class="text-gray-600 fw-semibold">
            <tr v-if="periodos.length === 0">
                <td colspan="5" class="text-center text-muted py-6">Nenhum período criado.</td>
            </tr>
            <tr v-for="periodo in periodos" :key="periodo.id">
                <td>{{ periodo.nome }}</td>
                <td>{{ tipoPeriodoLabel(periodo.tipo) }}</td>
                <td>{{ periodo.numero ?? '—' }}</td>
                <td>{{ periodo.data_inicio }} — {{ periodo.data_fim }}</td>
                <td class="text-end">
                    <button v-if="podeEditar" class="btn btn-light-primary btn-sm me-2" @click="$emit('editar-periodo', periodo)">
                        Editar
                    </button>
                    <button v-if="podeEliminar" class="btn btn-light-danger btn-sm" @click="$emit('eliminar-periodo', periodo)">
                        Eliminar
                    </button>
                </td>
            </tr>
        </tbody>
    </table>
</template>
