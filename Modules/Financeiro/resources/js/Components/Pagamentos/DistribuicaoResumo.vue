<script setup>
import { formatarDinheiro } from '../../Support/dinheiro';

defineProps({
    plano: { type: Object, required: true }, // PlanoDePagamentoDTO::toArray()
    moeda: { type: Object, required: true },
});
</script>

<template>
    <div>
        <div class="table-responsive">
            <table class="table table-row-dashed align-middle fs-7 gy-2 mb-4">
                <thead>
                    <tr class="text-muted fw-bold text-uppercase">
                        <th>Período</th>
                        <th class="text-end">Em dívida</th>
                        <th class="text-end">Crédito</th>
                        <th class="text-end">Dinheiro</th>
                        <th class="text-end">Fica em dívida</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="plano.linhas.length === 0"><td colspan="5" class="text-muted">Nenhuma propina recebe valor.</td></tr>
                    <tr v-for="linha in plano.linhas" :key="linha.propina_id">
                        <td>{{ linha.periodo }}</td>
                        <td class="text-end">{{ formatarDinheiro(linha.saldo_antes, moeda) }}</td>
                        <td class="text-end">{{ linha.credito ? formatarDinheiro(linha.credito, moeda) : '—' }}</td>
                        <td class="text-end">{{ linha.dinheiro ? formatarDinheiro(linha.dinheiro, moeda) : '—' }}</td>
                        <td class="text-end">{{ formatarDinheiro(linha.saldo_depois, moeda) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card bg-body-secondary">
            <div class="card-body py-4 fs-7 d-flex flex-wrap gap-6">
                <div>Dinheiro recebido: <strong>{{ formatarDinheiro(plano.dinheiro, moeda) }}</strong></div>
                <div>Crédito usado: <strong>{{ formatarDinheiro(plano.credito, moeda) }}</strong></div>
                <div>Total para propinas: <strong>{{ formatarDinheiro(plano.total_distribuido, moeda) }}</strong></div>
                <div>Crédito novo do aluno: <strong>{{ formatarDinheiro(plano.excedente, moeda) }}</strong></div>
            </div>
        </div>
    </div>
</template>
