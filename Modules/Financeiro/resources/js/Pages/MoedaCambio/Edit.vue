<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

const BASE = '/financeiro/configuracao/moeda-cambio';

const ORIGENS = {
    usd: 'Moeda da escola é o USD (vale sempre 1)',
    escola: 'Câmbio próprio da escola',
    plataforma: 'Câmbio da plataforma (padrão)',
};

const props = defineProps({
    configuracao: { type: Object, required: true },
    moeda: { type: Object, required: true },
    moedas: { type: Array, required: true },
    cambioVigente: { type: Object, default: null },
    historico: { type: Object, required: true }, // paginador: { data, links, ... }
});
defineOptions({ layout: AppLayout });

const podeEditar = computed(() => can('moeda-cambio.editar'));
const podeCriar = computed(() => can('moeda-cambio.criar'));
const moedaEUsd = computed(() => props.configuracao.moeda === 'USD');

const form = reactive({
    moeda: props.configuracao.moeda,
    cambio_manual: props.configuracao.cambio_manual,
});
watch(() => props.configuracao, (nova) => {
    form.moeda = nova.moeda;
    form.cambio_manual = nova.cambio_manual;
});

const errosConfiguracao = ref({});
const aGuardar = ref(false);

function guardarConfiguracao() {
    aGuardar.value = true;
    errosConfiguracao.value = {};

    router.put(BASE, { moeda: form.moeda, cambio_manual: form.cambio_manual }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Moeda e câmbio atualizados com sucesso.'),
        onError: (erros) => {
            errosConfiguracao.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            aGuardar.value = false;
        },
    });
}

const agora = new Date();
const hoje = `${agora.getFullYear()}-${String(agora.getMonth() + 1).padStart(2, '0')}-${String(agora.getDate()).padStart(2, '0')}`;
const novo = reactive({ data: hoje, taxa: '' });
const errosCambio = ref({});
const aRegistar = ref(false);

function registarCambio() {
    aRegistar.value = true;
    errosCambio.value = {};

    router.post(`${BASE}/cambios`, { data: novo.data, taxa: novo.taxa }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Câmbio registado com sucesso.');
            novo.taxa = '';
        },
        onError: (erros) => {
            errosCambio.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            aRegistar.value = false;
        },
    });
}

const paraEliminar = ref(null);
const aEliminar = ref(false);

function confirmarEliminacao() {
    aEliminar.value = true;
    router.delete(`${BASE}/cambios/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Câmbio eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aEliminar.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Moeda e Câmbio</h1>
            <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                A moeda em que a escola opera e o câmbio de referência em USD (1 USD = X unidades da moeda da escola).
                O câmbio é guardado em cada registo no momento em que é criado.
            </p>
        </div>

        <form class="card mb-6" @submit.prevent="guardarConfiguracao">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Moeda da escola</h3>
            </div>
            <div class="card-body pt-0">
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="fw-semibold fs-6 mb-2">Moeda</label>
                        <SelectSolid v-if="configuracao.pode_alterar_moeda && podeEditar" v-model="form.moeda" :options="moedas" />
                        <input v-else type="text" class="form-control form-control-solid" :value="`${moeda.codigo} — ${moeda.nome} (${moeda.simbolo})`" disabled />
                        <div class="text-danger fs-7 mt-1" v-if="errosConfiguracao.moeda">{{ errosConfiguracao.moeda }}</div>
                        <div class="form-text" v-if="!configuracao.pode_alterar_moeda">
                            A moeda não pode ser alterada porque já existem preços configurados ou registos financeiros.
                        </div>
                        <div class="form-text" v-else>
                            Só pode mudar enquanto não existirem produtos, serviços, planos de propina ou registos financeiros.
                        </div>
                    </div>
                </div>

                <div class="form-check form-switch form-check-custom form-check-solid mt-2" v-if="!moedaEUsd">
                    <input id="cambio_manual" v-model="form.cambio_manual" type="checkbox" class="form-check-input" :disabled="!podeEditar" />
                    <label for="cambio_manual" class="form-check-label fw-semibold">Usar câmbio próprio da escola (em vez do da plataforma)</label>
                </div>
                <div class="text-danger fs-7 mt-1" v-if="errosConfiguracao.cambio_manual">{{ errosConfiguracao.cambio_manual }}</div>
            </div>
            <div v-if="podeEditar" class="card-footer d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" :disabled="aGuardar">{{ aGuardar ? 'A guardar…' : 'Guardar' }}</button>
            </div>
        </form>

        <div class="card mb-6">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Câmbio em vigor</h3>
            </div>
            <div class="card-body pt-0">
                <template v-if="cambioVigente">
                    <div class="fs-2 fw-bold">1 USD = {{ cambioVigente.taxa }} {{ moeda.codigo }}</div>
                    <div class="text-muted fs-7">
                        {{ ORIGENS[cambioVigente.origem] }}<span v-if="cambioVigente.data"> · desde {{ cambioVigente.data }}</span>
                    </div>
                </template>
                <div v-else class="text-muted">
                    Sem câmbio configurado para {{ moeda.codigo }}. Os registos novos ficarão sem câmbio; nada fica bloqueado.
                </div>
            </div>
        </div>

        <div v-if="!moedaEUsd && configuracao.cambio_manual" class="card">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Câmbios da escola</h3>
            </div>
            <div class="card-body">
                <form v-if="podeCriar" class="row g-4 align-items-end mb-6" @submit.prevent="registarCambio">
                    <div class="col-md-3">
                        <label class="fw-semibold fs-7 text-muted mb-1">Data</label>
                        <input v-model="novo.data" type="date" :max="hoje" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errosCambio.data">{{ errosCambio.data }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">1 USD = (em {{ moeda.codigo }})</label>
                        <input v-model="novo.taxa" type="text" inputmode="decimal" class="form-control form-control-solid" placeholder="ex: 910 ou 910,50" />
                        <div class="text-danger fs-7 mt-1" v-if="errosCambio.taxa">{{ errosCambio.taxa }}</div>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary" :disabled="aRegistar">{{ aRegistar ? 'A registar…' : 'Registar câmbio' }}</button>
                    </div>
                </form>
                <div class="form-text mb-4" v-if="podeCriar">Registar no mesmo dia actualiza o câmbio desse dia.</div>

                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th>Data</th>
                            <th class="text-end">1 USD =</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="historico.data.length === 0">
                            <td colspan="3" class="text-center text-muted py-5">Ainda não registou nenhum câmbio.</td>
                        </tr>
                        <tr v-for="linha in historico.data" :key="linha.id">
                            <td>{{ linha.data }}</td>
                            <td class="text-end">{{ linha.taxa_formatada }} {{ moeda.codigo }}</td>
                            <td class="text-end">
                                <button v-if="can('moeda-cambio.eliminar')" type="button" class="btn btn-sm btn-light-danger" @click="paraEliminar = linha">Eliminar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="historico.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="historico.links" />
            </div>
        </div>

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar câmbio"
            :mensagem="`Eliminar o câmbio de ${paraEliminar?.data}? Os registos já criados mantêm o câmbio que guardaram.`"
            :processando="aEliminar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
