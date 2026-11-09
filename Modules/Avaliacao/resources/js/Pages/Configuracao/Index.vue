<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import RegraModal from '../../Components/Configuracao/RegraModal.vue';

const props = defineProps({
    anos: { type: Array, required: true }, // [{ id, nome }]
    ano_id: { type: Number, default: null },
    regras: { type: Array, required: true }, // numéricas e qualitativas, cada uma com as classes ligadas (nivel_ids)
    niveis: { type: Array, required: true }, // [{ id, codigo, nome, qualitativo, sem_regra }]
    definicoes: { type: Object, default: () => ({}) }, // { max_avaliacoes, min_avaliacoes }
    periodos: { type: Array, required: true }, // [{ id, nome, numero, data_fim }]
    prazos: { type: Array, default: () => [] }, // os prazos da escola: [{ id, periodo_id, tipo, data_limite, excepcoes }]
    tipos_de_prazo: { type: Array, default: () => [] }, // os tipos de prazo que a escola usa
    turmas: { type: Array, default: () => [] }, // as turmas do ano: [{ id, rotulo }]
    modos: { type: Array, required: true }, // [{ valor, nome }]
    tipos: { type: Array, required: true }, // [{ valor, nome }]
    progressoes: { type: Array, default: () => [] }, // [{ valor, nome }]
    escalas: { type: Array, default: () => [] }, // [{ id, nome, niveis: [{ id, codigo, designacao }] }]
    competencias: { type: Array, default: () => [] }, // [{ id, disciplina_id, nivel_academico_id, descricao, ativa }]
    disciplinas: { type: Array, default: () => [] }, // [{ id, nome }]
    documentos: { type: Object, default: () => ({}) }, // { linhas_extra, lema, visto_cargo, visto_nome }
    cabecalho_linhas: { type: Array, default: () => [] }, // o cabeçalho tal como sairá nos documentos
});
defineOptions({ layout: AppLayout });

const datas = reactive({}); // `${periodo}-${tipo}` -> data
const prolongamentos = reactive({}); // prazo_id -> { data_limite, motivo, turma_id }
const aba = ref('regras');

const aoFalhar = (e) => toast.error(Object.values(e)[0]);
const aoGuardar = (texto) => ({ preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => toast.success(texto) });

const chave = (r) => `${r.qualitativa ? 'q' : 'n'}${r.id}`;
const semRegra = computed(() => props.niveis.filter((n) => n.sem_regra && n.tem_regra_padrao));
const semAvaliacao = computed(() => props.niveis.filter((n) => n.sem_regra && !n.tem_regra_padrao)); // classes que ainda não têm avaliação disponível
const qualitativas = computed(() => props.niveis.filter((n) => n.qualitativo));
const nomeDaClasse = (id) => props.niveis.find((n) => n.id === id)?.nome ?? id;
// A sigla da classe no círculo: «3.ª Classe» → «3.ª»; «Creche II» → «C2»; «Pré-Escolar I» → «P1»; «Iniciação» → «In».
const sigla = (id) => {
    const nome = String(nomeDaClasse(id));
    const numero = nome.match(/^(\d+\.ª)/);
    if (numero) return numero[1];
    const romano = nome.match(/^(\S{1})\S*\s+(I{1,3})$/u);
    if (romano) return romano[1].toUpperCase() + romano[2].length;
    return nome.slice(0, 2);
};
const nomeDaEscala = (id) => props.escalas.find((e) => e.id === id)?.nome;
const nomeDaProgressao = (v) => props.progressoes.find((p) => p.valor === v)?.nome ?? '';
const abas = computed(() => [
    { id: 'regras', nome: 'Regras', alerta: semRegra.value.length > 0, total: props.regras.length },
    { id: 'prazos', nome: 'Prazos' },
    { id: 'limites', nome: 'Avaliações por trimestre' },
    { id: 'documentos', nome: 'Documentos' },
    ...(qualitativas.value.length || props.regras.some((r) => r.qualitativa) ? [{ id: 'qualitativo', nome: 'Escalas e competências' }] : []),
]);

// ---- a janela de criar/editar uma regra
const modal = reactive({ regra: null, nova: null, processando: false, erros: {} });
const abrirEditar = (r) => { modal.erros = {}; modal.regra = r; modal.nova = null; };
const abrirNova = (qualitativa) => { modal.erros = {}; modal.regra = null; modal.nova = { qualitativa }; };
const fecharModal = () => { modal.regra = null; modal.nova = null; modal.erros = {}; };

function guardarRegra(f) {
    const r = modal.regra;
    const qualitativa = r ? r.qualitativa : modal.nova.qualitativa;
    const dados = qualitativa
        ? { regra_id: r?.id ?? null, nova: !r, nome: f.nome, nivel_ids: f.nivel_ids, escala_id: f.escala_id === '' ? null : f.escala_id, progressao: f.progressao }
        : { ...f, regra_id: r?.id ?? null, nova: !r };

    modal.processando = true;
    router.post(qualitativa ? '/avaliacao/configuracao/regras-qualitativas' : '/avaliacao/configuracao/regras', { ...dados, ano_lectivo_id: props.ano_id }, {
        preserveScroll: true,
        preserveState: true,
        onError: (e) => { modal.erros = e; },
        onSuccess: () => { fecharModal(); toast.success(r ? 'Regra guardada.' : 'Regra criada.'); },
        onFinish: () => { modal.processando = false; },
    });
}

function eliminarRegra() {
    const r = modal.regra;
    modal.processando = true;
    router.delete(`/avaliacao/configuracao/${r.qualitativa ? 'regras-qualitativas' : 'regras'}/${r.id}`, {
        preserveScroll: true,
        preserveState: true,
        onError: (e) => { modal.erros = e; },
        onSuccess: () => { fecharModal(); toast.success('Regra eliminada.'); },
        onFinish: () => { modal.processando = false; },
    });
}

// Os prazos são da escola (não de cada regra): por trimestre e tipo, com os tipos que as regras usam.
const tiposPrazo = computed(() => props.tipos.filter((t) => props.tipos_de_prazo.includes(t.valor)));
const prazoDe = (periodoId, tipo) => props.prazos.find((p) => p.periodo_id === periodoId && p.tipo === tipo);

function definirPrazo(periodoId, tipo) {
    const data = datas[`${periodoId}-${tipo}`] ?? prazoDe(periodoId, tipo)?.data_limite;
    if (!data) return;
    router.post('/avaliacao/configuracao/prazos', { periodo_id: periodoId, tipo, data_limite: data }, aoGuardar('Prazo definido.'));
}

function criarRegrasPorDefeito() {
    router.post('/avaliacao/configuracao/regras/defeito', { ano_lectivo_id: props.ano_id }, aoGuardar('Regras por defeito criadas.'));
}

function prolongar(prazo) {
    const p = prolongamentos[prazo.id];
    router.post(`/avaliacao/configuracao/prazos/${prazo.id}/excepcoes`, { ...p, turma_id: p.turma_id || null }, {
        ...aoGuardar('Prazo prolongado.'), onSuccess: () => { delete prolongamentos[prazo.id]; toast.success('Prazo prolongado.'); },
    });
}

const def = reactive({ max_avaliacoes: props.definicoes?.max_avaliacoes ?? 10, min_avaliacoes: props.definicoes?.min_avaliacoes ?? 5 });
const guardarDefinicoes = () => router.post('/avaliacao/configuracao/definicoes', { max_avaliacoes: def.max_avaliacoes, min_avaliacoes: def.min_avaliacoes }, aoGuardar('Definições guardadas.'));

const doc = reactive({ linhas_extra: props.documentos.linhas_extra ?? '', lema: props.documentos.lema ?? '', visto_cargo: props.documentos.visto_cargo ?? '', visto_nome: props.documentos.visto_nome ?? '' });
const guardarDocumentos = () => router.post('/avaliacao/configuracao/documentos', { ...doc }, aoGuardar('Cabeçalho dos documentos guardado.'));

const compNivel = ref(''); // '' = todas as classes
const compDisciplina = ref('');
const compLista = ref([]); // [{ id, descricao, ativa }]

function carregarCompetencias() {
    compLista.value = props.competencias
        .filter((c) => c.disciplina_id === compDisciplina.value && (c.nivel_academico_id ?? '') === compNivel.value)
        .map((c) => ({ id: c.id, descricao: c.descricao, ativa: c.ativa }));
}

function guardarCompetencias() {
    router.post('/avaliacao/configuracao/competencias', {
        disciplina_id: compDisciplina.value, nivel_academico_id: compNivel.value === '' ? null : compNivel.value, competencias: compLista.value,
    }, { ...aoGuardar('Competências guardadas.'), onSuccess: () => { toast.success('Competências guardadas.'); carregarCompetencias(); } });
}

function moverCompetencia(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= compLista.value.length) return;
    [compLista.value[i], compLista.value[j]] = [compLista.value[j], compLista.value[i]];
}

const EXEMPLO = [['INS', 'Insuficiente'], ['SUF', 'Suficiente'], ['B', 'Bom'], ['MB', 'Muito Bom'], ['EXC', 'Excelente']];
const editor = ref(null); // { id, nome, niveis: [{ id, codigo, designacao }] }

const novaEscala = () => { editor.value = { id: null, nome: '', niveis: EXEMPLO.map(([codigo, designacao]) => ({ id: null, codigo, designacao })) }; };
const editarEscala = (e) => { editor.value = { id: e.id, nome: e.nome, niveis: e.niveis.map((n) => ({ ...n })) }; };

function guardarEscala() {
    router.post('/avaliacao/configuracao/escalas', editor.value, {
        ...aoGuardar('Escala guardada.'), onSuccess: () => { editor.value = null; toast.success('Escala guardada.'); },
    });
}

function mover(i, delta) {
    const lista = editor.value.niveis;
    const j = i + delta;
    if (j < 0 || j >= lista.length) return;
    [lista[i], lista[j]] = [lista[j], lista[i]];
}

</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-4 mb-5">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Configuração da avaliação</h1>
                <div class="text-muted">Defina as regras uma vez e ligue-lhes as classes. Tudo vale para o ano lectivo escolhido.</div>
            </div>
            <select class="form-select form-select-sm w-200px" :value="ano_id" @change="router.get('/avaliacao/configuracao', { ano: $event.target.value })">
                <option v-for="a in anos" :key="a.id" :value="a.id">{{ a.nome }}</option>
            </select>
        </div>

        <ul class="nav nav-tabs nav-line-tabs nav-line-tabs-2x fs-6 fw-semibold mb-6">
            <li v-for="t in abas" :key="t.id" class="nav-item">
                <a href="#" class="nav-link d-flex align-items-center gap-2" :class="{ active: aba === t.id }" @click.prevent="aba = t.id">
                    {{ t.nome }}
                    <span v-if="t.total !== undefined" class="badge badge-light-primary">{{ t.total }}</span>
                    <span v-if="t.alerta" class="badge badge-circle badge-warning w-10px h-10px p-0" title="Há classes sem regra"></span>
                </a>
            </li>
        </ul>

        <!-- ============ REGRAS ============ -->
        <div v-show="aba === 'regras'">
            <div v-if="semRegra.length" class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div><strong>Classes sem regra:</strong> {{ semRegra.map((n) => n.nome).join(', ') }}. Enquanto não seguirem uma regra, não se lançam notas nelas.</div>
                <button type="button" class="btn btn-sm btn-primary" @click="criarRegrasPorDefeito">Criar as regras por defeito em falta</button>
            </div>

            <div class="text-muted fs-7 mb-5">Cada cartão é uma regra independente. Clique num cartão para a editar. Uma classe segue uma só regra: ao juntá-la a outra, sai da anterior.</div>

            <div class="row g-6 mb-6">
                <div v-for="r in regras" :key="chave(r)" class="col-md-6 col-xl-4">
                    <div class="card border-hover-primary h-100 cursor-pointer" role="button" tabindex="0" @click="abrirEditar(r)" @keyup.enter="abrirEditar(r)">
                        <div class="card-header border-0 pt-9">
                            <div class="card-title m-0">
                                <div class="symbol symbol-50px w-50px bg-light">
                                    <span class="symbol-label fw-bold fs-6" :class="r.qualitativa ? 'text-info' : 'text-primary'">{{ r.qualitativa ? 'QL' : `0–${r.escala_maxima}` }}</span>
                                </div>
                            </div>
                            <div class="card-toolbar">
                                <span class="badge fw-bold px-4 py-3" :class="r.qualitativa ? 'badge-light-info' : 'badge-light-primary'">{{ r.qualitativa ? 'Qualitativa' : 'Com notas' }}</span>
                            </div>
                        </div>

                        <div class="card-body p-9 pt-4 d-flex flex-column">
                            <div class="fs-3 fw-bold text-gray-900">{{ r.nome }}</div>
                            <p class="text-gray-500 fw-semibold fs-6 mt-1 mb-6">
                                {{ r.nivel_ids.length ? `${r.nivel_ids.length} ${r.nivel_ids.length === 1 ? 'classe segue' : 'classes seguem'} esta regra` : 'Nenhuma classe ligada' }}
                            </p>

                            <div class="d-flex flex-wrap mb-4">
                                <template v-if="r.qualitativa">
                                    <div class="border border-gray-300 border-dashed rounded min-w-125px py-3 px-4 me-4 mb-3">
                                        <div class="fs-6 fw-bold" :class="r.escala_id ? 'text-gray-800' : 'text-warning'">{{ r.escala_id ? nomeDaEscala(r.escala_id) : 'Por escolher' }}</div>
                                        <div class="fw-semibold text-gray-500">Escala</div>
                                    </div>
                                    <div class="border border-gray-300 border-dashed rounded min-w-125px py-3 px-4 mb-3">
                                        <div class="fs-6 text-gray-800 fw-bold">{{ nomeDaProgressao(r.progressao).split(' (')[0] }}</div>
                                        <div class="fw-semibold text-gray-500">Progressão</div>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="border border-gray-300 border-dashed rounded min-w-100px py-3 px-4 me-4 mb-3">
                                        <div class="fs-6 text-gray-800 fw-bold">{{ r.nota_minima }}</div>
                                        <div class="fw-semibold text-gray-500">Nota mínima</div>
                                    </div>
                                    <div class="border border-gray-300 border-dashed rounded min-w-100px py-3 px-4 mb-3">
                                        <div class="fs-6 text-gray-800 fw-bold">{{ Number(r.peso_mac) }}% · {{ Number(r.peso_pt) }}%</div>
                                        <div class="fw-semibold text-gray-500">MAC · PT</div>
                                    </div>
                                </template>
                            </div>

                            <div v-if="!r.qualitativa" class="d-flex flex-wrap gap-2 mb-4">
                                <span v-if="r.com_exame" class="badge badge-light-warning">Exame próprio</span>
                                <span v-if="r.com_recurso" class="badge badge-light-danger">Recurso</span>
                                <span v-if="!r.reprova_por_aproveitamento" class="badge badge-light">Não reprova</span>
                                <span v-if="!r.com_exame && !r.com_recurso && r.reprova_por_aproveitamento" class="badge badge-light">Média final decide</span>
                            </div>
                            <div v-if="!r.qualitativa" class="h-4px w-100 bg-light mb-5 d-flex" :title="`Média trimestral: ${Number(r.peso_mac)}% avaliação contínua, ${Number(r.peso_pt)}% prova`">
                                <div class="bg-primary h-4px" :style="{ width: Number(r.peso_mac) + '%' }"></div>
                                <div class="bg-warning h-4px" :style="{ width: Number(r.peso_pt) + '%' }"></div>
                            </div>

                            <div class="d-flex align-items-center justify-content-between mt-auto pt-2">
                                <div class="symbol-group symbol-hover">
                                    <div v-for="id in r.nivel_ids.slice(0, 5)" :key="id" class="symbol symbol-35px symbol-circle" :title="nomeDaClasse(id)">
                                        <span class="symbol-label bg-light-success text-success fw-bold fs-8">{{ sigla(id) }}</span>
                                    </div>
                                    <div v-if="r.nivel_ids.length > 5" class="symbol symbol-35px symbol-circle">
                                        <span class="symbol-label bg-light text-gray-600 fw-bold fs-8">+{{ r.nivel_ids.length - 5 }}</span>
                                    </div>
                                </div>
                                <span class="text-primary fw-semibold fs-7">Editar →</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border border-dashed border-gray-400 border-hover-primary cursor-pointer bg-transparent" role="button" tabindex="0" @click="abrirNova(false)" @keyup.enter="abrirNova(false)">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center text-center p-9">
                            <div class="symbol symbol-50px w-50px bg-light-primary mb-4"><span class="symbol-label fs-1 fw-bold text-primary">+</span></div>
                            <div class="fs-4 fw-bold text-gray-900">Nova regra com notas</div>
                            <div class="text-gray-500 fs-7 mt-1">Para classes avaliadas por notas (1.ª à 9.ª)</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border border-dashed border-gray-400 border-hover-primary cursor-pointer bg-transparent" role="button" tabindex="0" @click="abrirNova(true)" @keyup.enter="abrirNova(true)">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center text-center p-9">
                            <div class="symbol symbol-50px w-50px bg-light-info mb-4"><span class="symbol-label fs-1 fw-bold text-info">+</span></div>
                            <div class="fs-4 fw-bold text-gray-900">Nova regra qualitativa</div>
                            <div class="text-gray-500 fs-7 mt-1">Para a Creche e o Pré-Escolar, por níveis</div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="semAvaliacao.length" class="text-muted fs-8">Ainda sem avaliação disponível: {{ semAvaliacao.map((n) => n.nome).join(', ') }}. Pode juntá-las a uma regra, se quiser.</div>
        </div>

        <!-- ============ PRAZOS ============ -->
        <div v-show="aba === 'prazos'">
            <div class="text-muted fs-7 mb-4">Definidos uma só vez: valem para toda a escola e para todas as regras. Sem prazo definido, vale o fim do trimestre.</div>
            <div class="row g-5">
                <div v-for="p in periodos" :key="p.id" class="col-12 col-lg-4">
                    <div class="card card-flush h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-baseline mb-4">
                                <h3 class="fs-4 fw-bold mb-0">{{ p.nome }}</h3>
                                <span class="text-muted fs-8">termina em {{ p.data_fim }}</span>
                            </div>
                            <div v-for="t in tiposPrazo" :key="t.valor" class="mb-4 pb-4 border-bottom border-dashed">
                                <label class="form-label fs-8 fw-bold text-muted text-uppercase">{{ t.nome }}</label>
                                <div class="d-flex gap-2">
                                    <input :value="datas[`${p.id}-${t.valor}`] ?? prazoDe(p.id, t.valor)?.data_limite ?? ''" type="date" class="form-control form-control-sm"
                                        @input="datas[`${p.id}-${t.valor}`] = $event.target.value">
                                    <button type="button" class="btn btn-sm btn-light" @click="definirPrazo(p.id, t.valor)">Definir</button>
                                </div>
                                <div v-if="prazoDe(p.id, t.valor)" class="mt-2">
                                    <div class="fs-8 text-muted">Em vigor: <strong>{{ prazoDe(p.id, t.valor).data_limite }}</strong></div>
                                    <div v-for="e in prazoDe(p.id, t.valor).excepcoes" :key="e.id" class="fs-8 text-muted">
                                        Excepção até {{ e.data_limite }}{{ e.turma_id ? ' (turma)' : '' }} — {{ e.motivo }}
                                    </div>
                                    <a v-if="can('nota.prolongar-prazo')" href="#" class="fs-8"
                                        @click.prevent="prolongamentos[prazoDe(p.id, t.valor).id] ??= { data_limite: '', motivo: '', turma_id: '' }">Prolongar prazo</a>
                                    <div v-if="prolongamentos[prazoDe(p.id, t.valor).id]" class="d-flex flex-wrap gap-2 mt-2">
                                        <input v-model="prolongamentos[prazoDe(p.id, t.valor).id].data_limite" type="date" class="form-control form-control-sm">
                                        <input v-model="prolongamentos[prazoDe(p.id, t.valor).id].motivo" type="text" class="form-control form-control-sm" placeholder="Motivo">
                                        <select v-model="prolongamentos[prazoDe(p.id, t.valor).id].turma_id" class="form-select form-select-sm">
                                            <option value="">Todas as turmas</option>
                                            <option v-for="tu in turmas" :key="tu.id" :value="tu.id">{{ tu.rotulo }}</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-primary" @click="prolongar(prazoDe(p.id, t.valor))">Prolongar</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ AVALIAÇÕES POR TRIMESTRE ============ -->
        <div v-show="aba === 'limites'">
            <div class="card card-flush">
                <div class="card-body">
                    <h3 class="fs-4 fw-bold">Avaliações contínuas por trimestre</h3>
                    <div class="text-muted fs-7 mb-4">Definido uma só vez: vale para todas as classes e para todos os professores. O mínimo não é a nota mínima de aprovação (essa define-se em cada regra).</div>
                    <div class="d-flex flex-wrap align-items-end gap-4">
                        <div>
                            <label class="form-label fs-8 fw-bold text-muted text-uppercase">Máximo de avaliações</label>
                            <input v-model.number="def.max_avaliacoes" type="number" min="1" max="30" class="form-control form-control-sm w-200px">
                        </div>
                        <div>
                            <label class="form-label fs-8 fw-bold text-muted text-uppercase">Mínimo esperado</label>
                            <input v-model.number="def.min_avaliacoes" type="number" min="1" max="30" class="form-control form-control-sm w-200px">
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" @click="guardarDefinicoes">Guardar</button>
                    </div>
                    <div class="text-muted fs-8 mt-3">O máximo trava a criação de avaliações. O mínimo é um aviso ao professor quando criou menos do que a escola pede, e não altera a média.</div>
                </div>
            </div>
        </div>

        <!-- ============ DOCUMENTOS ============ -->
        <div v-show="aba === 'documentos'">
            <div class="card card-flush">
                <div class="card-body">
                    <h3 class="fs-4 fw-bold mb-4">Cabeçalho e assinaturas dos documentos</h3>
                <div class="row g-5">
                    <div class="col-12 col-lg-6">
                        <div class="text-muted fs-7 mb-3">A província, o município, o nome, o logótipo e o responsável da escola vêm do cadastro do estabelecimento. Aqui acrescenta o que falta.</div>
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Linhas a acrescentar (uma por linha)</label>
                        <textarea v-model="doc.linhas_extra" rows="3" class="form-control form-control-sm mb-3" placeholder="Direcção Municipal da Educação&#10;Secção de Educação e Ensino"></textarea>
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Lema da escola (opcional)</label>
                        <input v-model="doc.lema" type="text" class="form-control form-control-sm mb-3" placeholder="Linguagem do amor e da sabedoria">
                        <div class="row g-3">
                            <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Visto: cargo</label><input v-model="doc.visto_cargo" type="text" class="form-control form-control-sm" placeholder="Directora Municipal"></div>
                            <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Visto: nome</label><input v-model="doc.visto_nome" type="text" class="form-control form-control-sm"></div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary mt-4" @click="guardarDocumentos">Guardar</button>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="fs-8 fw-bold text-muted text-uppercase mb-2">Como fica o cabeçalho</div>
                        <div class="border rounded p-3 bg-body-secondary text-center fw-bold fs-7">
                            <div v-for="(l, i) in cabecalho_linhas" :key="i">{{ l }}</div>
                            <div v-if="documentos.lema" class="fw-normal fst-italic">«{{ documentos.lema }}»</div>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </div>

        <!-- ============ ESCALAS E COMPETÊNCIAS ============ -->
        <div v-if="abas.some((t) => t.id === 'qualitativo')" v-show="aba === 'qualitativo'">
            <div class="card card-flush mb-6">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h3 class="fs-4 fw-bold mb-0">Escalas qualitativas</h3>
                        <button type="button" class="btn btn-sm btn-light" @click="novaEscala">Nova escala</button>
                    </div>
                <div v-if="!escalas.length && !editor" class="text-muted">Ainda não há escalas. Crie uma para a Creche e o Pré-Escolar avaliarem as crianças.</div>
                <div v-for="e in escalas" :key="e.id" class="d-flex flex-wrap align-items-center gap-3 mb-3">
                    <strong>{{ e.nome }}</strong>
                    <span v-for="n in e.niveis" :key="n.id" class="badge badge-light">{{ n.codigo }} · {{ n.designacao }}</span>
                    <a href="#" class="fs-7" @click.prevent="editarEscala(e)">Editar</a>
                </div>
                <div v-if="editor" class="border rounded p-4 mt-4 bg-body-secondary">
                    <input v-model="editor.nome" type="text" class="form-control form-control-sm w-300px mb-3" placeholder="Nome da escala (ex.: Escala do Pré-Escolar)">
                    <div class="text-muted fs-8 mb-2">Do nível mais baixo para o mais alto.</div>
                    <div v-for="(n, i) in editor.niveis" :key="i" class="d-flex flex-wrap gap-2 mb-2">
                        <input v-model="n.codigo" type="text" class="form-control form-control-sm w-100px" placeholder="Código">
                        <input v-model="n.designacao" type="text" class="form-control form-control-sm w-250px" placeholder="Designação">
                        <button type="button" class="btn btn-sm btn-light" title="Subir" @click="mover(i, -1)">↑</button>
                        <button type="button" class="btn btn-sm btn-light" title="Descer" @click="mover(i, 1)">↓</button>
                        <button type="button" class="btn btn-sm btn-light-danger" title="Remover" @click="editor.niveis.splice(i, 1)">×</button>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-light" @click="editor.niveis.push({ id: null, codigo: '', designacao: '' })">Adicionar nível</button>
                        <button type="button" class="btn btn-sm btn-primary" @click="guardarEscala">Guardar escala</button>
                        <button type="button" class="btn btn-sm btn-light" @click="editor = null">Cancelar</button>
                    </div>
                </div>
                </div>
            </div>

            <div class="card card-flush">
                <div class="card-body">
                    <h3 class="fs-4 fw-bold mb-4">Competências por área</h3>
                <div class="text-muted fs-7 mb-3">Opcional. Liste o que se observa em cada área (ex.: «Reconhece as cores primárias»). O educador avalia cada competência e o sistema sugere o nível da área, que ele confirma.</div>
                <div class="d-flex flex-wrap gap-3 mb-4">
                    <select v-model="compDisciplina" class="form-select form-select-sm w-250px" @change="carregarCompetencias">
                        <option value="" disabled>Escolha a área</option>
                        <option v-for="d in disciplinas" :key="d.id" :value="d.id">{{ d.nome }}</option>
                    </select>
                    <select v-model="compNivel" class="form-select form-select-sm w-250px" @change="carregarCompetencias">
                        <option value="">Todas as classes</option>
                        <option v-for="c in qualitativas" :key="c.id" :value="c.id">{{ c.nome }}</option>
                    </select>
                </div>
                <template v-if="compDisciplina !== ''">
                    <div v-if="!compLista.length" class="text-muted fs-7 mb-3">Esta área ainda não tem competências para esta selecção.</div>
                    <div v-for="(c, i) in compLista" :key="i" class="d-flex flex-wrap gap-2 mb-2 align-items-center">
                        <span class="text-muted w-30px">{{ i + 1 }}.</span>
                        <input v-model="c.descricao" type="text" class="form-control form-control-sm w-400px mw-100" placeholder="Descrição da competência">
                        <label class="form-check form-check-sm form-check-custom form-check-solid"><input v-model="c.ativa" type="checkbox" class="form-check-input"><span class="form-check-label">Activa</span></label>
                        <button type="button" class="btn btn-sm btn-light" title="Subir" @click="moverCompetencia(i, -1)">↑</button>
                        <button type="button" class="btn btn-sm btn-light" title="Descer" @click="moverCompetencia(i, 1)">↓</button>
                        <button type="button" class="btn btn-sm btn-light-danger" title="Remover" @click="compLista.splice(i, 1)">×</button>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-light" @click="compLista.push({ id: null, descricao: '', ativa: true })">Adicionar competência</button>
                        <button type="button" class="btn btn-sm btn-primary" @click="guardarCompetencias">Guardar competências</button>
                    </div>
                </template>
                </div>
            </div>
        </div>

        <RegraModal
            :regra="modal.regra" :nova="modal.nova" :niveis="niveis" :regras="regras" :escalas="escalas" :progressoes="progressoes" :modos="modos" :disciplinas="disciplinas"
            :processando="modal.processando" :erros="modal.erros" @guardar="guardarRegra" @eliminar="eliminarRegra" @fechar="fecharModal"
        />
    </div>
</template>
