<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import DistribuicaoResumo from './DistribuicaoResumo.vue';
import { MODO_DISTRIBUICAO } from '../../Models/Pagamento';
import { formatarDinheiro, unidadesMenoresParaDecimal } from '../../Support/dinheiro';

const props = defineProps({
    show: { type: Boolean, default: false },
    matriculas: { type: Array, required: true }, // [{id, numero, ano_lectivo_nome, turma_nome, estado_descricao}]
    saldoDisponivel: { type: Number, required: true },
    moeda: { type: Object, required: true },
});
const emit = defineEmits(['fechar']);

const matriculaId = ref(''); // nada pré-seleccionado
const valor = ref('');
const plano = ref(null);
const erro = ref('');
const aPrever = ref(false);
const processing = ref(false);

watch(() => props.show, (show) => {
    if (!show) return;
    matriculaId.value = '';
    valor.value = '';
    plano.value = null;
    erro.value = '';
});

async function preVisualizar() {
    aPrever.value = true;
    erro.value = '';
    plano.value = null;

    try {
        const { data } = await axios.post(`/financeiro/creditos/matriculas/${matriculaId.value}/pre-visualizar`, { valor_credito: valor.value, modo_distribuicao: MODO_DISTRIBUICAO.AUTOMATICA });
        plano.value = data.plano;
    } catch (e) {
        erro.value = e.response?.status === 422 ? Object.values(e.response.data.errors)[0][0] : 'Não foi possível calcular.';
    } finally {
        aPrever.value = false;
    }
}

function aplicar() {
    if (processing.value) return;
    processing.value = true;
    const distribuicao = plano.value.linhas.map((l) => ({ propina_id: l.propina_id, valor: unidadesMenoresParaDecimal(l.credito, props.moeda) }));

    router.post(`/financeiro/creditos/matriculas/${matriculaId.value}/aplicar`, { valor_credito: valor.value, modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Crédito aplicado.');
            emit('fechar');
        },
        onError: (erros) => {
            erro.value = Object.values(erros)[0];
            plano.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('fechar')">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-6">
                <h3 class="mb-2">Aplicar crédito</h3>
                <p class="text-muted fs-6 mb-5">Disponível: {{ formatarDinheiro(saldoDisponivel, moeda) }}. Usa os créditos mais antigos primeiro, nas propinas mais antigas da matrícula.</p>

                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-7">
                        <label class="form-label required fw-semibold">Matrícula</label>
                        <SelectSolid v-model="matriculaId" @update:model-value="plano = null" :options="matriculas.map((m) => ({ value: m.id, label: `${m.numero} · ${m.ano_lectivo_nome ?? '—'} · ${m.turma_nome ?? '—'} (${m.estado_descricao})` }))" />
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label required fw-semibold">Valor ({{ moeda.simbolo }})</label>
                        <input v-model="valor" type="text" inputmode="decimal" class="form-control form-control-solid" :placeholder="`Máx. ${unidadesMenoresParaDecimal(saldoDisponivel, moeda)}`" @input="plano = null" />
                    </div>
                </div>

                <div v-if="erro" class="alert alert-danger fs-7 py-3">{{ erro }}</div>
                <DistribuicaoResumo v-if="plano" :plano="plano" :moeda="moeda" />

                <div class="d-flex justify-content-end gap-3 mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="emit('fechar')">Fechar</button>
                    <button v-if="!plano" type="button" class="btn btn-primary" :disabled="aPrever || !matriculaId || !valor" @click="preVisualizar">{{ aPrever ? 'A calcular…' : 'Ver resumo' }}</button>
                    <button v-else type="button" class="btn btn-primary" :disabled="processing" @click="aplicar">{{ processing ? 'A aplicar…' : 'Aplicar crédito' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
