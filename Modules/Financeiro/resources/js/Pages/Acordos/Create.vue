<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { formatarDinheiro } from '../../Support/dinheiro';

const props = defineProps({
    permiteNegociacao: { type: Boolean, default: false },
    descontoMaximo: { type: Number, default: 0 },
    moeda: { type: Object, required: true },
    hoje: { type: String, required: true },
    matriculaInicial: { type: Object, default: null },
});
defineOptions({ layout: AppLayout });

const dinheiro = (v) => formatarDinheiro(v, props.moeda);

const matricula = ref(null);
const propinas = ref([]);
const escolhidas = ref([]);
const termo = ref('');
const resultados = ref([]);
let espera = null;

async function escolher(m) {
    matricula.value = m; resultados.value = []; termo.value = '';
    const { data } = await axios.get(`/financeiro/acordos/matriculas/${m.id}/propinas`);
    propinas.value = data.propinas;
    escolhidas.value = data.propinas.map((p) => p.id);
}
if (props.matriculaInicial) escolher(props.matriculaInicial);
function pesquisar() {
    clearTimeout(espera);
    espera = setTimeout(async () => {
        if (termo.value.trim().length < 2) { resultados.value = []; return; }
        const { data } = await axios.get('/financeiro/acordos/matriculas', { params: { q: termo.value } });
        resultados.value = data.matriculas;
    }, 250);
}

const form = reactive({ desconto_multa_pct: 0, prestacoes: 3, primeiro_vencimento: props.hoje, motivo: '' });
const seleccionadas = computed(() => propinas.value.filter((p) => escolhidas.value.includes(p.id)));
const capital = computed(() => seleccionadas.value.reduce((s, p) => s + p.capital, 0));
const multa = computed(() => seleccionadas.value.reduce((s, p) => s + p.multa, 0));
const desconto = computed(() => Math.round((multa.value * (Number(form.desconto_multa_pct) || 0)) / 100));
const total = computed(() => capital.value + multa.value - desconto.value);
const calendario = computed(() => {
    const n = Math.max(1, Math.min(36, Number(form.prestacoes) || 1));
    const base = Math.floor(total.value / n);
    const [a, m, d] = form.primeiro_vencimento.split('-').map(Number);

    return Array.from({ length: n }, (_, i) => {
        const data = new Date(a, m - 1 + i, d);
        const fim = new Date(a, m + i, 0).getDate();
        if (data.getDate() !== d) data.setDate(fim);

        return { ordem: i + 1, data: `${String(data.getDate()).padStart(2, '0')}/${String(data.getMonth() + 1).padStart(2, '0')}/${data.getFullYear()}`, valor: i === n - 1 ? total.value - base * (n - 1) : base };
    });
});

const erros = ref({});
const processando = ref(false);
const podeGuardar = computed(() => matricula.value && escolhidas.value.length > 0 && form.motivo.trim().length >= 5 && !processando.value);
function guardar() {
    processando.value = true; erros.value = {};
    router.post('/financeiro/acordos', { matricula_id: matricula.value.id, propinas: escolhidas.value, ...form }, {
        onError: (e) => { erros.value = e; toast.error(Object.values(e)[0]); },
        onFinish: () => { processando.value = false; },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/financeiro/acordos" />
        <h1 class="fs-2 fw-bold mb-1">Novo acordo de dívida</h1>
        <p class="text-muted fs-6 mb-6">Divida o que o aluno deve em prestações. As multas ficam congeladas enquanto o acordo estiver activo.</p>

        <div v-if="!permiteNegociacao" class="alert alert-warning">A escola não permite negociação de dívidas. Ligue "Permitir negociação" em Configurações → Financeiro → Regras de Cobrança.</div>

        <template v-else>
            <div class="card mb-6">
                <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">1. Aluno</h3></div>
                <div class="card-body">
                    <div v-if="matricula" class="d-flex justify-content-between align-items-center">
                        <div><div class="text-uppercase fs-5"><strong>{{ matricula.aluno_nome }}</strong> · nº de estudante {{ matricula.aluno_numero }} · matrícula {{ matricula.numero }}</div><div class="text-muted fs-7">{{ matricula.turma_rotulo ?? '—' }} · {{ matricula.ano_lectivo_nome ?? '—' }} · {{ matricula.estado_descricao }}</div></div>
                        <button type="button" class="btn btn-light btn-sm" @click="matricula = null; propinas = []; escolhidas = []">Trocar</button>
                    </div>
                    <div v-else>
                        <input v-model="termo" type="text" class="form-control form-control-solid" placeholder="Nome, nº de estudante ou nº da matrícula" @input="pesquisar" />
                        <div v-for="m in resultados" :key="m.id" class="py-3 border-bottom border-dashed" role="button" @click="escolher(m)">
                            <strong class="text-uppercase">{{ m.aluno_nome }}</strong> <span class="text-muted">· nº {{ m.aluno_numero }}</span>
                            <div class="text-muted fs-7">Matrícula {{ m.numero }} · {{ m.ano_lectivo_nome }} · {{ m.estado_descricao }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="matricula" class="card mb-6">
                <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">2. O que entra no acordo</h3></div>
                <div class="card-body">
                    <p v-if="propinas.length === 0" class="text-muted mb-0">Esta matrícula não tem propinas em dívida (ou já estão noutro acordo).</p>
                    <label v-for="p in propinas" :key="p.id" class="d-flex align-items-center gap-3 py-2 border-bottom border-dashed" role="button">
                        <input v-model="escolhidas" type="checkbox" :value="p.id" class="form-check-input caixa" />
                        <span class="fw-semibold flex-grow-1">{{ p.periodo }}</span>
                        <span>{{ dinheiro(p.capital) }}<span v-if="p.multa" class="text-danger"> + multa {{ dinheiro(p.multa) }}</span></span>
                    </label>
                </div>
            </div>

            <div v-if="propinas.length" class="card mb-6">
                <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">3. Condições</h3></div>
                <div class="card-body">
                    <div class="row g-5">
                        <div class="col-6 col-md-3"><label class="form-label fw-semibold">Desconto nas multas (%)</label><input v-model.number="form.desconto_multa_pct" type="number" min="0" :max="descontoMaximo" class="form-control form-control-solid" /><div class="text-muted fs-8">Máximo da escola: {{ descontoMaximo }}%.</div><div class="text-danger fs-7">{{ erros.desconto_multa_pct }}</div></div>
                        <div class="col-6 col-md-3"><label class="form-label fw-semibold">Nº de prestações</label><input v-model.number="form.prestacoes" type="number" min="1" max="36" class="form-control form-control-solid" /><div class="text-danger fs-7">{{ erros.prestacoes }}</div></div>
                        <div class="col-12 col-md-3"><label class="form-label fw-semibold">1.ª prestação em</label><input v-model="form.primeiro_vencimento" type="date" :min="hoje" class="form-control form-control-solid" /><div class="text-danger fs-7">{{ erros.primeiro_vencimento }}</div></div>
                        <div class="col-12"><label class="form-label required fw-semibold">Motivo do acordo</label><input v-model="form.motivo" type="text" maxlength="1000" class="form-control form-control-solid" /><div class="text-danger fs-7">{{ erros.motivo }}</div></div>
                    </div>

                    <div class="bg-body-secondary rounded p-4 mt-6">
                        <div class="d-flex justify-content-between"><span>Capital</span><strong>{{ dinheiro(capital) }}</strong></div>
                        <div class="d-flex justify-content-between"><span>Multas</span><strong>{{ dinheiro(multa) }}</strong></div>
                        <div class="d-flex justify-content-between text-success"><span>Desconto nas multas</span><strong>− {{ dinheiro(desconto) }}</strong></div>
                        <div class="d-flex justify-content-between fs-4 border-top pt-2 mt-2"><span>Total a pagar em prestações</span><strong>{{ dinheiro(total) }}</strong></div>
                    </div>

                    <table class="table align-middle table-row-dashed fs-6 gy-2 mt-4 mb-0">
                        <thead><tr class="text-muted fw-bold fs-7 text-uppercase"><th>Prestação</th><th>Vencimento</th><th class="text-end">Valor</th></tr></thead>
                        <tbody class="fw-semibold"><tr v-for="c in calendario" :key="c.ordem"><td>{{ c.ordem }}.ª</td><td>{{ c.data }}</td><td class="text-end">{{ dinheiro(c.valor) }}</td></tr></tbody>
                    </table>
                    <div class="text-danger fs-7 mt-2">{{ erros.propinas }}{{ erros.acordo }}</div>
                </div>
            </div>

            <div v-if="propinas.length" class="text-end">
                <button type="button" class="btn btn-primary btn-lg" :disabled="!podeGuardar" @click="guardar">Criar acordo</button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.caixa { border: 2px solid #64748B !important; background-color: transparent !important; }
.caixa:checked { background-color: #166534 !important; border-color: #166534 !important; }
</style>
