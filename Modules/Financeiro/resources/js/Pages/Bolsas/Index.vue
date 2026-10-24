<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltrosRecolhiveis from '../../Components/Shared/FiltrosRecolhiveis.vue';
import IndicadoresDiferidos from '../../Components/Shared/IndicadoresDiferidos.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const BASE = '/financeiro/bolsas';

const props = defineProps({
    bolsas: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    estados: { type: Array, required: true },
    anosLectivos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    indicadores: { type: Object, default: null }, // diferido: chega depois da lista
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (v) => (v !== undefined && v !== null && v !== '' && Number.isFinite(Number(v)) ? Number(v) : '');
const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: numeroOuVazio(props.filtros.estado),
    ano_lectivo_id: numeroOuVazio(props.filtros.ano_lectivo_id),
});
const opcoesEstado = computed(() => [{ value: '', label: 'Todos os estados' }, ...props.estados]);
const opcoesAno = computed(() => [{ value: '', label: 'Todos os anos lectivos' }, ...props.anosLectivos.map((a) => ({ value: a.id, label: a.nome }))]);
const dinheiro = (v) => formatarDinheiro(v, props.moeda);
const montarIndicadores = (i) => [
    { rotulo: 'Bolsas activas', valor: String(i.activas), nota: `${i.terminadas} terminada(s)`, cor: 'success' },
    { rotulo: 'Alunos abrangidos', valor: String(i.alunos), nota: 'com bolsa activa', cor: 'primary' },
    { rotulo: 'Cobertura média', valor: `${i.media_pct}%`, nota: 'das propinas, por bolsa activa', cor: 'info', progresso: i.media_pct },
    { rotulo: 'Abatido nas propinas', valor: dinheiro(i.abatido), nota: `${i.propinas} propina(s) com bolsa`, cor: 'warning' },
];
const filtrosAtivos = computed(() => [filtros.estado !== '', filtros.ano_lectivo_id !== ''].filter(Boolean).length);
const limparFiltros = () => Object.assign(filtros, { estado: '', ano_lectivo_id: '' });

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true }), 300);
});

// Criar / editar
const modal = ref(false);
const emEdicao = ref(null);
const matricula = ref(null);
const form = reactive({ nome: '', percentagem: '', data_inicio: '', data_fim: '', motivo: '' });
const erros = ref({});
const processando = ref(false);
const termo = ref('');
const resultados = ref([]);
let espera = null;

function abrirCriar() {
    emEdicao.value = null; matricula.value = null; termo.value = ''; resultados.value = []; erros.value = {};
    Object.assign(form, { nome: '', percentagem: '', data_inicio: '', data_fim: '', motivo: '' });
    modal.value = true;
}
function abrirEditar(b) {
    emEdicao.value = b; erros.value = {};
    Object.assign(form, { nome: b.nome, percentagem: b.percentagem, data_inicio: b.data_inicio, data_fim: b.data_fim, motivo: b.motivo });
    modal.value = true;
}
function pesquisar() {
    clearTimeout(espera);
    espera = setTimeout(async () => {
        if (termo.value.trim().length < 2) { resultados.value = []; return; }
        const { data } = await axios.get(`${BASE}/matriculas`, { params: { q: termo.value } });
        resultados.value = data.matriculas;
    }, 250);
}
function escolher(m) {
    matricula.value = m; resultados.value = [];
    form.data_inicio ||= m.ano_inicio ?? '';
    form.data_fim ||= m.ano_fim ?? '';
}
function guardar() {
    processando.value = true; erros.value = {};
    const dados = { ...form, ...(emEdicao.value ? {} : { matricula_id: matricula.value?.id }) };
    router[emEdicao.value ? 'put' : 'post'](emEdicao.value ? `${BASE}/${emEdicao.value.id}` : BASE, dados, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onError: (e) => { erros.value = e; toast.error(Object.values(e)[0]); },
        onFinish: () => { processando.value = false; },
    });
}

// Terminar
const paraTerminar = ref(null);
const motivoTermino = ref('');
function terminar() {
    processando.value = true;
    router.patch(`${BASE}/${paraTerminar.value.id}/terminar`, { motivo: motivoTermino.value }, {
        preserveScroll: true,
        onSuccess: () => { paraTerminar.value = null; motivoTermino.value = ''; },
        onError: (e) => { erros.value = e; toast.error(Object.values(e)[0]); },
        onFinish: () => { processando.value = false; },
    });
}

// Aplicar às propinas em aberto
const paraAplicar = ref(null);
function aplicar() {
    processando.value = true;
    router.post(`${BASE}/${paraAplicar.value.id}/aplicar`, {}, {
        preserveScroll: true,
        onSuccess: () => { paraAplicar.value = null; },
        onError: (e) => toast.error(Object.values(e)[0]),
        onFinish: () => { processando.value = false; },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Bolsas</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Bolsas de estudo que cobrem uma percentagem das propinas de um aluno num ano lectivo. As propinas geradas dentro das datas
                    nascem já com o desconto; para as que já existem, use «Aplicar às propinas em aberto».
                </p>
            </div>
            <button v-if="can('bolsa.criar')" type="button" class="btn btn-primary" @click="abrirCriar">Nova bolsa</button>
        </div>

        <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" />

        <FiltrosRecolhiveis v-model="filtros.pesquisa" placeholder="Pesquisar aluno, nº do aluno ou nome da bolsa" :ativos="filtrosAtivos" @limpar="limparFiltros">
                    <div class="col-6 col-md-4"><label class="fw-semibold fs-7 text-muted mb-1">Ano lectivo</label><SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAno" /></div>
                    <div class="col-6 col-md-3"><label class="fw-semibold fs-7 text-muted mb-1">Estado</label><SelectSolid v-model="filtros.estado" :options="opcoesEstado" /></div>
        </FiltrosRecolhiveis>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-200px">Aluno</th>
                                <th class="min-w-175px">Bolsa</th>
                                <th class="text-center min-w-100px">Cobre</th>
                                <th class="min-w-175px">Vigência</th>
                                <th class="text-center min-w-100px">Propinas</th>
                                <th class="min-w-100px">Estado</th>
                                <th class="text-end min-w-200px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="bolsas.data.length === 0"><td colspan="7" class="text-center text-muted py-6">Nenhuma bolsa encontrada.</td></tr>
                            <tr v-for="b in bolsas.data" :key="b.id">
                                <td class="text-gray-800"><span class="text-uppercase">{{ b.aluno_nome ?? '—' }}</span><div class="text-muted fs-7">nº {{ b.aluno_numero ?? '—' }}</div></td>
                                <td class="text-gray-800">{{ b.nome }}<div class="text-muted fs-7">{{ b.motivo }}</div></td>
                                <td class="text-center"><span class="badge badge-light-success fs-6">{{ b.percentagem }}%</span></td>
                                <td>{{ dataCurta(b.data_inicio) }} → {{ dataCurta(b.data_fim) }}<div class="text-muted fs-7">{{ b.ano_lectivo_nome }}</div></td>
                                <td class="text-center">{{ b.propinas_com_bolsa }}</td>
                                <td><span class="badge" :class="b.estado === 1 ? 'badge-light-success' : 'badge-light-dark'">{{ b.estado_descricao }}</span><div v-if="b.motivo_termino" class="text-muted fs-8">{{ b.motivo_termino }}</div></td>
                                <td class="text-end text-nowrap">
                                    <template v-if="b.estado === 1 && can('bolsa.editar')">
                                        <button type="button" class="btn btn-sm btn-light-primary me-1" @click="paraAplicar = b">Aplicar às propinas em aberto</button>
                                        <button type="button" class="btn btn-sm btn-light me-1" @click="abrirEditar(b)">Editar</button>
                                        <button type="button" class="btn btn-sm btn-light-danger" @click="paraTerminar = b">Terminar</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="bolsas.data.length" class="card-footer d-flex justify-content-end"><Pagination :links="bolsas.links" /></div>
        </div>

        <!-- Criar / editar -->
        <div v-if="modal" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="modal = false">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content p-6">
                    <h3 class="mb-5">{{ emEdicao ? 'Editar bolsa' : 'Nova bolsa' }}</h3>

                    <div v-if="!emEdicao" class="mb-5">
                        <label class="required fw-semibold fs-6 mb-2">Aluno</label>
                        <div v-if="matricula" class="d-flex justify-content-between align-items-center bg-body-secondary rounded p-3">
                            <div><strong class="text-uppercase">{{ matricula.aluno_nome }}</strong> · nº {{ matricula.aluno_numero }}<div class="text-muted fs-7">Matrícula {{ matricula.numero }} · {{ matricula.ano_lectivo_nome }}</div></div>
                            <button type="button" class="btn btn-sm btn-light" @click="matricula = null">Trocar</button>
                        </div>
                        <div v-else>
                            <input v-model="termo" type="text" class="form-control form-control-solid" placeholder="Nome, nº de estudante ou nº da matrícula" @input="pesquisar" />
                            <div v-for="m in resultados" :key="m.id" class="py-3 border-bottom border-dashed" role="button" @click="escolher(m)">
                                <strong class="text-uppercase">{{ m.aluno_nome }}</strong> <span class="text-muted">· nº {{ m.aluno_numero }}</span>
                                <div class="text-muted fs-7">Matrícula {{ m.numero }} · {{ m.ano_lectivo_nome }} · {{ m.estado_descricao }}</div>
                            </div>
                        </div>
                        <div class="text-danger fs-7 mt-1">{{ erros.matricula_id }}</div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-8"><label class="required fw-semibold fs-6 mb-2">Nome da bolsa</label><input v-model="form.nome" type="text" maxlength="150" class="form-control form-control-solid" placeholder="ex.: Bolsa de mérito" /><div class="text-danger fs-7">{{ erros.nome }}</div></div>
                        <div class="col-md-4"><label class="required fw-semibold fs-6 mb-2">Cobre (%)</label><input v-model="form.percentagem" type="text" inputmode="decimal" class="form-control form-control-solid" placeholder="ex.: 50" /><div class="text-danger fs-7">{{ erros.percentagem }}</div></div>
                        <div class="col-md-6"><label class="required fw-semibold fs-6 mb-2">Início</label><input v-model="form.data_inicio" type="date" class="form-control form-control-solid" /><div class="text-danger fs-7">{{ erros.data_inicio }}</div></div>
                        <div class="col-md-6"><label class="required fw-semibold fs-6 mb-2">Fim</label><input v-model="form.data_fim" type="date" class="form-control form-control-solid" /><div class="text-danger fs-7">{{ erros.data_fim }}</div></div>
                        <div class="col-12"><label class="required fw-semibold fs-6 mb-2">Motivo</label><textarea v-model="form.motivo" rows="2" maxlength="1000" class="form-control form-control-solid"></textarea><div class="text-danger fs-7">{{ erros.motivo }}</div></div>
                    </div>
                    <div class="form-text mt-3">Bolsa de 100% cobre tudo: as propinas desse período deixam de ser geradas. Editar só afecta propinas geradas depois.</div>

                    <div class="text-end mt-5">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processando" @click="modal = false">Cancelar</button>
                        <button type="button" class="btn btn-primary" :disabled="processando || (!emEdicao && !matricula)" @click="guardar">Guardar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Terminar -->
        <div v-if="paraTerminar" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="paraTerminar = null">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content p-6">
                    <h3 class="mb-3">Terminar bolsa</h3>
                    <p class="text-gray-700">«{{ paraTerminar.nome }}» deixa de se aplicar a propinas geradas daqui em diante. As que já nasceram com desconto não mudam.</p>
                    <textarea v-model="motivoTermino" rows="2" maxlength="1000" class="form-control form-control-solid mb-1" placeholder="Motivo (obrigatório)"></textarea>
                    <div class="text-danger fs-7 mb-3">{{ erros.motivo }}</div>
                    <div class="text-end">
                        <button type="button" class="btn btn-light me-2" :disabled="processando" @click="paraTerminar = null">Voltar</button>
                        <button type="button" class="btn btn-danger" :disabled="processando || motivoTermino.trim().length < 5" @click="terminar">Terminar bolsa</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aplicar -->
        <div v-if="paraAplicar" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="paraAplicar = null">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content p-6">
                    <h3 class="mb-3">Aplicar às propinas em aberto</h3>
                    <p class="text-gray-700">
                        Reduz em {{ paraAplicar.percentagem }}% as propinas de {{ paraAplicar.aluno_nome }} que estão <strong>Em Aberto, sem nenhum pagamento</strong>,
                        entre {{ dataCurta(paraAplicar.data_inicio) }} e {{ dataCurta(paraAplicar.data_fim) }}. As já pagas ou parcialmente pagas não mudam.
                    </p>
                    <div class="text-end">
                        <button type="button" class="btn btn-light me-2" :disabled="processando" @click="paraAplicar = null">Cancelar</button>
                        <button type="button" class="btn btn-primary" :disabled="processando" @click="aplicar">Aplicar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
