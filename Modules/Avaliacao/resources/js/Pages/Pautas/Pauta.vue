<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    turmas: { type: Array, required: true }, // [{ id, rotulo, estado }]
    selecao: { type: Object, required: true }, // { turma }
    pauta: { type: Object, default: null },
    pode: { type: Object, required: true }, // { aprovar, reabrir }
});
defineOptions({ layout: AppLayout });

const turma = ref(props.selecao.turma);
const aConfirmar = ref(false);
const aReabrir = ref(false);
const motivo = ref('');

const ESTADOS = {
    provisoria: { texto: 'Provisória', classe: 'badge-light-warning' },
    definitiva: { texto: 'Definitiva', classe: 'badge-light-success' },
    reaberta: { texto: 'Reaberta', classe: 'badge-light-danger' },
};

const aoFalhar = (e) => toast.error(Object.values(e)[0]);
const abrir = () => turma.value && router.get('/avaliacao/pautas', { turma: turma.value }, { preserveState: true });

function aprovar() {
    router.post(`/avaliacao/pautas/${props.pauta.turma_id}/aprovar`, {}, {
        preserveScroll: true, preserveState: true, onError: aoFalhar,
        onSuccess: () => { aConfirmar.value = false; toast.success('Pauta aprovada. As notas desta turma ficaram bloqueadas.'); },
    });
}

function reabrir() {
    router.post(`/avaliacao/pautas/${props.pauta.turma_id}/reabrir`, { motivo: motivo.value }, {
        preserveScroll: true, preserveState: true, onError: aoFalhar,
        onSuccess: () => { aReabrir.value = false; motivo.value = ''; toast.success('Pauta reaberta.'); },
    });
}

const imprimir = () => window.print();

const escolhas = reactive({}); // matricula -> decisão escolhida (0 Transita, 1 Não transita) ainda por gravar
const motivos = reactive({});

function decidir(a, valor) {
    valor = Number(valor);
    escolhas[a.matricula_id] = valor;
    if (valor === 1 && !(motivos[a.matricula_id] ?? a.motivo_progressao ?? '').trim()) return; // espera pelo motivo
    router.post(`/avaliacao/pautas/${props.pauta.turma_id}/progressao`, {
        matricula_id: a.matricula_id, decisao: valor, motivo: valor === 1 ? (motivos[a.matricula_id] ?? a.motivo_progressao) : null,
    }, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => { delete escolhas[a.matricula_id]; toast.success('Progressão guardada.'); } });
}
const textoDecisao = (a) => a.decisao?.rotulo ?? 'Pendente';
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6 d-print-none">
            <h1 class="fs-2 fw-bold mb-1">Pautas finais</h1>
            <div class="text-muted">Resultado final de cada turma. A pauta só é formal depois de aprovada.</div>
        </div>

        <div class="card card-flush mb-6 d-print-none">
            <div class="card-body py-4">
                <label class="form-label fs-7 fw-bold text-muted text-uppercase">Turma</label>
                <select v-model="turma" class="form-select form-select-sm w-100 w-md-50" @change="abrir">
                    <option :value="null" disabled>Escolha a turma</option>
                    <option v-for="t in turmas" :key="t.id" :value="t.id">{{ t.rotulo }} — {{ ESTADOS[t.estado].texto }}</option>
                </select>
            </div>
        </div>

        <div v-if="pauta" class="card card-flush">
            <div class="card-header pt-5">
                <div class="card-title flex-column align-items-start">
                    <h3 class="fw-bold mb-1">{{ pauta.turma_rotulo }}
                        <span class="badge ms-3" :class="ESTADOS[pauta.estado].classe">{{ ESTADOS[pauta.estado].texto }}{{ pauta.versao > 1 ? ` · v${pauta.versao}` : '' }}</span>
                    </h3>
                    <div v-if="pauta.regra?.qualitativa" class="text-muted fs-7">Avaliação qualitativa · escala «{{ pauta.regra.escala_nome }}» · a MFD é o nível do 3.º trimestre</div>
                    <div v-else-if="pauta.regra" class="text-muted fs-7">Escala 0–{{ pauta.regra.escala_maxima }} · mínimo {{ pauta.regra.nota_minima }}</div>
                    <div v-if="pauta.aprovada_em" class="text-muted fs-7">Aprovada em {{ pauta.aprovada_em }}{{ pauta.aprovada_por ? ` por ${pauta.aprovada_por}` : '' }}</div>
                </div>
                <div class="card-toolbar gap-2 d-print-none">
                    <button type="button" class="btn btn-sm btn-light" @click="imprimir">Imprimir</button>
                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/exportar?formato=pdf`" class="btn btn-sm btn-light">PDF</a>
                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/exportar?formato=excel`" class="btn btn-sm btn-light">Excel</a>
                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/boletins`" class="btn btn-sm btn-light" title="Um boletim por aluno, cada um na sua página">Boletins (PDF)</a>
                    <button v-if="pode.aprovar && pauta.estado !== 'definitiva'" type="button" class="btn btn-sm btn-primary" :disabled="!pauta.pode_aprovar" @click="aConfirmar = true">Aprovar pauta</button>
                    <button v-if="pode.reabrir && pauta.estado === 'definitiva'" type="button" class="btn btn-sm btn-light-danger" @click="aReabrir = true">Reabrir pauta</button>
                </div>
            </div>

            <div class="card-body pt-3">
                <div v-if="pauta.estado !== 'definitiva' && pauta.bloqueios.length" class="alert alert-warning py-3 d-print-none">
                    <div class="fw-bold mb-1">Ainda não se pode aprovar:</div>
                    <div v-for="(b, i) in pauta.bloqueios" :key="i">• {{ b }}</div>
                </div>

                <div v-if="pauta.avisos?.length" class="alert alert-info py-3 d-print-none">
                    <div v-for="(av, i) in pauta.avisos" :key="i">• {{ av }}</div>
                </div>

                <div v-if="aConfirmar" class="border rounded p-4 mb-5 bg-body-secondary d-print-none">
                    <div class="fw-bold mb-2">Aprovar esta pauta?</div>
                    <div class="text-muted mb-3">Os resultados passam a definitivos, a decisão de cada aluno fica formalizada e as notas desta turma ficam bloqueadas até a pauta ser reaberta.</div>
                    <button type="button" class="btn btn-sm btn-primary me-2" @click="aprovar">Confirmar aprovação</button>
                    <button type="button" class="btn btn-sm btn-light" @click="aConfirmar = false">Cancelar</button>
                </div>

                <div v-if="aReabrir" class="border rounded p-4 mb-5 bg-body-secondary d-print-none">
                    <div class="fw-bold mb-2">Reabrir esta pauta</div>
                    <div class="d-flex flex-wrap gap-3">
                        <input v-model="motivo" type="text" class="form-control form-control-sm w-300px" placeholder="Motivo da reabertura">
                        <button type="button" class="btn btn-sm btn-danger" :disabled="!motivo.trim()" @click="reabrir">Reabrir</button>
                        <button type="button" class="btn btn-sm btn-light" @click="aReabrir = false">Cancelar</button>
                    </div>
                </div>

                <div v-if="!pauta.regra" class="alert alert-warning">A escola ainda não configurou a regra de avaliação desta classe.</div>
                <div v-else-if="!pauta.alunos.length" class="text-center text-muted py-8">A turma não tem alunos com matrícula activa.</div>

                <div v-else class="table-responsive">
                    <table class="table table-bordered align-middle fs-7 text-center mb-0">
                        <thead>
                            <tr class="fw-bold text-uppercase">
                                <th rowspan="2" style="width: 2.5rem">N.º</th>
                                <th rowspan="2" class="text-start">Nome do aluno</th>
                                <th v-for="d in pauta.disciplinas" :key="d.id" :colspan="(pauta.com_exame ? 3 : 4) + (pauta.com_recurso ? 2 : 0)">{{ d.nome }}</th>
                                <th rowspan="2">Resultado final</th>
                            </tr>
                            <tr class="text-muted">
                                <template v-for="d in pauta.disciplinas" :key="d.id">
                                    <template v-if="pauta.com_exame"><th>MFD</th><th>NE</th><th>MF</th></template>
                                    <template v-else><th>MT1</th><th>MT2</th><th>MT3</th><th>MFD</th></template>
                                    <template v-if="pauta.com_recurso"><th>NER</th><th>MFR</th></template>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, n) in pauta.alunos" :key="a.matricula_id">
                                <td class="text-muted">{{ n + 1 }}</td>
                                <td class="text-start fw-semibold text-nowrap">{{ a.nome }}
                                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/boletins?matricula=${a.matricula_id}`" class="ms-2 fs-8 fw-normal d-print-none" title="Boletim deste aluno">Boletim</a></td>
                                <template v-for="d in pauta.disciplinas" :key="d.id">
                                    <template v-if="pauta.com_exame">
                                        <td>{{ a.celulas[d.id]?.mfd ?? '—' }}</td>
                                        <td>{{ a.celulas[d.id]?.ne ?? '—' }}</td>
                                        <td class="fw-bold" :class="a.celulas[d.id]?.aprovado === false ? 'text-danger' : ''">{{ a.celulas[d.id]?.mf ?? '—' }}</td>
                                    </template>
                                    <template v-else>
                                    <td>{{ a.celulas[d.id]?.mt1 ?? '—' }}</td>
                                    <td>{{ a.celulas[d.id]?.mt2 ?? '—' }}</td>
                                    <td>{{ a.celulas[d.id]?.mt3 ?? '—' }}</td>
                                    <td class="fw-bold" :class="a.celulas[d.id]?.aprovado === false ? 'text-danger' : ''">{{ a.celulas[d.id]?.mfd ?? '—' }}</td>
                                    </template>
                                    <template v-if="pauta.com_recurso">
                                        <td>{{ a.celulas[d.id]?.ner ?? '' }}</td>
                                        <td class="fw-bold" :class="a.celulas[d.id]?.mfr != null && a.celulas[d.id]?.aprovado === false ? 'text-danger' : ''">{{ a.celulas[d.id]?.mfr ?? '' }}</td>
                                    </template>
                                </template>
                                <td class="fw-bold text-nowrap" :title="a.recurso?.texto" :class="a.decisao?.valor === 1 || a.decisao?.valor === 3 ? 'text-danger' : (a.decisao ? 'text-success' : 'text-muted')"><template v-if="pauta.progressao_manual && pauta.estado !== 'definitiva' && pode.aprovar">
                                        <select :value="escolhas[a.matricula_id] ?? a.decisao?.valor ?? ''" class="form-select form-select-sm w-150px d-print-none" @change="decidir(a, $event.target.value)">
                                            <option value="" disabled>Decidir…</option>
                                            <option :value="0">Transita</option>
                                            <option :value="1">Não transita</option>
                                        </select>
                                        <input v-if="(escolhas[a.matricula_id] ?? a.decisao?.valor) === 1" v-model="motivos[a.matricula_id]" type="text" class="form-control form-control-sm mt-1 d-print-none"
                                            placeholder="Motivo" :value="a.motivo_progressao" @blur="decidir(a, 1)">
                                    </template>
                                    <template v-else>{{ textoDecisao(a) }}</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
