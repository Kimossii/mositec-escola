<script setup>
import { computed, reactive, ref, watchEffect } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import TratarNegativaModal from '../../Components/Notas/TratarNegativaModal.vue';

const props = defineProps({
    opcoes: { type: Array, required: true }, // [{ turma_id, turma_rotulo, disciplinas: [{ disciplina_id, disciplina_nome }] }]
    selecao: { type: Object, required: true }, // { turma, disciplina }
    pauta: { type: Object, default: null },
    tipos: { type: Array, required: true }, // [{ valor, nome }]
    pode: { type: Object, required: true }, // { anular, criar, fora_prazo }
});
defineOptions({ layout: AppLayout });

const MAC = 0;
const PT = 1;
const ESTADO_ANULADA = 1;

const turma = ref(props.selecao.turma);
const disciplina = ref(props.selecao.disciplina);
const disciplinasDaTurma = computed(() => props.opcoes.find((o) => o.turma_id === turma.value)?.disciplinas ?? []);

const valores = reactive({}); // `${inscricao}-${instrumento}` -> texto digitado
const motivoForaDoPrazo = ref('');
const aAnular = ref(null); // { nota_id, aluno, instrumento }
const anulacao = reactive({ motivo: '', conta_como_zero: false });
const novo = reactive({ tipo: MAC, obrigatorio: true });
const modalNova = ref(false);

// Só há uma prova trimestral por trimestre: depois de criada, deixa de se oferecer.
const macsCriadas = computed(() => (props.pauta?.instrumentos ?? []).filter((i) => i.tipo === MAC).length);
const maxAvaliacoes = computed(() => props.pauta?.regra?.max_avaliacoes ?? 10);
const minAvaliacoes = computed(() => props.pauta?.regra?.min_avaliacoes ?? 5);
// Nota abaixo do mínimo da escola: a vermelho, como na pauta final.
const negativaDe = (v) => v !== null && v !== undefined && v !== '' && Number(v) < Number(props.pauta?.regra?.nota_minima);
const negativasTotal = computed(() => (props.pauta?.alunos ?? []).filter((a) => a.negativa).length);
const negativasPorTratar = computed(() => (props.pauta?.alunos ?? []).filter((a) => a.negativa && !a.medida_negativa).length);
const aTratar = ref(null); // { inscricao_id, aluno, disciplina, medida }
const tratar = (a) => { aTratar.value = { inscricao_id: a.inscricao_id, aluno: a.nome, disciplina: props.pauta.disciplina_nome, medida: a.medida_negativa }; };
const limiteAtingido = computed(() => macsCriadas.value >= maxAvaliacoes.value);
const opcoesTurmas = computed(() => props.opcoes.map((o) => ({ value: o.turma_id, label: o.turma_rotulo })));
const opcoesDisciplinas = computed(() => disciplinasDaTurma.value.map((d) => ({ value: d.disciplina_id, label: d.disciplina_nome })));
const opcoesNiveis = computed(() => (props.pauta?.escala?.niveis ?? []).map((nv) => ({ value: nv.id, label: nv.designacao })));
const tiposPossiveis = computed(() => props.tipos.filter((t) => (t.valor !== PT || !(props.pauta?.instrumentos ?? []).some((i) => i.tipo === PT)) && (t.valor !== MAC || !limiteAtingido.value)));
// Se o tipo escolhido deixou de se poder criar (limite atingido, prova já criada), passa ao primeiro possível.
const opcoesTipos = computed(() => tiposPossiveis.value.map((t) => ({ value: t.valor, label: t.nome })));
watchEffect(() => { if (tiposPossiveis.value.length && !tiposPossiveis.value.some((t) => t.valor === novo.tipo)) novo.tipo = tiposPossiveis.value[0].valor; });

const aoFalhar = (e) => toast.error(Object.values(e)[0]);
const tipoDoInstrumento = (i) => (i.tipo === MAC ? 'MAC' : 'PT');
const encerrado = (i) => props.pauta?.prazos?.[tipoDoInstrumento(i)]?.encerrado ?? false;
const algumEncerrado = computed(() => (props.pauta?.instrumentos ?? []).some(encerrado) || (props.pauta?.exame && props.pauta.prazos.EXAME.encerrado) || (props.pauta?.recurso && props.pauta.prazos.RECURSO.encerrado));

function abrir(turmaId, disciplinaId, periodoId = null) {
    if (!turmaId || !disciplinaId) return;
    if (alteracoes.value.total > 0 && !window.confirm('Tem notas por guardar. Sair sem guardar?')) return;
    router.get('/avaliacao/notas', { turma: turmaId, disciplina: disciplinaId, periodo: periodoId ?? undefined }, { preserveState: true });
}

function textoDaCelula(aluno, instrumento) {
    const chave = `${aluno.inscricao_id}-${instrumento.id}`;
    if (chave in valores) return valores[chave];
    const nota = aluno.notas[instrumento.id];
    return nota?.valor ?? '';
}

const recursos = reactive({}); // inscricao -> texto da NER
const textoRecurso = (a) => recursos[a.inscricao_id] ?? a.ner?.valor ?? '';

const exames = reactive({}); // inscricao -> texto da NE
const textoExame = (a) => exames[a.inscricao_id] ?? a.ne?.valor ?? '';

const limpar = (texto) => (texto ?? '').trim().replace(',', '.');

// O que o professor alterou e ainda não gravou: nada se grava até carregar em «Guardar notas» (ou Enter).
const alteracoes = computed(() => {
    const notas = []; const examesNovos = []; const recursosNovos = [];
    for (const a of props.pauta?.alunos ?? []) {
        for (const i of props.pauta.instrumentos ?? []) {
            const chave = `${a.inscricao_id}-${i.id}`;
            const texto = limpar(valores[chave]);
            if (chave in valores && texto !== '' && texto !== a.notas[i.id]?.valor) {
                notas.push({ instrumento_id: i.id, inscricao_disciplina_id: a.inscricao_id, valor: texto, motivo: encerrado(i) ? motivoForaDoPrazo.value : null });
            }
        }
        const ne = limpar(exames[a.inscricao_id]);
        if (a.inscricao_id in exames && ne !== '' && ne !== a.ne?.valor) {
            examesNovos.push({ inscricao_disciplina_id: a.inscricao_id, valor: ne, motivo: props.pauta.prazos?.EXAME?.encerrado ? motivoForaDoPrazo.value : null });
        }
        const ner = limpar(recursos[a.inscricao_id]);
        if (a.inscricao_id in recursos && ner !== '' && ner !== a.ner?.valor) {
            recursosNovos.push({ inscricao_disciplina_id: a.inscricao_id, valor: ner, motivo: props.pauta.prazos?.RECURSO?.encerrado ? motivoForaDoPrazo.value : null });
        }
    }
    return { notas, exames: examesNovos, recursos: recursosNovos, total: notas.length + examesNovos.length + recursosNovos.length };
});

const pendente = (chave, original, tabela) => chave in tabela && limpar(tabela[chave]) !== '' && limpar(tabela[chave]) !== (original ?? '');
const aGuardar = ref(false);

function guardarTudo() {
    if (aGuardar.value || alteracoes.value.total === 0) return;
    const { notas, exames: ex, recursos: rec } = alteracoes.value;
    aGuardar.value = true;
    router.post('/avaliacao/notas/lote', { turma_id: turma.value, disciplina_id: disciplina.value, notas, exames: ex, recursos: rec }, {
        preserveScroll: true, preserveState: true,
        onError: aoFalhar,
        onSuccess: () => { for (const k of Object.keys(valores)) delete valores[k]; for (const k of Object.keys(exames)) delete exames[k]; for (const k of Object.keys(recursos)) delete recursos[k]; },
        onFinish: () => { aGuardar.value = false; },
    });
}

function confirmarAnulacao() {
    router.post(`/avaliacao/notas/${aAnular.value.nota_id}/anular`, { ...anulacao }, {
        preserveScroll: true,
        preserveState: true,
        onError: aoFalhar,
        onSuccess: () => { aAnular.value = null; anulacao.motivo = ''; anulacao.conta_como_zero = false; toast.success('Avaliação anulada.'); },
    });
}

function criarAvaliacao() {
    router.post('/avaliacao/notas/instrumentos', {
        turma_id: turma.value, disciplina_id: disciplina.value, periodo_id: props.pauta.periodo_id, ...novo,
    }, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => { novo.tipo = MAC; modalNova.value = false; toast.success('Avaliação criada.'); } });
}

const qual = reactive({}); // inscricao -> { nivel, obs } editados e ainda não guardados
const nivelDe = (a) => qual[a.inscricao_id]?.nivel ?? a.nivel_id ?? '';
const obsDe = (a) => qual[a.inscricao_id]?.obs ?? a.observacao ?? '';

function guardarQualitativa(a, campo, valor) {
    qual[a.inscricao_id] = { nivel: nivelDe(a), obs: obsDe(a), [campo]: valor };
    const nivel = qual[a.inscricao_id].nivel;
    if (!nivel) return;
    router.post('/avaliacao/notas/qualitativas', {
        inscricao_disciplina_id: a.inscricao_id, periodo_id: props.pauta.periodo_id, escala_nivel_id: nivel,
        observacao: qual[a.inscricao_id].obs, motivo: props.pauta.prazo?.encerrado ? motivoForaDoPrazo.value : null,
    }, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => { delete qual[a.inscricao_id]; toast.success('Avaliação guardada.'); } });
}

const abertas = reactive({}); // inscricao -> detalhe das competências aberto
const comp = reactive({}); // `${inscricao}-${competencia}` -> nível escolhido ainda não guardado
const nivelDaCompetencia = (a, c) => comp[`${a.inscricao_id}-${c.id}`] ?? a.competencias[c.id] ?? '';

function guardarCompetencia(a, c, nivelId) {
    comp[`${a.inscricao_id}-${c.id}`] = nivelId;
    router.post('/avaliacao/notas/competencias', {
        inscricao_disciplina_id: a.inscricao_id, competencia_id: c.id, periodo_id: props.pauta.periodo_id, escala_nivel_id: nivelId,
        motivo: props.pauta.prazo?.encerrado ? motivoForaDoPrazo.value : null,
    }, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => { delete comp[`${a.inscricao_id}-${c.id}`]; toast.success('Competência guardada.'); } });
}

const aplicarSugestao = (a) => guardarQualitativa(a, 'nivel', a.sugestao.nivel_id);

function eliminar(instrumento) {
    router.delete(`/avaliacao/notas/instrumentos/${instrumento.id}`, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => toast.success('Avaliação eliminada.') });
}
</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Avaliações / Notas</h1>
            <div class="text-body-secondary">Lançamento de notas e cálculo da média trimestral.</div>
        </div>

        <div class="card card-flush mb-6">
            <div class="card-body py-4">
                <div class="row g-4 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label fs-7 fw-bold text-body-secondary text-uppercase">Turma</label>
                        <SelectSolid v-model="turma" :options="opcoesTurmas" searchable placeholder="Escolha a turma" @update:model-value="disciplina = null" />
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label fs-7 fw-bold text-body-secondary text-uppercase">Disciplina</label>
                        <SelectSolid v-model="disciplina" :options="opcoesDisciplinas" :disabled="!turma" placeholder="Escolha a disciplina" @update:model-value="(v) => abrir(turma, v)" />
                    </div>
                </div>
                <div v-if="!opcoes.length" class="text-body-secondary mt-4">Não tem turmas atribuídas neste momento.</div>
            </div>
        </div>

        <div v-if="pauta?.sem_periodos" class="alert alert-warning">
            O ano lectivo desta turma ainda não tem trimestres definidos. Defina-os no ano lectivo para poder lançar notas.
        </div>

        <div v-else-if="pauta && !pauta.regra" class="alert alert-warning">
            A escola ainda não configurou a regra de avaliação desta classe. Peça a quem administra a avaliação para a definir em Configuração da avaliação.
        </div>

        <div v-else-if="pauta && pauta.tipo === 'qualitativa'" class="card card-flush">
            <div class="card-header pt-5">
                <div class="card-title flex-column align-items-start">
                    <h3 class="fw-bold mb-1">{{ pauta.disciplina_nome }}</h3>
                    <div class="text-body-secondary fs-7">{{ pauta.turma_rotulo }} · escala «{{ pauta.escala.nome }}»</div>
                </div>
                <div class="card-toolbar gap-3">
                    <span class="btn-group">
                        <button v-for="p in pauta.periodos" :key="p.id" type="button" class="btn btn-sm"
                            :class="p.id === pauta.periodo_id ? 'btn-primary' : 'btn-light'" @click="abrir(turma, disciplina, p.id)">{{ p.nome }}</button>
                    </span>
                    <a :href="`/avaliacao/notas/exportar?turma=${turma}&disciplina=${disciplina}&formato=pdf`" class="btn btn-sm btn-light">PDF</a>
                    <a :href="`/avaliacao/notas/exportar?turma=${turma}&disciplina=${disciplina}&formato=excel`" class="btn btn-sm btn-light">Excel</a>
                    <a v-if="pauta.competencias.length" :href="`/avaliacao/notas/exportar?turma=${turma}&disciplina=${disciplina}&periodo=${pauta.periodo_id}&detalhe=competencias&formato=pdf`" class="btn btn-sm btn-light">Competências (PDF)</a>
                </div>
            </div>
            <div class="card-body pt-3">
                <div v-if="pauta.prazo" class="fs-7 text-body-secondary mb-4">Prazo de lançamento: <strong :class="pauta.prazo.encerrado ? 'text-danger' : ''">{{ pauta.prazo.data_limite }}</strong></div>
                <div v-if="pauta.prazo?.encerrado" class="alert alert-warning py-3 d-flex flex-wrap align-items-center gap-3">
                    <span v-if="pode.fora_prazo">O prazo terminou. Para lançar fora do prazo, indique o motivo:</span>
                    <span v-else>O prazo terminou. Já não é possível lançar notas.</span>
                    <input v-if="pode.fora_prazo" v-model="motivoForaDoPrazo" type="text" class="form-control form-control-sm w-300px" placeholder="Motivo do lançamento fora do prazo">
                </div>
                <div v-if="!pauta.alunos.length" class="text-center text-body-secondary py-6">Nenhuma criança inscrita nesta área.</div>
                <div v-else class="table-responsive tabela-com-selects">
                    <table class="table align-middle table-row-dashed fs-6 gy-3 mb-0">
                        <thead>
                            <tr class="text-start text-body-secondary fw-bold fs-7 text-uppercase"><th style="width: 3rem">#</th><th>Criança</th><th style="width: 16rem">Avaliação</th><th>Observação</th><th v-if="pauta.competencias.length" class="text-center" style="width: 7rem">Competências</th></tr>
                        </thead>
                        <tbody>
                            <template v-for="(a, n) in pauta.alunos" :key="a.inscricao_id">
                                <tr>
                                    <td class="text-body-secondary">{{ n + 1 }}</td>
                                    <td class="fw-semibold">{{ a.nome }}</td>
                                    <td>
                                        <SelectSolid :model-value="nivelDe(a)" :options="opcoesNiveis" pequeno placeholder="—" @update:model-value="(v) => guardarQualitativa(a, 'nivel', Number(v))" />
                                        <div v-if="a.sugestao && a.sugestao.nivel_id !== nivelDe(a)" class="fs-8 text-body-secondary mt-1">
                                            Sugestão: <strong>{{ a.sugestao.designacao }}</strong> ({{ a.sugestao.avaliadas }} de {{ a.sugestao.total }} competências)
                                            <a href="#" class="ms-1" @click.prevent="aplicarSugestao(a)">Aplicar</a>
                                        </div>
                                    </td>
                                    <td><input :value="obsDe(a)" type="text" class="form-control form-control-sm" placeholder="Observação do educador (opcional)" :disabled="!nivelDe(a)"
                                        @blur="$event.target.value !== (a.observacao ?? '') && guardarQualitativa(a, 'obs', $event.target.value)"></td>
                                    <td v-if="pauta.competencias.length" class="text-center">
                                        <a href="#" class="fs-7" @click.prevent="abertas[a.inscricao_id] = !abertas[a.inscricao_id]">{{ Object.keys(a.competencias).length }}/{{ pauta.competencias.length }} {{ abertas[a.inscricao_id] ? '▴' : '▾' }}</a>
                                    </td>
                                </tr>
                                <tr v-if="abertas[a.inscricao_id]">
                                    <td></td>
                                    <td :colspan="pauta.competencias.length ? 4 : 3" class="bg-body-secondary">
                                        <div v-for="c in pauta.competencias" :key="c.id" class="d-flex flex-wrap align-items-center gap-3 py-1">
                                            <span class="flex-grow-1 fs-7">{{ c.descricao }}</span>
                                            <SelectSolid :model-value="nivelDaCompetencia(a, c)" :options="opcoesNiveis" pequeno placeholder="—" class="w-200px" @update:model-value="(v) => guardarCompetencia(a, c, Number(v))" />
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-else-if="pauta" class="card card-flush">
            <div class="card-header pt-5">
                <div class="card-title flex-column align-items-start">
                    <h3 class="fw-bold mb-1">{{ pauta.disciplina_nome }}</h3>
                    <div class="text-body-secondary fs-7">{{ pauta.turma_rotulo }} · escala 0–{{ pauta.regra.escala_maxima }} · mínimo {{ pauta.regra.nota_minima }}</div>
                </div>
                <div class="card-toolbar gap-3">
                    <span class="btn-group">
                        <button v-for="p in pauta.periodos" :key="p.id" type="button" class="btn btn-sm"
                            :class="p.id === pauta.periodo_id ? 'btn-primary' : 'btn-light'" @click="abrir(turma, disciplina, p.id)">{{ p.nome }}</button>
                    </span>
                    <a :href="`/avaliacao/notas/exportar?turma=${turma}&disciplina=${disciplina}&formato=pdf`" class="btn btn-sm btn-light">PDF</a>
                    <a :href="`/avaliacao/notas/exportar?turma=${turma}&disciplina=${disciplina}&formato=excel`" class="btn btn-sm btn-light">Excel</a>
                </div>
            </div>

            <div class="card-body pt-3">
                <div class="d-flex flex-wrap gap-4 fs-7 text-body-secondary mb-4">
                    <span>Prazo das avaliações contínuas: <strong :class="pauta.prazos.MAC.encerrado ? 'text-danger' : ''">{{ pauta.prazos.MAC.data_limite }}</strong></span>
                    <span>Prazo da prova: <strong :class="pauta.prazos.PT.encerrado ? 'text-danger' : ''">{{ pauta.prazos.PT.data_limite }}</strong></span>
                    <span v-if="pauta.recurso">Só faz recurso quem tem a nota final abaixo do mínimo · prazo do recurso: <strong :class="pauta.prazos.RECURSO.encerrado ? 'text-danger' : ''">{{ pauta.prazos.RECURSO.data_limite }}</strong> · MFR = MF × {{ pauta.recurso.peso_mf }}% + NER × {{ pauta.recurso.peso_recurso }}%</span>
                    <span v-if="pauta.exame">Prazo do exame: <strong :class="pauta.prazos.EXAME.encerrado ? 'text-danger' : ''">{{ pauta.prazos.EXAME.data_limite }}</strong> · MF = MFD × {{ pauta.exame.peso_mfd }}% + NE × {{ pauta.exame.peso_exame }}%</span>
                </div>

                <div v-if="negativasTotal" class="d-flex align-items-center gap-2 fw-semibold text-danger mb-4">
                    <i class="ki-duotone ki-information-5 fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                    <span>{{ negativasTotal }} aluno(s) com negativa nesta disciplina<template v-if="pauta.regra.trata_negativas"> · {{ negativasPorTratar }} por tratar</template><template v-else> · {{ pauta.regra.consequencia_negativa }}</template></span>
                </div>

                <div v-if="algumEncerrado" class="alert alert-warning py-3 d-flex flex-wrap align-items-center gap-3">
                    <span v-if="pode.fora_prazo">O prazo terminou para parte das avaliações. Para lançar fora do prazo, indique o motivo:</span>
                    <span v-else>O prazo terminou para parte das avaliações. Nessas já não é possível lançar notas.</span>
                    <input v-if="pode.fora_prazo" v-model="motivoForaDoPrazo" type="text" class="form-control form-control-sm w-300px" placeholder="Motivo do lançamento fora do prazo">
                </div>

                <div v-if="!pauta.instrumentos.length" class="text-body-secondary py-6">Ainda não há avaliações neste trimestre. Crie a primeira em «Nova avaliação».</div>

                <div v-if="pode.criar" class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                    <div class="d-flex flex-wrap align-items-center gap-3 fs-7">
                        <span class="badge" :class="macsCriadas < minAvaliacoes ? 'badge-light-warning' : 'badge-light-success'"
                            :title="`A escola pede entre ${minAvaliacoes} e ${maxAvaliacoes} avaliações contínuas por trimestre`">
                            Contínuas {{ macsCriadas }}/{{ maxAvaliacoes }}
                        </span>
                        <span v-if="macsCriadas < minAvaliacoes" class="text-warning">Faltam {{ minAvaliacoes - macsCriadas }} para o mínimo ({{ minAvaliacoes }}).</span>
                        <span v-if="pauta.instrumentos.length" :class="alteracoes.total ? 'text-warning fw-bold' : 'text-body-secondary'">
                            {{ alteracoes.total ? `${alteracoes.total} nota(s) por guardar` : 'Escreva as notas e carregue em «Guardar notas» (ou Enter).' }}
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-light-primary" :disabled="!tiposPossiveis.length"
                            :title="tiposPossiveis.length ? '' : 'Não há mais avaliações a criar neste trimestre.'" @click="modalNova = true">+ Nova avaliação</button>
                        <button v-if="pauta.instrumentos.length" type="button" class="btn btn-sm btn-primary" :disabled="!alteracoes.total || aGuardar" @click="guardarTudo">
                            {{ aGuardar ? 'A guardar…' : 'Guardar notas' }}
                        </button>
                    </div>
                </div>

                <div v-if="pauta.instrumentos.length" class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-3 mb-0">
                        <thead>
                            <tr class="text-start text-body-secondary fw-bold fs-7 text-uppercase">
                                <th style="width: 3rem">#</th>
                                <th>Aluno</th>
                                <th v-for="i in pauta.instrumentos" :key="i.id" class="text-center">
                                    <span :title="i.tipo_descricao">{{ i.sigla }}</span>
                                    <div class="fw-normal text-nowrap">{{ i.obrigatorio ? '' : 'facultativa' }}
                                        <a v-if="pode.criar" href="#" class="text-danger ms-1" title="Eliminar avaliação" @click.prevent="eliminar(i)">×</a>
                                    </div>
                                </th>
                                <th class="text-center">MAC</th>
                                <th class="text-center">{{ pauta.rotulo_pt }}</th>
                                <th class="text-center">MT</th>
                                <th class="text-center">MFD</th>
                                <template v-if="pauta.exame"><th class="text-center">Exame (NE)</th><th class="text-center">MF</th></template>
                                <template v-if="pauta.recurso"><th class="text-center">Recurso (NER)</th><th class="text-center">MFR</th></template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, n) in pauta.alunos" :key="a.inscricao_id">
                                <td class="text-body-secondary">{{ n + 1 }}</td>
                                <td class="fw-semibold">{{ a.nome }}</td>
                                <td v-for="i in pauta.instrumentos" :key="i.id" class="text-center" style="min-width: 7rem">
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <input :value="textoDaCelula(a, i)" type="text" inputmode="decimal" class="form-control form-control-sm text-center w-70px"
                                            :class="[a.notas[i.id]?.estado === ESTADO_ANULADA ? 'text-body-secondary' : '', pendente(`${a.inscricao_id}-${i.id}`, a.notas[i.id]?.valor, valores) ? 'border-warning bg-light-warning' : '']" @input="valores[`${a.inscricao_id}-${i.id}`] = $event.target.value" @keyup.enter="guardarTudo">
                                        <a v-if="pode.anular && a.notas[i.id] && a.notas[i.id].estado !== 1 && a.notas[i.id].estado !== 2" href="#" class="text-body-secondary" title="Anular"
                                            @click.prevent="aAnular = { nota_id: a.notas[i.id].id, aluno: a.nome, instrumento: i.nome }">⊘</a>
                                    </div>
                                    <div v-if="a.notas[i.id] && a.notas[i.id].estado !== 0" class="fs-8 text-body-secondary">{{ a.notas[i.id].estado_descricao }}</div>
                                </td>
                                <td class="text-center" :class="negativaDe(a.mac) ? 'text-danger' : ''">{{ a.mac ?? '—' }}</td>
                                <td class="text-center" :class="negativaDe(a.pt) ? 'text-danger' : ''">{{ a.pt ?? '—' }}</td>
                                <td class="text-center fw-bold" :class="negativaDe(a.mt) || a.negativa ? 'text-danger' : ''">{{ a.mt ?? '—' }}<template v-if="a.negativa">
                                        <template v-if="pauta.regra.trata_negativas">
                                            <component :is="pode.tratar_negativa ? 'a' : 'span'" v-if="a.medida_negativa" href="#" class="badge badge-light-success ms-2" :title="a.medida_negativa" @click.prevent="pode.tratar_negativa && tratar(a)">Tratada</component>
                                            <component :is="pode.tratar_negativa ? 'a' : 'span'" v-else href="#" class="badge badge-light-danger ms-2" :title="pauta.regra.consequencia_negativa" @click.prevent="pode.tratar_negativa && tratar(a)">{{ pode.tratar_negativa ? 'Tratar negativa' : 'Por tratar' }}</component>
                                        </template>
                                        <span v-else class="badge badge-light-danger ms-2" :title="pauta.regra.consequencia_negativa">Negativa</span>
                                    </template>
                                    <span v-else-if="a.mt_negativa" class="badge badge-light-warning ms-2" title="Trimestre abaixo do mínimo. A média final da disciplina ainda passa.">Negativa no trimestre</span><span v-if="a.pendente" class="badge badge-light-warning ms-2">pendente</span></td>
                                <td class="text-center fw-bold" :class="negativaDe(a.mfd) ? 'text-danger' : ''">{{ a.mfd ?? '—' }}</td>
                                <template v-if="pauta.exame">
                                    <td class="text-center" style="min-width: 7rem">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <input :value="textoExame(a)" type="text" inputmode="decimal" class="form-control form-control-sm text-center w-70px"
                                                :class="pendente(a.inscricao_id, a.ne?.valor, exames) ? 'border-warning bg-light-warning' : ''" @input="exames[a.inscricao_id] = $event.target.value" @keyup.enter="guardarTudo">
                                            <a v-if="pode.anular && a.ne && a.ne.estado === 0" href="#" class="text-body-secondary" title="Anular"
                                                @click.prevent="aAnular = { nota_id: a.ne.id, aluno: a.nome, instrumento: 'Exame' }">⊘</a>
                                        </div>
                                        <div v-if="a.ne && a.ne.estado !== 0" class="fs-8 text-body-secondary">{{ a.ne.estado_descricao }}</div>
                                    </td>
                                    <td class="text-center fw-bold" :class="negativaDe(a.mf) ? 'text-danger' : ''">{{ a.mf ?? '—' }}</td>
                                </template>
                                <template v-if="pauta.recurso">
                                    <td class="text-center" style="min-width: 7rem">
                                        <div v-if="a.recurso_necessario" class="d-flex align-items-center justify-content-center gap-1">
                                            <input :value="textoRecurso(a)" type="text" inputmode="decimal" class="form-control form-control-sm text-center w-70px" :disabled="!a.recurso_pode_lancar"
                                                :title="a.recurso_pode_lancar ? '' : (pauta.recurso.aberto ? 'Este aluno não foi ao recurso' : 'O recurso abre depois de aprovada a pauta')"
                                                :class="pendente(a.inscricao_id, a.ner?.valor, recursos) ? 'border-warning bg-light-warning' : ''" @input="recursos[a.inscricao_id] = $event.target.value" @keyup.enter="guardarTudo">
                                            <a v-if="pode.anular && a.ner && a.ner.estado === 0" href="#" class="text-body-secondary" title="Anular"
                                                @click.prevent="aAnular = { nota_id: a.ner.id, aluno: a.nome, instrumento: 'Exame de recurso' }">⊘</a>
                                        </div>
                                        <span v-else class="text-body-secondary" :title="a.recurso_motivo">—</span>
                                        <div v-if="a.recurso_motivo" class="fs-8 text-danger" :title="a.recurso_motivo">Sem direito a recurso</div>
                                        <div v-if="a.ner && a.ner.estado !== 0" class="fs-8 text-body-secondary">{{ a.ner.estado_descricao }}</div>
                                    </td>
                                    <td class="text-center fw-bold" :class="negativaDe(a.mfr) ? 'text-danger' : ''">{{ a.mfr ?? '—' }}</td>
                                </template>
                            </tr>
                            <tr v-if="!pauta.alunos.length"><td :colspan="pauta.instrumentos.length + (pauta.exame ? 8 : 6) + (pauta.recurso ? 2 : 0)" class="text-center text-body-secondary py-6">Nenhum aluno inscrito nesta disciplina.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <TratarNegativaModal :alvo="aTratar" @fechar="aTratar = null" />

        <div v-if="aAnular" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="aAnular = null">
            <div class="modal-dialog modal-dialog-centered" @keydown.esc="aAnular = null">
                <form class="modal-content" @submit.prevent="anulacao.motivo.trim() && confirmarAnulacao()">
                    <div class="modal-header">
                        <h3 class="modal-title fw-bold">Anular «{{ aAnular.instrumento }}» de {{ aAnular.aluno }}</h3>
                        <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" aria-label="Fechar" @click="aAnular = null">✕</button>
                    </div>
                    <div class="modal-body">
                        <input v-model="anulacao.motivo" type="text" class="form-control mb-4" placeholder="Motivo da anulação" autofocus>
                        <label class="form-check form-check-sm form-check-custom form-check-solid">
                            <input v-model="anulacao.conta_como_zero" type="checkbox" class="form-check-input">
                            <span class="form-check-label">Conta como zero</span>
                        </label>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="aAnular = null">Cancelar</button>
                        <button type="submit" class="btn btn-danger" :disabled="!anulacao.motivo.trim()">Anular</button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="modalNova" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="modalNova = false">
            <div class="modal-dialog modal-dialog-centered" @keydown.esc="modalNova = false">
                <form class="modal-content" @submit.prevent="criarAvaliacao">
                    <div class="modal-header">
                        <h3 class="modal-title fw-bold">Nova avaliação</h3>
                        <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" aria-label="Fechar" @click="modalNova = false">✕</button>
                    </div>
                    <div class="modal-body">
                        <label class="fw-semibold fs-7 mb-1">Tipo</label>
                        <SelectSolid v-model="novo.tipo" :options="opcoesTipos" />
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="modalNova = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Adicionar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Os selects estão dentro da tabela: o contentor com scroll horizontal cortava a lista de opções (com poucas linhas parecia que o select não abria). */
@media (min-width: 768px) {
    .tabela-com-selects { overflow: visible !important; }
}
/* Em ecrãs estreitos o scroll horizontal tem de ficar: dá-se altura para a lista de opções caber. */
@media (max-width: 767.98px) {
    .tabela-com-selects { min-height: 18rem; }
}
</style>
