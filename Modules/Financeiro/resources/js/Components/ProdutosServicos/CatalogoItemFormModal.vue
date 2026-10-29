<script setup>
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { computed, reactive, watch } from 'vue';
import { unidadesMenoresParaDecimal } from '../../Support/dinheiro';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import ListaDeAlvos from './ListaDeAlvos.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    tipo: { type: String, required: true }, // 'produto' | 'servico'
    item: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    moeda: { type: Object, required: true },
    opcoes: { type: Object, default: () => ({ niveis: [], cursos: [], momentos: [] }) },
});
const emit = defineEmits(['submit', 'cancelar']);

const rotulo = computed(() => (props.tipo === 'produto' ? 'Produto' : 'Serviço'));
const ehProduto = computed(() => props.tipo === 'produto');
const form = reactive({
    nome: '', codigo: '', descricao: '', preco: '',
    obrigatorio: false, momento_obrigatorio: '', apenas_alunos_novos: false, alvos_niveis: [], alvos_cursos: [],
    controla_stock: false, stock_quantidade: 0, tem_variantes: false, variantes: [],
});
const erroDe = (campo) => props.errors[campo];
const adicionarVariante = () => form.variantes.push({ nome: '', stock_quantidade: 0 });
const removerVariante = (i) => form.variantes.splice(i, 1);
function alternarAlvo(lista, id) {
    const i = lista.indexOf(id);
    if (i >= 0) lista.splice(i, 1);
    else lista.push(id);
}

function enviar() {
    const dados = { ...form, variantes: form.variantes.map((v) => ({ ...v })) };
    if (!form.obrigatorio) {
        dados.momento_obrigatorio = null;
        dados.apenas_alunos_novos = false;
        dados.alvos_niveis = [];
        dados.alvos_cursos = [];
    }
    if (!ehProduto.value) {
        ['controla_stock', 'stock_quantidade', 'tem_variantes', 'variantes'].forEach((c) => delete dados[c]);
    }
    emit('submit', dados);
}
const placeholderPreco = computed(() => props.moeda.decimais === 0
    ? 'ex: 25000'
    : 'ex: 25000 ou 25000,' + '50'.padEnd(props.moeda.decimais, '0').slice(0, props.moeda.decimais));

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.item?.nome ?? '';
    form.codigo = props.item?.codigo ?? '';
    form.descricao = props.item?.descricao ?? '';
    form.preco = props.item ? unidadesMenoresParaDecimal(props.item.preco, props.moeda) : '';
    form.obrigatorio = props.item?.obrigatorio ?? false;
    form.momento_obrigatorio = props.item?.momento_obrigatorio ?? '';
    form.apenas_alunos_novos = props.item?.apenas_alunos_novos ?? false;
    form.alvos_niveis = [...(props.item?.alvos_niveis ?? [])];
    form.alvos_cursos = [...(props.item?.alvos_cursos ?? [])];
    form.controla_stock = props.item?.controla_stock ?? false;
    form.stock_quantidade = props.item?.stock_quantidade ?? 0;
    form.tem_variantes = props.item?.tem_variantes ?? false;
    form.variantes = (props.item?.variantes ?? []).map((v) => ({ ...v }));
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6" style="max-height: 92vh; overflow-y: auto;">
                <h3 class="mb-5">{{ item ? `Editar ${rotulo}` : `Novo ${rotulo}` }}</h3>
                <form @submit.prevent="enviar">
                    <div class="row g-6">
                    <div class="col-lg-5">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" :placeholder="tipo === 'produto' ? 'ex: UNI-001' : 'ex: SER-001'" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Preço ({{ moeda.simbolo }})</label>
                            <input v-model="form.preco" type="text" inputmode="decimal" class="form-control form-control-solid" :placeholder="placeholderPreco" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.preco">{{ errors.preco }}</div>
                        </div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>

                    </div>
                    <div class="col-lg-7">
                    <!-- Obrigatoriedade -->
                    <div class="bg-body-secondary rounded p-4 mb-5">
                        <div class="form-check form-switch form-check-custom">
                            <input id="obrigatorio" v-model="form.obrigatorio" type="checkbox" class="form-check-input interruptor" />
                            <label for="obrigatorio" class="form-check-label fw-semibold text-gray-900">Obrigatório para o aluno</label>
                        </div>
                        <div class="form-text ms-12">Fica como dívida do aluno (visível na ficha e nas dívidas). Desligado, só se cobra a pedido.</div>

                        <template v-if="form.obrigatorio">
                            <div class="row mt-4">
                                <div class="col-md-6 fv-row mb-3">
                                    <label class="required fw-semibold fs-6 mb-2">Quando se torna obrigatório</label>
                                    <SelectSolid v-model="form.momento_obrigatorio" :options="opcoes.momentos" placeholder="Selecione quando" />
                                    <div class="text-danger fs-7 mt-1" v-if="erroDe('momento_obrigatorio')">{{ erroDe('momento_obrigatorio') }}</div>
                                </div>
                                <div class="col-md-6 d-flex align-items-center mb-3">
                                    <div class="form-check form-switch form-check-custom mt-5">
                                        <input id="novos" v-model="form.apenas_alunos_novos" type="checkbox" class="form-check-input interruptor" />
                                        <label for="novos" class="form-check-label fw-semibold">Só para alunos novos</label>
                                    </div>
                                </div>
                            </div>
                            <div class="fw-semibold fs-6 mb-2">Aplica-se a (sem escolha = todos)</div>
                            <div class="row g-4">
                                <div class="col-md-6"><ListaDeAlvos v-model="form.alvos_niveis" titulo="Níveis" :itens="opcoes.niveis" /></div>
                                <div class="col-md-6"><ListaDeAlvos v-model="form.alvos_cursos" titulo="Cursos" :itens="opcoes.cursos" /></div>
                            </div>
                        </template>
                    </div>

                    <!-- Stock e tamanhos (só produtos) -->
                    <div v-if="ehProduto" class="bg-body-secondary rounded p-4 mb-7">
                        <div class="form-check form-switch form-check-custom">
                            <input id="stock" v-model="form.controla_stock" type="checkbox" class="form-check-input interruptor" />
                            <label for="stock" class="form-check-label fw-semibold text-gray-900">Controlar stock</label>
                        </div>
                        <div class="form-text ms-12">Cada venda abate a quantidade; a anulação repõe-na. Sem stock suficiente a venda é bloqueada.</div>

                        <div v-if="form.controla_stock && !form.tem_variantes" class="row mt-3">
                            <div class="col-md-4">
                                <label class="fw-semibold fs-6 mb-2">Quantidade em stock</label>
                                <input v-model.number="form.stock_quantidade" type="number" min="0" class="form-control form-control-solid" />
                                <div class="text-danger fs-7 mt-1" v-if="erroDe('stock_quantidade')">{{ erroDe('stock_quantidade') }}</div>
                            </div>
                        </div>

                        <div class="form-check form-switch form-check-custom mt-4">
                            <input id="variantes" v-model="form.tem_variantes" type="checkbox" class="form-check-input interruptor" />
                            <label for="variantes" class="form-check-label fw-semibold text-gray-900">Tem tamanhos</label>
                        </div>

                        <div v-if="form.tem_variantes" class="mt-3">
                            <div v-for="(v, i) in form.variantes" :key="i" class="row g-2 align-items-start mb-2">
                                <div class="col-md-6">
                                    <input v-model="v.nome" type="text" class="form-control form-control-solid" placeholder="Tamanho (ex.: P, M, 38)" maxlength="100" />
                                    <div class="text-danger fs-7" v-if="erroDe(`variantes.${i}.nome`)">{{ erroDe(`variantes.${i}.nome`) }}</div>
                                </div>
                                <div v-if="form.controla_stock" class="col-md-4">
                                    <input v-model.number="v.stock_quantidade" type="number" min="0" class="form-control form-control-solid" placeholder="Stock" />
                                </div>
                                <div class="col-md-2"><button type="button" class="btn btn-light-danger btn-sm" @click="removerVariante(i)"><AcaoIcone acao="remover" herda-cor class="me-1" /> Remover</button></div>
                            </div>
                            <button type="button" class="btn btn-light-primary btn-sm" @click="adicionarVariante">Adicionar tamanho</button>
                            <div class="text-danger fs-7 mt-1" v-if="erroDe('variantes')">{{ erroDe('variantes') }}</div>
                        </div>
                    </div>

                    </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style scoped>
.interruptor { width: 2.75rem; height: 1.5rem; border: 2px solid #64748B !important; background-color: transparent !important; }
.interruptor:checked { background-color: #166534 !important; border-color: #166534 !important; }
.caixa { border: 2px solid #64748B !important; background-color: transparent !important; }
.caixa:checked { background-color: #166534 !important; border-color: #166534 !important; }
</style>
