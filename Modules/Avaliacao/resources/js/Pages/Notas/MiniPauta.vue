<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    opcoes: { type: Array, required: true }, // [{ turma_id, turma_rotulo, disciplinas: [{ disciplina_id, disciplina_nome }] }]
    selecao: { type: Object, required: true }, // { turma, disciplina }
    pauta: { type: Object, default: null },
    tipos: { type: Array, required: true }, // [{ valor, nome }]
    pode: { type: Object, required: true }, // { anular, criar }
});
defineOptions({ layout: AppLayout });

const MAC = 0;
const ESTADO_ANULADA = 1;

const turma = ref(props.selecao.turma);
const disciplina = ref(props.selecao.disciplina);
const disciplinasDaTurma = computed(() => props.opcoes.find((o) => o.turma_id === turma.value)?.disciplinas ?? []);

const valores = reactive({}); // `${inscricao}-${instrumento}` -> texto digitado
const motivoForaDoPrazo = ref('');
const aAnular = ref(null); // { nota_id, aluno, instrumento }
const anulacao = reactive({ motivo: '', conta_como_zero: false });
const novo = reactive({ nome: '', tipo: MAC, obrigatorio: true });

const aoFalhar = (e) => toast.error(Object.values(e)[0]);
const tipoDoInstrumento = (i) => (i.tipo === MAC ? 'MAC' : 'PT');
const encerrado = (i) => props.pauta?.prazos?.[tipoDoInstrumento(i)]?.encerrado ?? false;
const algumEncerrado = computed(() => (props.pauta?.instrumentos ?? []).some(encerrado) || (props.pauta?.exame && props.pauta.prazos.EXAME.encerrado));

function abrir(turmaId, disciplinaId, periodoId = null) {
    if (!turmaId || !disciplinaId) return;
    router.get('/avaliacao/notas', { turma: turmaId, disciplina: disciplinaId, periodo: periodoId ?? undefined }, { preserveState: true });
}

function textoDaCelula(aluno, instrumento) {
    const chave = `${aluno.inscricao_id}-${instrumento.id}`;
    if (chave in valores) return valores[chave];
    const nota = aluno.notas[instrumento.id];
    return nota?.valor ?? '';
}

const exames = reactive({}); // inscricao -> texto da NE
const textoExame = (a) => exames[a.inscricao_id] ?? a.ne?.valor ?? '';

function guardarExame(a) {
    const texto = (exames[a.inscricao_id] ?? '').trim().replace(',', '.');
    if (!(a.inscricao_id in exames) || texto === '' || texto === a.ne?.valor) return;
    router.post('/avaliacao/notas/exame', {
        turma_id: turma.value, disciplina_id: disciplina.value, inscricao_disciplina_id: a.inscricao_id, valor: texto,
        motivo: props.pauta.prazos.EXAME.encerrado ? motivoForaDoPrazo.value : null,
    }, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => { delete exames[a.inscricao_id]; toast.success('Nota do exame guardada.'); } });
}

function guardar(aluno, instrumento) {
    const chave = `${aluno.inscricao_id}-${instrumento.id}`;
    const texto = (valores[chave] ?? '').trim().replace(',', '.');
    const actual = aluno.notas[instrumento.id]?.valor;
    if (!(chave in valores) || texto === '' || texto === actual) return;

    router.post('/avaliacao/notas', {
        instrumento_id: instrumento.id,
        inscricao_disciplina_id: aluno.inscricao_id,
        valor: texto,
        motivo: encerrado(instrumento) ? motivoForaDoPrazo.value : null,
    }, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => { delete valores[chave]; toast.success('Nota guardada.'); } });
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
    }, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => { novo.nome = ''; toast.success('Avaliação criada.'); } });
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

function eliminar(instrumento) {
    router.delete(`/avaliacao/notas/instrumentos/${instrumento.id}`, { preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => toast.success('Avaliação eliminada.') });
}
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Avaliações / Notas</h1>
            <div class="text-muted">Lançamento de notas e cálculo da média trimestral.</div>
        </div>

        <div class="card card-flush mb-6">
            <div class="card-body py-4">
                <div class="row g-4 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label fs-7 fw-bold text-muted text-uppercase">Turma</label>
                        <select v-model="turma" class="form-select form-select-sm" @change="disciplina = null">
                            <option :value="null" disabled>Escolha a turma</option>
                            <option v-for="o in opcoes" :key="o.turma_id" :value="o.turma_id">{{ o.turma_rotulo }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label fs-7 fw-bold text-muted text-uppercase">Disciplina</label>
                        <select v-model="disciplina" class="form-select form-select-sm" :disabled="!turma" @change="abrir(turma, disciplina)">
                            <option :value="null" disabled>Escolha a disciplina</option>
                            <option v-for="d in disciplinasDaTurma" :key="d.disciplina_id" :value="d.disciplina_id">{{ d.disciplina_nome }}</option>
                        </select>
                    </div>
                </div>
                <div v-if="!opcoes.length" class="text-muted mt-4">Não tem turmas atribuídas neste momento.</div>
            </div>
        </div>

        <div v-if="pauta && !pauta.regra" class="alert alert-warning">
            A escola ainda não configurou a regra de avaliação desta classe. Peça a quem administra a avaliação para a definir em Configuração da avaliação.
        </div>

        <div v-else-if="pauta && pauta.tipo === 'qualitativa'" class="card card-flush">
            <div class="card-header pt-5">
                <div class="card-title flex-column align-items-start">
                    <h3 class="fw-bold mb-1">{{ pauta.disciplina_nome }}</h3>
                    <div class="text-muted fs-7">{{ pauta.turma_rotulo }} · escala «{{ pauta.escala.nome }}»</div>
                </div>
                <div class="card-toolbar btn-group">
                    <button v-for="p in pauta.periodos" :key="p.id" type="button" class="btn btn-sm"
                        :class="p.id === pauta.periodo_id ? 'btn-primary' : 'btn-light'" @click="abrir(turma, disciplina, p.id)">{{ p.nome }}</button>
                </div>
            </div>
            <div class="card-body pt-3">
                <div v-if="pauta.prazo" class="fs-7 text-muted mb-4">Prazo de lançamento: <strong :class="pauta.prazo.encerrado ? 'text-danger' : ''">{{ pauta.prazo.data_limite }}</strong></div>
                <div v-if="pauta.prazo?.encerrado" class="alert alert-warning py-3 d-flex flex-wrap align-items-center gap-3">
                    <span>O prazo terminou. Só quem tem permissão pode lançar, e tem de indicar o motivo:</span>
                    <input v-model="motivoForaDoPrazo" type="text" class="form-control form-control-sm w-300px" placeholder="Motivo do lançamento fora do prazo">
                </div>
                <div v-if="!pauta.alunos.length" class="text-center text-muted py-6">Nenhuma criança inscrita nesta área.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-3 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase"><th style="width: 3rem">#</th><th>Criança</th><th style="width: 16rem">Avaliação</th><th>Observação</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, n) in pauta.alunos" :key="a.inscricao_id">
                                <td class="text-muted">{{ n + 1 }}</td>
                                <td class="fw-semibold">{{ a.nome }}</td>
                                <td>
                                    <select :value="nivelDe(a)" class="form-select form-select-sm" @change="guardarQualitativa(a, 'nivel', Number($event.target.value))">
                                        <option value="" disabled>—</option>
                                        <option v-for="nv in pauta.escala.niveis" :key="nv.id" :value="nv.id">{{ nv.designacao }}</option>
                                    </select>
                                </td>
                                <td><input :value="obsDe(a)" type="text" class="form-control form-control-sm" placeholder="Observação do educador (opcional)" :disabled="!nivelDe(a)"
                                    @blur="$event.target.value !== (a.observacao ?? '') && guardarQualitativa(a, 'obs', $event.target.value)"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-else-if="pauta" class="card card-flush">
            <div class="card-header pt-5">
                <div class="card-title flex-column align-items-start">
                    <h3 class="fw-bold mb-1">{{ pauta.disciplina_nome }}</h3>
                    <div class="text-muted fs-7">{{ pauta.turma_rotulo }} · escala 0–{{ pauta.regra.escala_maxima }} · mínimo {{ pauta.regra.nota_minima }}</div>
                </div>
                <div class="card-toolbar btn-group">
                    <button v-for="p in pauta.periodos" :key="p.id" type="button" class="btn btn-sm"
                        :class="p.id === pauta.periodo_id ? 'btn-primary' : 'btn-light'" @click="abrir(turma, disciplina, p.id)">{{ p.nome }}</button>
                </div>
            </div>

            <div class="card-body pt-3">
                <div class="d-flex flex-wrap gap-4 fs-7 text-muted mb-4">
                    <span>Prazo das avaliações contínuas: <strong :class="pauta.prazos.MAC.encerrado ? 'text-danger' : ''">{{ pauta.prazos.MAC.data_limite }}</strong></span>
                    <span>Prazo da prova: <strong :class="pauta.prazos.PT.encerrado ? 'text-danger' : ''">{{ pauta.prazos.PT.data_limite }}</strong></span>
                    <span v-if="pauta.exame">Prazo do exame: <strong :class="pauta.prazos.EXAME.encerrado ? 'text-danger' : ''">{{ pauta.prazos.EXAME.data_limite }}</strong> · MF = MFD × {{ pauta.exame.peso_mfd }}% + NE × {{ pauta.exame.peso_exame }}%</span>
                </div>

                <div v-if="algumEncerrado" class="alert alert-warning py-3 d-flex flex-wrap align-items-center gap-3">
                    <span>O prazo terminou para parte das avaliações. Só quem tem permissão pode lançar, e tem de indicar o motivo:</span>
                    <input v-model="motivoForaDoPrazo" type="text" class="form-control form-control-sm w-300px" placeholder="Motivo do lançamento fora do prazo">
                </div>

                <div v-if="!pauta.instrumentos.length" class="text-muted py-6">Ainda não há avaliações neste trimestre. Crie a primeira abaixo.</div>

                <div v-if="pauta.instrumentos.length" class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-3 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase">
                                <th style="width: 3rem">#</th>
                                <th>Aluno</th>
                                <th v-for="i in pauta.instrumentos" :key="i.id" class="text-center">
                                    {{ i.nome }}
                                    <div class="fw-normal text-nowrap">{{ i.tipo_descricao }}{{ i.obrigatorio ? '' : ' · facultativa' }}
                                        <a v-if="pode.criar" href="#" class="text-danger ms-1" title="Eliminar avaliação" @click.prevent="eliminar(i)">×</a>
                                    </div>
                                </th>
                                <th class="text-center">MAC</th>
                                <th class="text-center">PT</th>
                                <th class="text-center">MT</th>
                                <th class="text-center">MFD</th>
                                <template v-if="pauta.exame"><th class="text-center">Exame (NE)</th><th class="text-center">MF</th></template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, n) in pauta.alunos" :key="a.inscricao_id">
                                <td class="text-muted">{{ n + 1 }}</td>
                                <td class="fw-semibold">{{ a.nome }}</td>
                                <td v-for="i in pauta.instrumentos" :key="i.id" class="text-center" style="min-width: 7rem">
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <input :value="textoDaCelula(a, i)" type="text" inputmode="decimal" class="form-control form-control-sm text-center w-70px"
                                            :class="a.notas[i.id]?.estado === ESTADO_ANULADA ? 'text-muted' : ''" @input="valores[`${a.inscricao_id}-${i.id}`] = $event.target.value"
                                            @blur="guardar(a, i)" @keyup.enter="$event.target.blur()">
                                        <a v-if="pode.anular && a.notas[i.id] && a.notas[i.id].estado !== 1 && a.notas[i.id].estado !== 2" href="#" class="text-muted" title="Anular"
                                            @click.prevent="aAnular = { nota_id: a.notas[i.id].id, aluno: a.nome, instrumento: i.nome }">⊘</a>
                                    </div>
                                    <div v-if="a.notas[i.id] && a.notas[i.id].estado !== 0" class="fs-8 text-muted">{{ a.notas[i.id].estado_descricao }}</div>
                                </td>
                                <td class="text-center">{{ a.mac ?? '—' }}</td>
                                <td class="text-center">{{ a.pt ?? '—' }}</td>
                                <td class="text-center fw-bold">{{ a.mt ?? '—' }}<span v-if="a.pendente" class="badge badge-light-warning ms-2">pendente</span></td>
                                <td class="text-center">{{ a.mfd ?? '—' }}</td>
                                <template v-if="pauta.exame">
                                    <td class="text-center" style="min-width: 7rem">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <input :value="textoExame(a)" type="text" inputmode="decimal" class="form-control form-control-sm text-center w-70px"
                                                @input="exames[a.inscricao_id] = $event.target.value" @blur="guardarExame(a)" @keyup.enter="$event.target.blur()">
                                            <a v-if="pode.anular && a.ne && a.ne.estado === 0" href="#" class="text-muted" title="Anular"
                                                @click.prevent="aAnular = { nota_id: a.ne.id, aluno: a.nome, instrumento: 'Exame' }">⊘</a>
                                        </div>
                                        <div v-if="a.ne && a.ne.estado !== 0" class="fs-8 text-muted">{{ a.ne.estado_descricao }}</div>
                                    </td>
                                    <td class="text-center fw-bold">{{ a.mf ?? '—' }}</td>
                                </template>
                            </tr>
                            <tr v-if="!pauta.alunos.length"><td :colspan="pauta.instrumentos.length + (pauta.exame ? 8 : 6)" class="text-center text-muted py-6">Nenhum aluno inscrito nesta disciplina.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="aAnular" class="border rounded p-4 mt-6 bg-body-secondary">
                    <div class="fw-bold mb-2">Anular «{{ aAnular.instrumento }}» de {{ aAnular.aluno }}</div>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <input v-model="anulacao.motivo" type="text" class="form-control form-control-sm w-300px" placeholder="Motivo da anulação">
                        <label class="form-check form-check-sm form-check-custom form-check-solid">
                            <input v-model="anulacao.conta_como_zero" type="checkbox" class="form-check-input">
                            <span class="form-check-label">Conta como zero</span>
                        </label>
                        <button type="button" class="btn btn-sm btn-danger" :disabled="!anulacao.motivo.trim()" @click="confirmarAnulacao">Anular</button>
                        <button type="button" class="btn btn-sm btn-light" @click="aAnular = null">Cancelar</button>
                    </div>
                </div>

                <div v-if="pode.criar" class="border-top mt-6 pt-5">
                    <div class="fw-bold mb-3">Nova avaliação neste trimestre</div>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <input v-model="novo.nome" type="text" class="form-control form-control-sm w-250px" placeholder="Nome (ex.: Avaliação 1)">
                        <select v-model="novo.tipo" class="form-select form-select-sm w-200px">
                            <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.nome }}</option>
                        </select>
                        <label class="form-check form-check-sm form-check-custom form-check-solid">
                            <input v-model="novo.obrigatorio" type="checkbox" class="form-check-input">
                            <span class="form-check-label">Obrigatória</span>
                        </label>
                        <button type="button" class="btn btn-sm btn-primary" :disabled="!novo.nome.trim()" @click="criarAvaliacao">Adicionar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
