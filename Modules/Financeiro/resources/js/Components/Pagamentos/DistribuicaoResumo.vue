<script setup>
import { formatarDinheiro } from '../../Support/dinheiro';

const props = defineProps({
    plano: { type: Object, required: true }, // PlanoDePagamentoDTO::toArray()
    moeda: { type: Object, required: true },
});

const totalLinha = (linha) => (linha.saldo_antes ?? 0) + (linha.multa_pendente_antes ?? 0);
const creditoLinha = (linha) => (linha.credito ?? 0) + (linha.multa_credito ?? 0);
const dinheiroLinha = (linha) => (linha.dinheiro ?? 0) + (linha.multa_dinheiro ?? 0);
const emDividaDepois = (linha) => (linha.saldo_depois ?? 0) + (linha.multa_pendente_depois ?? 0);
const soma = (fn) => props.plano.linhas.reduce((t, l) => t + fn(l), 0);
</script>

<template>
    <div>
        <!-- Totais em destaque -->
        <div class="row g-4 mb-6">
            <div class="col-12 col-md-4">
                <div class="rounded border border-success bg-light-success px-6 py-5 h-100">
                    <div class="text-success fs-7 fw-bold text-uppercase mb-1">Dinheiro a receber</div>
                    <div class="fs-2hx fw-bolder text-success lh-1">{{ formatarDinheiro(plano.dinheiro, moeda) }}</div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="rounded bg-body-secondary px-6 py-5 h-100">
                    <div class="text-muted fs-7 fw-bold text-uppercase mb-1">Total para propinas</div>
                    <div class="fs-1 fw-bold text-gray-900 lh-1">{{ formatarDinheiro(plano.total_distribuido, moeda) }}</div>
                    <div v-if="plano.credito" class="text-muted fs-7 mt-2">inclui {{ formatarDinheiro(plano.credito, moeda) }} de crédito do aluno</div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="rounded bg-body-secondary px-6 py-5 h-100">
                    <div class="text-muted fs-7 fw-bold text-uppercase mb-1">Crédito novo do aluno</div>
                    <div class="fs-1 fw-bold lh-1" :class="plano.excedente ? 'text-primary' : 'text-gray-900'">{{ formatarDinheiro(plano.excedente, moeda) }}</div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-row-dashed align-middle fs-5 gy-4 mb-0">
                <thead>
                    <tr class="text-muted fw-bold fs-7 text-uppercase border-bottom border-gray-300">
                        <th>Período</th>
                        <th class="text-end">Capital</th>
                        <th class="text-end">Multa</th>
                        <th class="text-end">Total em dívida</th>
                        <th class="text-end">Crédito</th>
                        <th class="text-end">Dinheiro</th>
                        <th class="text-end">Fica em dívida</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="plano.linhas.length === 0"><td colspan="7" class="text-muted">Nenhuma propina recebe valor.</td></tr>
                    <tr v-for="linha in plano.linhas" :key="linha.propina_id">
                        <td class="fw-bold text-gray-900">{{ linha.periodo }}</td>
                        <td class="text-end">{{ formatarDinheiro(linha.saldo_antes, moeda) }}</td>
                        <td class="text-end" :class="linha.multa_pendente_antes ? 'text-danger' : 'text-muted'">{{ linha.multa_pendente_antes ? formatarDinheiro(linha.multa_pendente_antes, moeda) : '—' }}</td>
                        <td class="text-end fw-bold text-gray-900">{{ formatarDinheiro(totalLinha(linha), moeda) }}</td>
                        <td class="text-end" :class="creditoLinha(linha) ? '' : 'text-muted'">{{ creditoLinha(linha) ? formatarDinheiro(creditoLinha(linha), moeda) : '—' }}</td>
                        <td class="text-end fw-bold" :class="dinheiroLinha(linha) ? 'text-success' : 'text-muted'">{{ dinheiroLinha(linha) ? formatarDinheiro(dinheiroLinha(linha), moeda) : '—' }}</td>
                        <td class="text-end fw-bold" :class="emDividaDepois(linha) ? 'text-danger' : 'text-success'">{{ formatarDinheiro(emDividaDepois(linha), moeda) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="plano.linhas.length > 1">
                    <tr class="fw-bolder fs-4 border-top border-gray-400">
                        <td>Total</td>
                        <td class="text-end">{{ formatarDinheiro(soma((l) => l.saldo_antes ?? 0), moeda) }}</td>
                        <td class="text-end">{{ formatarDinheiro(soma((l) => l.multa_pendente_antes ?? 0), moeda) }}</td>
                        <td class="text-end">{{ formatarDinheiro(soma(totalLinha), moeda) }}</td>
                        <td class="text-end">{{ formatarDinheiro(soma(creditoLinha), moeda) }}</td>
                        <td class="text-end text-success">{{ formatarDinheiro(soma(dinheiroLinha), moeda) }}</td>
                        <td class="text-end" :class="soma(emDividaDepois) ? 'text-danger' : 'text-success'">{{ formatarDinheiro(soma(emDividaDepois), moeda) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
