<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    cobranca: { type: Object, required: true },
    metodos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    hoje: { type: String, required: true },
    podeCriar: { type: Boolean, default: false },
    podeCancelar: { type: Boolean, default: false },
});
defineOptions({ layout: AppLayout });

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const emAberto = [1, 2].includes(props.cobranca.estado);
const form = reactive({ metodo_pagamento_id: '', data_pagamento: props.hoje, referencia: '' });
const comprovativo = ref(null);
const erros = ref({});
const processando = ref(false);
const opcoesMetodo = props.metodos.map((m) => ({ value: m.id, label: m.nome }));
const metodoEscolhido = () => props.metodos.find((m) => m.id === form.metodo_pagamento_id);

function pagar() {
    processando.value = true;
    erros.value = {};
    router.post(`/financeiro/cobrancas/${props.cobranca.id}/pagar`, { ...form, ...(comprovativo.value ? { comprovativo: comprovativo.value } : {}) }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => toast.success('Cobrança paga.'),
        onError: (e) => { erros.value = e; toast.error(Object.values(e)[0]); },
        onFinish: () => { processando.value = false; },
    });
}

const aCancelar = ref(false);
const motivo = ref('');
function cancelar() {
    processando.value = true;
    router.patch(`/financeiro/cobrancas/${props.cobranca.id}/cancelar`, { motivo: motivo.value }, {
        preserveScroll: true,
        onSuccess: () => { aCancelar.value = false; },
        onError: (e) => { erros.value = e; toast.error(Object.values(e)[0]); },
        onFinish: () => { processando.value = false; },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/financeiro/cobrancas" />

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Cobrança #{{ cobranca.id }}</h1>
                <div class="text-uppercase fs-5 fw-bold text-gray-800">
                    {{ cobranca.aluno_nome }} · <span class="fw-semibold">nº de estudante {{ cobranca.aluno_numero }}</span> · matrícula {{ cobranca.matricula_numero }}
                </div>
                <div class="text-muted fs-7">{{ cobranca.turma_rotulo ?? '—' }} · {{ cobranca.ano_lectivo_nome ?? '—' }} · matrícula {{ cobranca.matricula_estado }}</div>
            </div>
            <div class="text-end">
                <EstadoCobrancaBadge :estado="cobranca.estado" :estado-descricao="cobranca.estado_descricao" class="fs-6 mb-1" />
                <div><span class="badge" :class="cobranca.origem === 2 ? 'badge-light-warning' : 'badge-light'">{{ cobranca.origem_descricao }}</span></div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-muted fw-bold fs-7 text-uppercase">
                            <th class="ps-6">Item</th>
                            <th class="text-end">Preço</th>
                            <th class="text-center">Qtd.</th>
                            <th class="text-end pe-6">Total</th>
                        </tr>
                    </thead>
                    <tbody class="fw-semibold text-gray-800">
                        <tr v-for="i in cobranca.itens" :key="i.id">
                            <td class="ps-6">
                                {{ i.nome }}<span v-if="i.variante_nome" class="text-muted"> · {{ i.variante_nome }}</span>
                                <div class="text-muted fs-8">{{ i.tipo === 'produto' ? 'Produto' : 'Serviço' }}<span v-if="i.codigo"> · {{ i.codigo }}</span></div>
                            </td>
                            <td class="text-end">{{ dinheiro(i.preco_unitario) }}</td>
                            <td class="text-center">{{ i.quantidade }}</td>
                            <td class="text-end pe-6">{{ dinheiro(i.total) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="fw-bold">
                        <tr><td colspan="3" class="text-end">Total</td><td class="text-end pe-6">{{ dinheiro(cobranca.total) }}</td></tr>
                        <tr><td colspan="3" class="text-end">Pago</td><td class="text-end pe-6">{{ dinheiro(cobranca.valor_pago) }}</td></tr>
                        <tr><td colspan="3" class="text-end">Em dívida</td><td class="text-end pe-6">{{ cobranca.estado === 4 ? '—' : dinheiro(cobranca.saldo) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div v-if="cobranca.data_limite || cobranca.observacao || cobranca.motivo_cancelamento" class="card mb-6">
            <div class="card-body fs-6">
                <div v-if="cobranca.data_limite"><span class="text-muted">Data limite:</span> {{ dataCurta(cobranca.data_limite) }}</div>
                <div v-if="cobranca.observacao"><span class="text-muted">Observação:</span> {{ cobranca.observacao }}</div>
                <div v-if="cobranca.motivo_cancelamento" class="text-danger"><span class="fw-semibold">Motivo do cancelamento:</span> {{ cobranca.motivo_cancelamento }}</div>
            </div>
        </div>

        <div v-if="cobranca.pagamentos.length" class="card mb-6">
            <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">Pagamentos</h3></div>
            <div class="card-body pt-0">
                <div v-for="p in cobranca.pagamentos" :key="p.pagamento_id" class="d-flex justify-content-between align-items-center py-2 border-bottom border-dashed">
                    <div>
                        <a :href="`/financeiro/pagamentos/${p.pagamento_id}`" class="fw-bold">Pagamento n.º {{ p.numero }}</a>
                        <span class="text-muted fs-7 ms-2">{{ dataCurta(p.data) }} · {{ p.estado_descricao }}</span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="fw-semibold">{{ dinheiro(p.valor) }}</span>
                        <a :href="`/financeiro/pagamentos/${p.pagamento_id}/recibo`" target="_blank" class="btn btn-sm btn-light-primary">Recibo</a>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="emAberto && podeCriar" class="card mb-6">
            <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">Pagar cobrança ({{ dinheiro(cobranca.saldo) }})</h3></div>
            <div class="card-body">
                <div class="row g-5">
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
                        <label class="form-label fw-semibold">Comprovativo {{ metodoEscolhido()?.exige_comprovativo ? '(obrigatório)' : '(opcional)' }}</label>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control form-control-solid" @change="comprovativo = $event.target.files[0] ?? null" />
                        <div class="text-danger fs-7">{{ erros.comprovativo }}</div>
                    </div>
                </div>
                <div class="text-end mt-6">
                    <button type="button" class="btn btn-primary" :disabled="processando || !form.metodo_pagamento_id" @click="pagar">Pagar agora</button>
                </div>
            </div>
        </div>

        <div v-if="emAberto && podeCancelar" class="text-end">
            <button type="button" class="btn btn-light-danger" @click="aCancelar = true">Cancelar cobrança</button>
        </div>

        <div v-if="aCancelar" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="aCancelar = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content p-6">
                    <h3 class="mb-3">Cancelar cobrança #{{ cobranca.id }}</h3>
                    <p class="text-gray-700">Só se cancela uma cobrança sem pagamentos. O stock abatido é reposto.</p>
                    <textarea v-model="motivo" rows="3" class="form-control form-control-solid mb-1" placeholder="Motivo (obrigatório)" maxlength="1000"></textarea>
                    <div class="text-danger fs-7 mb-3">{{ erros.motivo }}</div>
                    <div class="text-end">
                        <button type="button" class="btn btn-light me-2" :disabled="processando" @click="aCancelar = false">Voltar</button>
                        <button type="button" class="btn btn-danger" :disabled="processando || motivo.trim().length < 5" @click="cancelar">Cancelar cobrança</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
