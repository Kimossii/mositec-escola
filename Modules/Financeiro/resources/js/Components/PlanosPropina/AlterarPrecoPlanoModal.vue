<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { formatarDinheiro } from '../../Support/dinheiro';
import { mesAno } from '../../Support/periodos';

const props = defineProps({
    show: { type: Boolean, default: false },
    plano: { type: Object, default: null }, // linha da lista: id, nome, valor, ano_lectivo_nome, inicios_periodo
    modos: { type: Array, required: true }, // [{ value, label }] — nenhum marcado por omissão (spec §14)
    moeda: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const BASE = '/financeiro/configuracao/planos-propina';
const AJUDA_MODO = {
    1: 'As propinas em aberto ou parcialmente pagas a partir do mês de efeito passam ao novo valor (o já recebido mantém-se). Pagas, canceladas e anuladas não mudam.',
    2: 'Nenhuma propina já gerada muda; o novo valor aplica-se só às que ainda não foram geradas.',
};

const vazio = () => ({
    valor: '',
    mes_efeito: '',
    modo: null,
    motivo: '',
    confirmar_valores_diferentes: false,
});

const form = reactive(vazio());
const previa = ref(null);
const aCalcular = ref(false);
const errosPrevia = ref({});

watch(() => props.show, (show) => {
    if (!show) return;
    Object.assign(form, vazio());
    previa.value = null;
    errosPrevia.value = {};
});

// Mudar um parâmetro invalida a pré-visualização: nunca se confirma algo diferente do que foi visto.
watch(() => [form.valor, form.mes_efeito, form.modo], () => {
    previa.value = null;
    form.confirmar_valores_diferentes = false;
});

// Spec §14: se os dados mudaram entre a pré-visualização e a confirmação, a pré-visualização é refeita.
watch(() => props.errors.impressao_digital, (mensagem) => {
    if (!mensagem || !props.show) return;
    toast.warning(mensagem);
    form.confirmar_valores_diferentes = false;
    preVisualizar();
});

const opcoesMes = computed(() => (props.plano?.inicios_periodo ?? []).map((m) => ({ value: m.valor, label: m.rotulo })));
const podePreVisualizar = computed(() => form.modo !== null && form.valor !== '' && form.mes_efeito !== '');
const podeConfirmar = computed(() => previa.value !== null
    && form.motivo.trim().length >= 5
    && (!previa.value.exige_confirmacao_valores_diferentes || form.confirmar_valores_diferentes));
const erro = (campo) => errosPrevia.value[campo] ?? props.errors[campo];
const dinheiro = (valor) => formatarDinheiro(valor, props.moeda);

async function preVisualizar() {
    if (!props.plano || !podePreVisualizar.value) return;
    aCalcular.value = true;
    errosPrevia.value = {};

    try {
        const { data } = await axios.post(`${BASE}/${props.plano.id}/alterar-preco/pre-visualizar`, {
            valor: form.valor,
            mes_efeito: form.mes_efeito,
            modo: form.modo,
        });
        previa.value = data.previsualizacao;
    } catch (falha) {
        previa.value = null;
        const estado = falha.response?.status;

        if (estado === 422) {
            errosPrevia.value = Object.fromEntries(Object.entries(falha.response.data.errors ?? {}).map(([campo, mensagens]) => [campo, mensagens[0]]));
            toast.error(Object.values(errosPrevia.value)[0] ?? 'Verifique os dados.');
        } else if (estado === 403) {
            toast.error('Não tem permissão para alterar o preço deste plano.');
        } else {
            toast.error('Não foi possível pré-visualizar. Tente de novo.');
        }
    } finally {
        aCalcular.value = false;
    }
}

function confirmar() {
    if (!podeConfirmar.value || props.processing) return;

    emit('confirmar', {
        valor: form.valor,
        mes_efeito: form.mes_efeito,
        modo: form.modo,
        motivo: form.motivo.trim(),
        impressao_digital: previa.value.impressao_digital,
        confirmar_valores_diferentes: form.confirmar_valores_diferentes,
    });
}
</script>

<template>
    <div v-if="show && plano" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6">
                <h3 class="mb-1">Alterar preço — {{ plano.nome }}</h3>
                <p class="text-muted fs-7 mb-5">
                    Ano lectivo {{ plano.ano_lectivo_nome }} · em vigor desde {{ mesAno(plano.data_inicio) }} · valor actual {{ dinheiro(plano.valor) }} por período.
                    Este plano já tem propinas geradas: escolha como as tratar.
                </p>

                <form v-if="!previa" @submit.prevent="preVisualizar">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Novo valor por período</label>
                            <input v-model="form.valor" type="text" inputmode="decimal" class="form-control form-control-solid" />
                            <div v-if="erro('valor')" class="text-danger fs-7 mt-1">{{ erro('valor') }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">A partir de (mês de efeito)</label>
                            <SelectSolid v-model="form.mes_efeito" :options="opcoesMes" />
                            <div class="text-muted fs-7 mt-1">Só o início de um período deste plano. Os meses anteriores mantêm o valor actual.</div>
                            <div v-if="erro('mes_efeito')" class="text-danger fs-7 mt-1">{{ erro('mes_efeito') }}</div>
                        </div>
                    </div>

                    <label class="required fw-semibold fs-6 mb-2">Propinas já geradas</label>
                    <div class="row g-4 mb-2">
                        <div v-for="modo in modos" :key="modo.value" class="col-md-6">
                            <label :class="['btn btn-outline btn-outline-dashed btn-active-light-primary d-flex text-start p-5 h-100', { active: form.modo === modo.value }]">
                                <input v-model="form.modo" class="form-check-input me-4 mt-1" type="radio" name="modo_alteracao_preco" :value="modo.value" />
                                <span>
                                    <span class="fw-bold fs-6 d-block mb-1">{{ modo.label }}</span>
                                    <span class="text-muted fs-7">{{ AJUDA_MODO[modo.value] }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div v-if="erro('modo')" class="text-danger fs-7 mb-3">{{ erro('modo') }}</div>
                    <div v-if="erro('plano')" class="alert alert-danger py-3 fs-7">{{ erro('plano') }}</div>

                    <div class="text-end mt-5">
                        <button type="button" class="btn btn-light me-2" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="!podePreVisualizar || aCalcular">
                            {{ aCalcular ? 'A calcular…' : 'Pré-visualizar' }}
                        </button>
                    </div>
                </form>

                <template v-else>
                    <div class="alert alert-warning py-3 fs-7 mb-5">
                        Pré-visualização: <strong>nada foi gravado</strong>. Ao confirmar, tudo é verificado de novo; se algo mudou entretanto, a pré-visualização é refeita.
                    </div>

                    <div class="bg-body-secondary rounded p-4 mb-5 fs-7">
                        <div><strong>Escolha:</strong> {{ previa.modo_descricao }} · <strong>a partir de</strong> {{ previa.mes_efeito_rotulo }}</div>
                        <div><strong>Ano lectivo abrangido:</strong> {{ previa.ano_lectivo.nome }} (id {{ previa.ano_lectivo.id }})</div>
                        <div class="text-success">{{ previa.garantia_outros_anos }} ({{ previa.propinas_de_outros_anos }} de outros anos.)</div>
                        <div><strong>Valor:</strong> {{ dinheiro(previa.valor_anterior) }} → {{ dinheiro(previa.valor_novo) }} por período</div>
                    </div>

                    <h4 class="fs-6 fw-bold mb-3">Planos resultantes</h4>
                    <ul class="fs-7 mb-5">
                        <li v-for="p in previa.planos" :key="p.papel">
                            <strong>{{ p.nome }}</strong> — {{ dinheiro(p.valor) }} · {{ p.periodos.map((x) => x.periodo).join(', ') }}
                            <span v-if="p.papel === 'novo'" class="badge badge-light-primary ms-1">novo</span>
                        </li>
                    </ul>

                    <div v-if="previa.aviso_valores_diferentes" class="alert alert-danger py-3 fs-7 mb-5">
                        {{ previa.aviso_valores_diferentes.mensagem }}
                        <label v-if="previa.exige_confirmacao_valores_diferentes" class="form-check form-check-custom form-check-solid mt-3">
                            <input v-model="form.confirmar_valores_diferentes" class="form-check-input" type="checkbox" />
                            <span class="form-check-label">Compreendo que alunos da mesma turma vão pagar valores diferentes.</span>
                        </label>
                        <div v-if="errors.confirmar_valores_diferentes" class="text-danger mt-1">{{ errors.confirmar_valores_diferentes }}</div>
                    </div>

                    <h4 class="fs-6 fw-bold mb-3">Propinas afectadas ({{ previa.totais.afectadas }})</h4>
                    <div v-if="previa.afectadas.length" class="table-responsive mb-5">
                        <table class="table table-row-dashed align-middle fs-7 gy-2">
                            <thead>
                                <tr class="text-muted fw-bold text-uppercase">
                                    <th>Aluno</th><th>Matrícula</th><th>Período</th><th>Estado</th>
                                    <th class="text-end">Valor</th><th class="text-end">Recebido</th><th class="text-end">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in previa.afectadas" :key="a.propina_id">
                                    <td>{{ a.aluno_nome }}</td>
                                    <td>{{ a.matricula_numero }}</td>
                                    <td>{{ a.periodo }}</td>
                                    <td>{{ a.estado_descricao }}</td>
                                    <td class="text-end">{{ dinheiro(a.valor_antes) }} → {{ dinheiro(a.valor_depois) }}</td>
                                    <td class="text-end">{{ dinheiro(a.valor_pago) }}</td>
                                    <td class="text-end">
                                        {{ dinheiro(a.saldo_antes) }} → {{ dinheiro(a.saldo_depois) }}
                                        <span v-if="a.passa_a_paga" class="badge badge-light-warning ms-1" :title="`Recebido acima do novo valor: ${dinheiro(a.excesso)} (sem devolução).`">Passa a Paga</span>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="4">Total</td>
                                    <td class="text-end">{{ dinheiro(previa.totais.valor_antes) }} → {{ dinheiro(previa.totais.valor_depois) }}</td>
                                    <td class="text-end">{{ dinheiro(previa.totais.recebido) }}</td>
                                    <td class="text-end">{{ dinheiro(previa.totais.saldo_antes) }} → {{ dinheiro(previa.totais.saldo_depois) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p v-else class="text-muted fs-7 mb-5">Nenhuma propina já gerada muda.</p>

                    <h4 class="fs-6 fw-bold mb-3">Ficam inalteradas ({{ previa.totais.inalteradas }} a partir do mês de efeito; {{ previa.totais.anteriores_ao_mes_efeito }} anteriores)</h4>
                    <div v-if="previa.inalteradas.length" class="table-responsive mb-5">
                        <table class="table table-row-dashed align-middle fs-7 gy-2">
                            <thead>
                                <tr class="text-muted fw-bold text-uppercase"><th>Aluno</th><th>Período</th><th>Estado</th><th class="text-end">Valor</th><th>Porquê</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in previa.inalteradas" :key="i.propina_id">
                                    <td>{{ i.aluno_nome }}</td>
                                    <td>{{ i.periodo }}</td>
                                    <td>{{ i.estado_descricao }}</td>
                                    <td class="text-end">{{ dinheiro(i.valor_antes) }}</td>
                                    <td>{{ i.motivo }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <template v-if="previa.reassociadas_sem_ajuste.length">
                        <h4 class="fs-6 fw-bold mb-1">Passam ao plano novo sem mudar de valor ({{ previa.totais.reassociadas_sem_ajuste }})</h4>
                        <p class="text-muted fs-7 mb-3">Já têm o valor novo: só mudam de plano, sem ajuste de preço.</p>
                        <div class="table-responsive mb-5">
                            <table class="table table-row-dashed align-middle fs-7 gy-2">
                                <thead>
                                    <tr class="text-muted fw-bold text-uppercase"><th>Aluno</th><th>Matrícula</th><th>Período</th><th>Estado</th><th class="text-end">Valor</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in previa.reassociadas_sem_ajuste" :key="r.propina_id">
                                        <td>{{ r.aluno_nome }}</td>
                                        <td>{{ r.matricula_numero }}</td>
                                        <td>{{ r.periodo }}</td>
                                        <td>{{ r.estado_descricao }}</td>
                                        <td class="text-end">{{ dinheiro(r.valor_antes) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <div class="fv-row mb-5">
                        <label class="required fw-semibold fs-6 mb-2">Motivo</label>
                        <textarea v-model="form.motivo" class="form-control form-control-solid" rows="3" maxlength="500"></textarea>
                        <div class="d-flex justify-content-between text-muted fs-7 mt-1">
                            <span>Fica registado com o seu nome, no plano e em cada propina alterada.</span>
                            <span>{{ form.motivo.length }}/500</span>
                        </div>
                        <div v-if="errors.motivo" class="text-danger fs-7 mt-1">{{ errors.motivo }}</div>
                    </div>

                    <div v-if="errors.plano" class="alert alert-danger py-3 fs-7">{{ errors.plano }}</div>
                    <div v-if="errors.mes_efeito || errors.valor || errors.modo" class="alert alert-danger py-3 fs-7">{{ errors.mes_efeito ?? errors.valor ?? errors.modo }}</div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light me-2" :disabled="processing" @click="previa = null">Voltar</button>
                        <button type="button" class="btn btn-primary" :disabled="!podeConfirmar || processing" @click="confirmar">
                            {{ processing ? 'A gravar…' : 'Confirmar alteração de preço' }}
                        </button>
                    </div>
                    <div v-if="form.motivo.trim().length < 5" class="text-muted fs-7 text-end mt-2">O motivo tem de ter pelo menos 5 caracteres.</div>
                </template>
            </div>
        </div>
    </div>
</template>
