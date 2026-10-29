<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    acordo: { type: Object, required: true },
    moeda: { type: Object, required: true },
    podeCancelar: { type: Boolean, default: false },
    podePagar: { type: Boolean, default: false },
});
defineOptions({ layout: AppLayout });

const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const activo = props.acordo.estado === 1;
const percentagem = props.acordo.total ? Math.round((props.acordo.pago / props.acordo.total) * 100) : 0;
const rotulo = { paga: ['Paga', 'badge-light-success'], em_atraso: ['Em atraso', 'badge-light-danger'], pendente: ['Pendente', 'badge-light'] };

const destino = ref(null); // 'cancelar' | 'quebrar'
const motivo = ref('');
const erros = ref({});
const processando = ref(false);
function encerrar() {
    processando.value = true;
    router.patch(`/financeiro/acordos/${props.acordo.id}/encerrar`, { destino: destino.value, motivo: motivo.value }, {
        preserveScroll: true,
        onSuccess: () => { destino.value = null; motivo.value = ''; },
        onError: (e) => { erros.value = e; toast.error(Object.values(e)[0]); },
        onFinish: () => { processando.value = false; },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/financeiro/acordos" />

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Acordo #{{ acordo.id }}</h1>
                <div class="text-uppercase fs-5 fw-bold text-gray-800">{{ acordo.aluno_nome }} · <span class="fw-semibold">nº de estudante {{ acordo.aluno_numero }}</span> · matrícula {{ acordo.matricula_numero }}</div>
                <div class="text-muted fs-7">{{ acordo.turma_rotulo ?? '—' }}</div>
            </div>
            <span class="badge fs-5" :class="acordo.estado_efectivo === 'Cumprido' ? 'badge-light-success' : acordo.em_atraso ? 'badge-light-danger' : acordo.estado === 1 ? 'badge-light-primary' : 'badge-light-dark'">{{ acordo.estado_efectivo }}</span>
        </div>

        <div class="row g-4 mb-6">
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Dívida inicial</div><div class="fs-4 fw-bold">{{ dinheiro(acordo.divida_inicial) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Desconto nas multas ({{ acordo.desconto_multa_pct }}%)</div><div class="fs-4 fw-bold text-success">− {{ dinheiro(acordo.desconto) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Total acordado</div><div class="fs-4 fw-bold">{{ dinheiro(acordo.total) }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-4"><div class="text-muted fs-8 text-uppercase fw-bold">Em falta</div><div class="fs-4 fw-bold text-danger">{{ dinheiro(acordo.em_falta) }}</div></div></div></div>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span class="fw-semibold">Pago {{ dinheiro(acordo.pago) }} de {{ dinheiro(acordo.total) }}</span><span class="fw-bold">{{ percentagem }}%</span></div>
                <div class="progress h-10px"><div class="progress-bar bg-success" role="progressbar" :style="{ width: `${percentagem}%` }"></div></div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-5 fw-bold">Prestações</h3>
                <div v-if="activo && acordo.em_falta > 0 && podePagar" class="card-toolbar">
                    <a :href="`/financeiro/pagamentos/novo?matricula=${acordo.matricula_id}`" class="btn btn-sm btn-primary">Registar pagamento</a>
                </div>
            </div>
            <div class="card-body pt-0">
                <table class="table align-middle table-row-dashed fs-6 gy-3 mb-0">
                    <thead><tr class="text-muted fw-bold fs-7 text-uppercase"><th>Prestação</th><th>Vencimento</th><th class="text-end">Valor</th><th class="text-end">Estado</th></tr></thead>
                    <tbody class="fw-semibold text-gray-800">
                        <tr v-for="p in acordo.prestacoes" :key="p.ordem">
                            <td>{{ p.ordem }}.ª</td><td>{{ dataCurta(p.data_vencimento) }}</td><td class="text-end">{{ dinheiro(p.valor) }}</td>
                            <td class="text-end"><span class="badge" :class="rotulo[p.estado][1]">{{ rotulo[p.estado][0] }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <div class="text-muted fs-7 mt-3">Os pagamentos fazem-se em Registar pagamento, em qualquer valor, e abatem a dívida do mês mais antigo para a frente.</div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header min-h-auto py-4"><h3 class="card-title fs-5 fw-bold">Propinas incluídas</h3></div>
            <div class="card-body pt-0">
                <div v-for="p in acordo.propinas" :key="p.propina_id" class="d-flex justify-content-between py-2 border-bottom border-dashed">
                    <a :href="`/financeiro/propinas/${p.propina_id}`" class="fw-semibold">{{ p.periodo }}</a>
                    <span>{{ dinheiro(p.capital_inicial) }}<span v-if="p.multa_inicial" class="text-muted"> + multa {{ dinheiro(p.multa_inicial) }}</span></span>
                </div>
            </div>
        </div>

        <div class="card mb-6"><div class="card-body fs-6">
            <div><span class="text-muted">Motivo do acordo:</span> {{ acordo.motivo }}</div>
            <div v-if="acordo.motivo_encerramento" class="text-danger mt-2"><span class="fw-semibold">Motivo do encerramento:</span> {{ acordo.motivo_encerramento }}</div>
        </div></div>

        <div v-if="activo && podeCancelar" class="text-end">
            <button type="button" class="btn btn-light-warning me-2" @click="destino = 'quebrar'">Quebrar acordo (aluno falhou)</button>
            <button type="button" class="btn btn-light-danger" @click="destino = 'cancelar'">Cancelar acordo</button>
        </div>

        <div v-if="destino" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="destino = null">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content p-6">
                <h3 class="mb-3">{{ destino === 'quebrar' ? 'Quebrar acordo' : 'Cancelar acordo' }}</h3>
                <p class="text-gray-700">As multas voltam a incidir e o pagamento parcial deixa de ser permitido. O que já foi pago e o desconto dado nas multas mantêm-se.</p>
                <textarea v-model="motivo" rows="2" maxlength="1000" class="form-control form-control-solid mb-1" placeholder="Motivo (obrigatório)"></textarea>
                <div class="text-danger fs-7 mb-3">{{ erros.motivo }}</div>
                <div class="text-end">
                    <button type="button" class="btn btn-light me-2" :disabled="processando" @click="destino = null">Voltar</button>
                    <button type="button" class="btn btn-danger" :disabled="processando || motivo.trim().length < 5" @click="encerrar">Confirmar</button>
                </div>
            </div></div>
        </div>
    </div>
</template>
