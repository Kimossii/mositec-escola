<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { formatarDinheiro } from '../../Support/dinheiro';

const props = defineProps({
    catalogo: { type: Object, required: true },
    metodos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    hoje: { type: String, required: true },
    matriculaInicial: { type: Object, default: null },
    voltarPara: { type: String, default: null },
});
defineOptions({ layout: AppLayout });

const dinheiro = (v) => formatarDinheiro(v, props.moeda);

// 1. Aluno (matrícula)
const matricula = ref(props.matriculaInicial);
const termo = ref('');
const resultados = ref([]);
let espera = null;
function pesquisar() {
    clearTimeout(espera);
    espera = setTimeout(async () => {
        if (termo.value.trim().length < 2) { resultados.value = []; return; }
        const { data } = await axios.get('/financeiro/cobrancas/matriculas', { params: { q: termo.value } });
        resultados.value = data.matriculas;
    }, 250);
}
function escolher(m) { matricula.value = m; resultados.value = []; termo.value = ''; }

// 2. Itens
const opcoesItem = computed(() => [
    ...props.catalogo.produtos.map((p) => ({ value: `produto-${p.id}`, label: `Produto · ${p.nome} — ${dinheiro(p.preco)}` })),
    ...props.catalogo.servicos.map((s) => ({ value: `servico-${s.id}`, label: `Serviço · ${s.nome} — ${dinheiro(s.preco)}` })),
]);
const novo = reactive({ chave: '', variante_id: '', quantidade: 1 });
const itens = ref([]);

const catalogoDe = (chave) => {
    const [tipo, id] = chave.split('-');
    return tipo === 'produto'
        ? { tipo, ...props.catalogo.produtos.find((p) => p.id === Number(id)) }
        : { tipo, ...props.catalogo.servicos.find((s) => s.id === Number(id)) };
};
const itemEscolhido = computed(() => (novo.chave ? catalogoDe(novo.chave) : null));
const opcoesVariante = computed(() => (itemEscolhido.value?.variantes ?? []).map((v) => ({
    value: v.id,
    label: itemEscolhido.value.controla_stock ? `${v.nome} (stock ${v.stock_quantidade})` : v.nome,
})));

function adicionar() {
    const base = itemEscolhido.value;
    if (!base) return;
    if (base.tem_variantes && !novo.variante_id) { toast.error('Escolha o tamanho.'); return; }
    const variante = base.variantes?.find((v) => v.id === novo.variante_id);
    itens.value.push({
        tipo: base.tipo, id: base.id, variante_id: novo.variante_id || null, quantidade: Math.max(1, Number(novo.quantidade) || 1),
        nome: base.nome + (variante ? ` · ${variante.nome}` : ''), preco: base.preco, obrigatorio: base.obrigatorio,
    });
    Object.assign(novo, { chave: '', variante_id: '', quantidade: 1 });
}
const remover = (i) => itens.value.splice(i, 1);
const total = computed(() => itens.value.reduce((s, i) => s + i.preco * i.quantidade, 0));

// 3. Condições e 4. Pagamento
const form = reactive({ data_limite: '', observacao: '', pagar_agora: false, metodo_pagamento_id: '', data_pagamento: props.hoje, referencia: '' });
const comprovativo = ref(null);
const opcoesMetodo = props.metodos.map((m) => ({ value: m.id, label: m.nome }));
const metodoEscolhido = computed(() => props.metodos.find((m) => m.id === form.metodo_pagamento_id));

const erros = ref({});
const processando = ref(false);
const podeGuardar = computed(() => matricula.value && itens.value.length > 0 && !processando.value && (!form.pagar_agora || form.metodo_pagamento_id));

function guardar() {
    processando.value = true;
    erros.value = {};
    const dados = {
        matricula_id: matricula.value.id,
        itens: itens.value.map((i) => ({ tipo: i.tipo, id: i.id, variante_id: i.variante_id, quantidade: i.quantidade })),
        data_limite: form.data_limite || null,
        observacao: form.observacao || null,
        pagar_agora: form.pagar_agora,
        ...(form.pagar_agora ? { metodo_pagamento_id: form.metodo_pagamento_id, data_pagamento: form.data_pagamento, referencia: form.referencia || null, ...(comprovativo.value ? { comprovativo: comprovativo.value } : {}) } : {}),
    };
    router.post('/financeiro/cobrancas', dados, {
        forceFormData: true,
        onError: (e) => { erros.value = e; toast.error(Object.values(e)[0]); },
        onFinish: () => { processando.value = false; },
    });
}
const erroDoItem = computed(() => Object.entries(erros.value).filter(([k]) => k === 'itens' || k.startsWith('itens.')).map(([, v]) => v));
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar :href="voltarPara ?? '/financeiro/cobrancas'" />
        <h1 class="fs-2 fw-bold mb-1">Nova cobrança</h1>
        <p class="text-muted fs-6 mb-6">Cobre produtos e serviços a um aluno. Pode deixar em aberto ou pagar logo.</p>

        <!-- 1. Aluno -->
        <div class="card mb-6">
            <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">1. Aluno</h3></div>
            <div class="card-body">
                <div v-if="matricula" class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-uppercase fs-5"><strong>{{ matricula.aluno_nome }}</strong> · nº de estudante {{ matricula.aluno_numero }} · matrícula {{ matricula.numero }}</div>
                        <div class="text-muted fs-7">{{ matricula.turma_nome ?? '—' }} · {{ matricula.ano_lectivo_nome ?? '—' }} · {{ matricula.estado_descricao }}</div>
                    </div>
                    <button type="button" class="btn btn-light btn-sm" @click="matricula = null">Trocar</button>
                </div>
                <div v-else>
                    <input v-model="termo" type="text" class="form-control form-control-solid" placeholder="Nome, nº de estudante ou nº da matrícula" @input="pesquisar" />
                    <div v-for="m in resultados" :key="m.id" class="py-3 border-bottom border-dashed" role="button" @click="escolher(m)">
                        <strong class="text-uppercase">{{ m.aluno_nome }}</strong> <span class="text-muted">· nº {{ m.aluno_numero }}</span>
                        <div class="text-muted fs-7">Matrícula {{ m.numero }} · {{ m.turma_nome ?? '—' }} · {{ m.ano_lectivo_nome ?? '—' }} · {{ m.estado_descricao }}</div>
                    </div>
                    <div class="text-danger fs-7 mt-1">{{ erros.matricula_id }}</div>
                </div>
            </div>
        </div>

        <!-- 2. Itens -->
        <div class="card mb-6">
            <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">2. Produtos e serviços</h3></div>
            <div class="card-body">
                <div class="row g-4 align-items-end mb-5">
                    <div class="col-12 col-md-5">
                        <label class="form-label fw-semibold">Item</label>
                        <SelectSolid v-model="novo.chave" :options="opcoesItem" placeholder="Escolha um produto ou serviço" />
                    </div>
                    <div v-if="itemEscolhido?.tem_variantes" class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Tamanho</label>
                        <SelectSolid v-model="novo.variante_id" :options="opcoesVariante" placeholder="Tamanho" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold">Quantidade</label>
                        <input v-model.number="novo.quantidade" type="number" min="1" max="9999" class="form-control form-control-solid" />
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="button" class="btn btn-light-primary w-100" :disabled="!itemEscolhido" @click="adicionar">Adicionar</button>
                    </div>
                </div>
                <div v-if="itemEscolhido?.controla_stock && !itemEscolhido.tem_variantes" class="text-muted fs-7 mb-3">Em stock: {{ itemEscolhido.stock_quantidade }}</div>

                <table v-if="itens.length" class="table align-middle table-row-dashed fs-6 gy-3 mb-0">
                    <thead><tr class="text-muted fw-bold fs-7 text-uppercase"><th>Item</th><th class="text-end">Preço</th><th class="text-center">Qtd.</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody class="fw-semibold text-gray-800">
                        <tr v-for="(i, idx) in itens" :key="idx">
                            <td>{{ i.nome }} <span v-if="i.obrigatorio" class="badge badge-light-warning ms-1">Obrigatório</span></td>
                            <td class="text-end">{{ dinheiro(i.preco) }}</td>
                            <td class="text-center"><input v-model.number="i.quantidade" type="number" min="1" max="9999" class="form-control form-control-solid form-control-sm w-75px mx-auto" /></td>
                            <td class="text-end">{{ dinheiro(i.preco * i.quantidade) }}</td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-light-danger" @click="remover(idx)">Remover</button></td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="fw-bold fs-5"><td colspan="3" class="text-end">Total</td><td class="text-end">{{ dinheiro(total) }}</td><td></td></tr></tfoot>
                </table>
                <div v-else class="text-muted text-center py-4">Ainda não adicionou nenhum item.</div>
                <div v-for="(e, i) in erroDoItem" :key="i" class="text-danger fs-7 mt-2">{{ e }}</div>
            </div>
        </div>

        <!-- 3. Condições -->
        <div class="card mb-6">
            <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">3. Condições</h3></div>
            <div class="card-body">
                <div class="row g-5">
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Data limite de pagamento</label>
                        <input v-model="form.data_limite" type="date" :min="hoje" class="form-control form-control-solid" />
                        <div class="text-muted fs-8">Opcional.</div>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold">Observação</label>
                        <input v-model="form.observacao" type="text" maxlength="1000" class="form-control form-control-solid" />
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Pagamento -->
        <div class="card mb-6">
            <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">4. Pagamento</h3></div>
            <div class="card-body">
                <div class="form-check form-switch form-check-custom mb-2">
                    <input id="pagar_agora" v-model="form.pagar_agora" type="checkbox" class="form-check-input interruptor" />
                    <label for="pagar_agora" class="form-check-label fw-semibold fs-5">Pagar agora</label>
                </div>
                <div class="form-text mb-4">Desligado, a cobrança fica em aberto (dívida do aluno) e paga-se depois.</div>

                <div v-if="form.pagar_agora" class="row g-5">
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Método</label>
                        <SelectSolid v-model="form.metodo_pagamento_id" :options="opcoesMetodo" placeholder="Selecione" />
                        <div class="text-danger fs-7">{{ erros.metodo_pagamento_id }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Data do pagamento</label>
                        <input v-model="form.data_pagamento" type="date" :max="hoje" class="form-control form-control-solid" />
                        <div class="text-danger fs-7">{{ erros.data_pagamento }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Referência</label>
                        <input v-model="form.referencia" type="text" maxlength="100" class="form-control form-control-solid" />
                        <div class="text-danger fs-7">{{ erros.referencia }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Comprovativo {{ metodoEscolhido?.exige_comprovativo ? '(obrigatório)' : '(opcional)' }}</label>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control form-control-solid" @change="comprovativo = $event.target.files[0] ?? null" />
                        <div class="text-danger fs-7">{{ erros.comprovativo }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center">
            <div class="fs-3 fw-bold">Total: {{ dinheiro(total) }}</div>
            <button type="button" class="btn btn-primary btn-lg" :disabled="!podeGuardar" @click="guardar">
                {{ form.pagar_agora ? 'Criar e pagar' : 'Criar cobrança' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.interruptor { width: 2.75rem; height: 1.5rem; border: 2px solid #64748B !important; background-color: transparent !important; }
.interruptor:checked { background-color: #166534 !important; border-color: #166534 !important; }
</style>
