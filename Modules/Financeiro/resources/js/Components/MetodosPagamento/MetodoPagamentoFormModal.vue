<script setup>
import { computed, nextTick, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    metodo: { type: Object, default: null },
    tipos: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const TIPO_TRANSFERENCIA_BANCARIA = 2;
const TIPO_MULTICAIXA = 4;
const TIPO_TRANSFERENCIA_EXPRESS = 6;
const TIPO_CARTEIRA_DIGITAL = 7;

const form = reactive({ nome: '', tipo: '', titular: '', banco: '', iban: '', numero_conta: '', telefone: '', exige_comprovativo: false });

// Por omissão os métodos electrónicos/remotos exigem comprovativo; os presenciais ou manuais não. A escola pode alterar.
function exigePorOmissao(tipo) {
    return [TIPO_TRANSFERENCIA_BANCARIA, TIPO_MULTICAIXA, TIPO_TRANSFERENCIA_EXPRESS, TIPO_CARTEIRA_DIGITAL].includes(Number(tipo));
}

let aCarregar = false;

watch(() => props.show, (show) => {
    if (!show) return;
    aCarregar = true;
    form.nome = props.metodo?.nome ?? '';
    form.tipo = props.metodo?.tipo ?? props.tipos[0]?.value ?? '';
    form.exige_comprovativo = props.metodo?.exige_comprovativo ?? exigePorOmissao(form.tipo);
    form.titular = props.metodo?.titular ?? '';
    form.banco = props.metodo?.banco ?? '';
    form.iban = props.metodo?.iban ?? '';
    form.numero_conta = props.metodo?.numero_conta ?? '';
    form.telefone = props.metodo?.telefone ?? '';
    nextTick(() => { aCarregar = false; });
});

watch(() => form.tipo, (tipo) => {
    if (aCarregar) return;
    form.exige_comprovativo = exigePorOmissao(tipo);
});

const mostraBancarios = computed(() => [TIPO_TRANSFERENCIA_BANCARIA, TIPO_MULTICAIXA].includes(Number(form.tipo)));
const mostraExpress = computed(() => Number(form.tipo) === TIPO_TRANSFERENCIA_EXPRESS);
const mostraCarteira = computed(() => Number(form.tipo) === TIPO_CARTEIRA_DIGITAL);
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ metodo ? 'Editar Método de Pagamento' : 'Novo Método de Pagamento' }}</h3>
                <form @submit.prevent="emit('submit', { ...form })">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Transferência BAI" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                        <SelectSolid v-model="form.tipo" :options="tipos" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo }}</div>
                    </div>
                    <div class="form-check form-switch form-check-custom form-check-solid mb-7">
                        <input id="exige_comprovativo" v-model="form.exige_comprovativo" type="checkbox" class="form-check-input" />
                        <label for="exige_comprovativo" class="form-check-label fw-semibold">Exige comprovativo</label>
                        <div class="text-muted fs-8">Ao registar um pagamento por este método, pede o anexo do comprovativo.</div>
                    </div>
                    <template v-if="mostraBancarios">
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Titular</label>
                            <input v-model="form.titular" type="text" class="form-control form-control-solid" placeholder="ex: Colégio Mositec" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.titular">{{ errors.titular }}</div>
                        </div>
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Banco</label>
                            <input v-model="form.banco" type="text" class="form-control form-control-solid" placeholder="ex: BAI" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.banco">{{ errors.banco }}</div>
                        </div>
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">IBAN</label>
                            <input v-model="form.iban" type="text" class="form-control form-control-solid" placeholder="ex: AO06000600000100037131174" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.iban">{{ errors.iban }}</div>
                        </div>
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Número de conta</label>
                            <input v-model="form.numero_conta" type="text" class="form-control form-control-solid" placeholder="ex: 0001-00037131174-01" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero_conta">{{ errors.numero_conta }}</div>
                        </div>
                    </template>
                    <template v-if="mostraExpress">
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Banco</label>
                            <input v-model="form.banco" type="text" class="form-control form-control-solid" placeholder="ex: BAI" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.banco">{{ errors.banco }}</div>
                        </div>
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Telefone</label>
                            <input v-model="form.telefone" type="text" class="form-control form-control-solid" placeholder="ex: +244 923 000 000" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.telefone">{{ errors.telefone }}</div>
                        </div>
                    </template>
                    <template v-if="mostraCarteira">
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Titular</label>
                            <input v-model="form.titular" type="text" class="form-control form-control-solid" placeholder="ex: Colégio Mositec" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.titular">{{ errors.titular }}</div>
                        </div>
                        <div class="fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Telefone</label>
                            <input v-model="form.telefone" type="text" class="form-control form-control-solid" placeholder="ex: +244 923 000 000" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.telefone">{{ errors.telefone }}</div>
                        </div>
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Outro identificador (opcional)</label>
                            <input v-model="form.numero_conta" type="text" class="form-control form-control-solid" placeholder="ex: número de agente" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero_conta">{{ errors.numero_conta }}</div>
                        </div>
                    </template>
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
