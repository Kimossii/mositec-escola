<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import TratarNegativaModal from '../../Components/Notas/TratarNegativaModal.vue';

const props = defineProps({
    turmas: { type: Array, required: true }, // [{ id, rotulo, estado }]
    selecao: { type: Object, required: true }, // { turma }
    pauta: { type: Object, default: null },
    pode: { type: Object, required: true }, // { aprovar, reabrir, tratar_negativa }
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
const fase = computed(() => props.pauta?.fase ?? props.selecao.fase ?? 1);
const abrir = (f = 1) => turma.value && router.get('/avaliacao/pautas', { turma: turma.value, fase: typeof f === 'number' ? f : 1 }, { preserveState: true });
const abrirFase = (f) => router.get('/avaliacao/pautas', { turma: turma.value, fase: f }, { preserveState: true });

function aprovar() {
    router.post(`/avaliacao/pautas/${props.pauta.turma_id}/aprovar`, { fase: fase.value }, {
        preserveScroll: true, preserveState: true, onError: aoFalhar,
        onSuccess: () => { aConfirmar.value = false; toast.success(fase.value === 2 ? 'Pauta do recurso aprovada. O processo da turma está fechado.' : 'Pauta aprovada. As notas desta turma ficaram bloqueadas.'); },
    });
}

function reabrir() {
    router.post(`/avaliacao/pautas/${props.pauta.turma_id}/reabrir`, { motivo: motivo.value, fase: fase.value }, {
        preserveScroll: true, preserveState: true, onError: aoFalhar,
        onSuccess: () => { aReabrir.value = false; motivo.value = ''; toast.success('Pauta reaberta.'); },
    });
}

const opcoesTurmas = computed(() => props.turmas.map((t) => ({ value: t.id, label: `${t.rotulo} — ${ESTADOS[t.estado].texto}` })));
const opcoesDecisao = [{ value: 0, label: 'Transita' }, { value: 1, label: 'Não transita' }];
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
// Nota abaixo do mínimo da escola: a vermelho.
const negativaDe = (v) => v !== null && v !== undefined && v !== '' && Number(v) < Number(props.pauta?.regra?.nota_minima);
// A negativa desta disciplina do aluno (para o link «Tratar» na própria célula).
const tratamentoDe = (a, d) => a.negativas?.find((n) => n.inscricao_id === a.celulas[d.id]?.inscricao_id) ?? null;
const aTratar = ref(null); // { inscricao_id, aluno, disciplina, medida }
const tratar = (a, n) => { aTratar.value = { inscricao_id: n.inscricao_id, aluno: a.nome, disciplina: n.disciplina, medida: n.medida }; };
const textoDecisao = (a) => a.decisao?.rotulo ?? (a.negativas?.some((n) => !n.medida) && props.pauta?.trata_negativas ? 'Por tratar' : 'Pendente');
</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="mb-6 d-print-none">
            <h1 class="fs-2 fw-bold mb-1">Pautas finais</h1>
            <div class="text-body-secondary">Resultado final de cada turma. A pauta só é formal depois de aprovada.</div>
        </div>

        <div class="card card-flush mb-6 d-print-none">
            <div class="card-body py-4">
                <label class="form-label fs-7 fw-bold text-body-secondary text-uppercase">Turma</label>
                <SelectSolid v-model="turma" :options="opcoesTurmas" searchable placeholder="Escolha a turma" class="w-100 w-md-50" @update:model-value="nextTick(abrir)" />
            </div>
        </div>

        <div v-if="pauta" class="card card-flush">
            <div class="card-header pt-5">
                <div class="card-title flex-column align-items-start">
                    <h3 class="fw-bold mb-1">{{ pauta.turma_rotulo }}
                        <span class="badge ms-3" :class="ESTADOS[pauta.estado].classe">{{ ESTADOS[pauta.estado].texto }}{{ pauta.versao > 1 ? ` · v${pauta.versao}` : '' }}</span>
                    </h3>
                    <div v-if="pauta.regra?.qualitativa" class="text-body-secondary fs-7">Avaliação qualitativa · escala «{{ pauta.regra.escala_nome }}» · a MFD é o nível do 3.º trimestre</div>
                    <div v-else-if="pauta.regra" class="text-body-secondary fs-7">Escala 0–{{ pauta.regra.escala_maxima }} · mínimo {{ pauta.regra.nota_minima }}</div>
                    <div v-if="pauta.aprovada_em" class="text-body-secondary fs-7">Aprovada em {{ pauta.aprovada_em }}{{ pauta.aprovada_por ? ` por ${pauta.aprovada_por}` : '' }}</div>
                </div>
                <div class="card-toolbar gap-2 d-print-none">
                    <button type="button" class="btn btn-sm btn-light" @click="imprimir">Imprimir</button>
                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/exportar?formato=pdf&fase=${fase}`" class="btn btn-sm btn-light">PDF</a>
                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/exportar?formato=excel&fase=${fase}`" class="btn btn-sm btn-light">Excel</a>
                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/boletins`" class="btn btn-sm btn-light" title="Um boletim por aluno, cada um na sua página">Boletins (PDF)</a>
                    <button v-if="pode.aprovar && pauta.estado !== 'definitiva'" type="button" class="btn btn-sm btn-primary" :disabled="!pauta.pode_aprovar" @click="aConfirmar = true">Aprovar pauta</button>
                    <button v-if="pode.reabrir && pauta.estado === 'definitiva'" type="button" class="btn btn-sm btn-light-danger" @click="aReabrir = true">Reabrir pauta</button>
                </div>
            </div>

            <div class="card-body pt-3">
                <div v-if="pauta.fases?.recurso" class="btn-group mb-4 d-print-none">
                    <button type="button" class="btn btn-sm" :class="fase === 1 ? 'btn-primary' : 'btn-light'" @click="abrirFase(1)">Pauta</button>
                    <button type="button" class="btn btn-sm" :class="fase === 2 ? 'btn-primary' : 'btn-light'" @click="abrirFase(2)">Pauta do recurso</button>
                </div>

                <div v-if="pauta.estado !== 'definitiva' && pauta.bloqueios.length" class="d-flex align-items-start gap-4 rounded bg-warning p-4 mb-4 d-print-none" style="color: #181C32;">
                    <i class="ki-duotone ki-information-5 fs-2x mt-1" style="color: #181C32;"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                    <div>
                        <div class="fw-bolder mb-1">Ainda não se pode aprovar</div>
                        <div v-for="(b, i) in pauta.bloqueios" :key="i" class="fw-semibold">{{ b }}</div>
                    </div>
                </div>

                <div v-if="pauta.avisos?.length" class="alert alert-info py-3 d-print-none">
                    <div v-for="(av, i) in pauta.avisos" :key="i">• {{ av }}</div>
                </div>

                <div v-if="aConfirmar" class="border rounded p-4 mb-5 bg-body-secondary d-print-none">
                    <div class="fw-bold mb-2">Aprovar esta pauta?</div>
                    <div class="text-body-secondary mb-3">Os resultados passam a definitivos, a decisão de cada aluno fica formalizada e as notas desta turma ficam bloqueadas até a pauta ser reaberta.</div>
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
                <div v-else-if="!pauta.alunos.length" class="text-center text-body-secondary py-8">{{ fase === 2 ? 'Nenhum aluno está em recurso.' : 'A turma não tem alunos com matrícula activa.' }}</div>

                <div v-else class="table-responsive">
                    <table class="pauta-tabela table table-bordered align-middle fs-7 text-center mb-0">
                        <thead>
                            <tr class="fw-bold text-uppercase">
                                <th rowspan="2" style="width: 2.5rem">N.º</th>
                                <th rowspan="2" class="text-start">Nome do aluno</th>
                                <th v-for="d in pauta.disciplinas" :key="d.id" class="pauta-grupo pauta-disciplina" :colspan="(pauta.com_exame ? 3 : 4) + (pauta.mostra_recurso ? 2 : 0)">{{ d.nome }}</th>
                                <th rowspan="2" class="pauta-grupo">Resultado final</th>
                            </tr>
                            <tr class="text-body-secondary">
                                <template v-for="d in pauta.disciplinas" :key="d.id">
                                    <template v-if="pauta.com_exame"><th class="pauta-grupo">MFD</th><th>NE</th><th>MF</th></template>
                                    <template v-else><th class="pauta-grupo">MT1</th><th>MT2</th><th>MT3</th><th>MFD</th></template>
                                    <template v-if="pauta.mostra_recurso"><th>NER</th><th>MFR</th></template>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, n) in pauta.alunos" :key="a.matricula_id">
                                <td class="text-body-secondary">{{ n + 1 }}</td>
                                <td class="text-start fw-semibold text-nowrap">{{ a.nome }}
                                    <a :href="`/avaliacao/pautas/${pauta.turma_id}/boletins?matricula=${a.matricula_id}`" class="ms-2 fs-8 fw-normal d-print-none" title="Boletim deste aluno">Boletim</a></td>
                                <template v-for="d in pauta.disciplinas" :key="d.id">
                                    <template v-if="pauta.com_exame">
                                        <td class="pauta-grupo">{{ a.celulas[d.id]?.mfd ?? '—' }}</td>
                                        <td>{{ a.celulas[d.id]?.ne ?? '—' }}</td>
                                        <td class="fw-bold" :class="a.celulas[d.id]?.aprovado === false ? 'text-danger' : ''">{{ a.celulas[d.id]?.mf ?? '—' }}<div v-if="pauta.trata_negativas && tratamentoDe(a, d)" class="fs-8 fw-semibold d-print-none">
                                            <a v-if="pode.tratar_negativa && pauta.estado !== 'definitiva'" href="#" :class="tratamentoDe(a, d).medida ? 'text-success' : 'text-danger'" :title="tratamentoDe(a, d).medida ?? 'Negativa por tratar'" @click.prevent="tratar(a, tratamentoDe(a, d))">{{ tratamentoDe(a, d).medida ? 'Tratada' : 'Tratar' }}</a>
                                            <span v-else :class="tratamentoDe(a, d).medida ? 'text-success' : 'text-danger'" :title="tratamentoDe(a, d).medida ?? ''">{{ tratamentoDe(a, d).medida ? 'Tratada' : 'Por tratar' }}</span>
                                        </div></td>
                                    </template>
                                    <template v-else>
                                    <td class="pauta-grupo" :class="negativaDe(a.celulas[d.id]?.mt1) ? 'text-danger' : ''">{{ a.celulas[d.id]?.mt1 ?? '—' }}</td>
                                    <td :class="negativaDe(a.celulas[d.id]?.mt2) ? 'text-danger' : ''">{{ a.celulas[d.id]?.mt2 ?? '—' }}</td>
                                    <td :class="negativaDe(a.celulas[d.id]?.mt3) ? 'text-danger' : ''">{{ a.celulas[d.id]?.mt3 ?? '—' }}</td>
                                    <td class="fw-bold" :class="a.celulas[d.id]?.aprovado === false ? 'text-danger' : ''">{{ a.celulas[d.id]?.mfd ?? '—' }}<div v-if="pauta.trata_negativas && tratamentoDe(a, d)" class="fs-8 fw-semibold d-print-none">
                                            <a v-if="pode.tratar_negativa && pauta.estado !== 'definitiva'" href="#" :class="tratamentoDe(a, d).medida ? 'text-success' : 'text-danger'" :title="tratamentoDe(a, d).medida ?? 'Negativa por tratar'" @click.prevent="tratar(a, tratamentoDe(a, d))">{{ tratamentoDe(a, d).medida ? 'Tratada' : 'Tratar' }}</a>
                                            <span v-else :class="tratamentoDe(a, d).medida ? 'text-success' : 'text-danger'" :title="tratamentoDe(a, d).medida ?? ''">{{ tratamentoDe(a, d).medida ? 'Tratada' : 'Por tratar' }}</span>
                                        </div></td>
                                    </template>
                                    <template v-if="pauta.mostra_recurso">
                                        <td>{{ a.celulas[d.id]?.ner ?? '' }}</td>
                                        <td class="fw-bold" :class="a.celulas[d.id]?.mfr != null && a.celulas[d.id]?.aprovado === false ? 'text-danger' : ''">{{ a.celulas[d.id]?.mfr ?? '' }}</td>
                                    </template>
                                </template>
                                <td class="pauta-grupo fw-bold text-nowrap" :title="a.recurso?.texto" :class="a.decisao?.valor === 1 || a.decisao?.valor === 3 ? 'text-danger' : (a.decisao?.valor === 4 ? 'text-primary' : (a.decisao ? 'text-success' : 'text-body-secondary'))"><template v-if="pauta.progressao_manual && pauta.estado !== 'definitiva' && pode.aprovar">
                                        <SelectSolid :model-value="escolhas[a.matricula_id] ?? a.decisao?.valor ?? ''" :options="opcoesDecisao" pequeno placeholder="Decidir…" class="w-150px d-print-none" @update:model-value="(v) => decidir(a, v)" />
                                        <input v-if="(escolhas[a.matricula_id] ?? a.decisao?.valor) === 1" type="text" class="form-control form-control-sm mt-1 d-print-none"
                                            placeholder="Motivo" :value="motivos[a.matricula_id] ?? a.motivo_progressao" @input="motivos[a.matricula_id] = $event.target.value" @blur="decidir(a, 1)">
                                    </template>
                                    <template v-else>{{ textoDecisao(a) }}</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <TratarNegativaModal :alvo="aTratar" @fechar="aTratar = null" />
    </div>
</template>

<style scoped>
/* Divisão marcada entre disciplinas (e antes do resultado final), legível nos dois temas. */
.pauta-grupo { border-left: 2px solid var(--bs-gray-700) !important; }
.pauta-disciplina { background: var(--bs-gray-200); }

/* Tema claro: as linhas do tema (#F4F4F4) quase não se vêem sobre o branco. Só aqui, só no claro; o escuro já se distingue. */
html[data-bs-theme='light'] .pauta-tabela,
html[data-bs-theme='light'] .pauta-tabela tr,
html[data-bs-theme='light'] .pauta-tabela th,
html[data-bs-theme='light'] .pauta-tabela td { border-color: #B9C0CF !important; }
/* Contorno: linha de cima (primeira linha do cabeçalho), de baixo (última do corpo) e separação cabeçalho/corpo. */
html[data-bs-theme='light'] .pauta-tabela thead tr:first-child > * { border-top: 2px solid #4B5675 !important; }
html[data-bs-theme='light'] .pauta-tabela thead tr:last-child > *,
html[data-bs-theme='light'] .pauta-tabela thead th[rowspan] { border-bottom: 2px solid #4B5675 !important; }
html[data-bs-theme='light'] .pauta-tabela tbody tr:last-child > * { border-bottom: 2px solid #4B5675 !important; }
html[data-bs-theme='light'] .pauta-grupo { border-left-color: #4B5675 !important; }
html[data-bs-theme='light'] .pauta-disciplina { background: #E9ECF3; }
</style>
