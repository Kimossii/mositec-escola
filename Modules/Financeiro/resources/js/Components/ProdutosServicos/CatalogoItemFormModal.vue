<script setup>
import { computed, reactive, watch } from 'vue';
import { unidadesMenoresParaDecimal } from '../../Support/dinheiro';

const props = defineProps({
    show: { type: Boolean, default: false },
    tipo: { type: String, required: true }, // 'produto' | 'servico'
    item: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    moeda: { type: Object, required: true },
});
const emit = defineEmits(['submit', 'cancelar']);

const rotulo = computed(() => (props.tipo === 'produto' ? 'Produto' : 'Serviço'));
const form = reactive({ nome: '', codigo: '', descricao: '', preco: '' });
const placeholderPreco = computed(() => props.moeda.decimais === 0
    ? 'ex: 25000'
    : 'ex: 25000 ou 25000,' + '50'.padEnd(props.moeda.decimais, '0').slice(0, props.moeda.decimais));

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.item?.nome ?? '';
    form.codigo = props.item?.codigo ?? '';
    form.descricao = props.item?.descricao ?? '';
    form.preco = props.item ? unidadesMenoresParaDecimal(props.item.preco, props.moeda) : '';
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ item ? `Editar ${rotulo}` : `Novo ${rotulo}` }}</h3>
                <form @submit.prevent="emit('submit', { ...form })">
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
