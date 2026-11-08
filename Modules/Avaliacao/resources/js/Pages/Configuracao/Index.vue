<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';

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

const rascunhos = reactive({}); // regra -> campos editados e ainda não guardados
const datas = reactive({}); // `${regra}-${periodo}-${tipo}` -> data
const prolongamentos = reactive({}); // prazo_id -> { data_limite, motivo, turma_id }
const novas = ref([]); // regras criadas no ecrã e ainda não guardadas

const aoFalhar = (e) => toast.error(Object.values(e)[0]);
const aoGuardar = (texto) => ({ preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => toast.success(texto) });

const todas = computed(() => [...props.regras, ...novas.value]);
const chave = (r) => `${r.qualitativa ? 'q' : 'n'}${r.id ?? r.rascunho}`;
const regraDaClasse = (nivelId) => props.regras.find((r) => r.nivel_ids.includes(nivelId));
const semRegra = computed(() => props.niveis.filter((n) => n.sem_regra && n.tem_regra_padrao));
const semAvaliacao = computed(() => props.niveis.filter((n) => n.sem_regra && !n.tem_regra_padrao)); // classes que ainda não têm avaliação disponível
const qualitativas = computed(() => props.niveis.filter((n) => n.qualitativo));

function formulario(r) {
    rascunhos[chave(r)] ??= { ...r, nivel_ids: [...r.nivel_ids], disciplinas_chave: [...(r.disciplinas_chave ?? [])], ...(r.qualitativa ? { escala_id: r.escala_id ?? '' } : {}) };
    return rascunhos[chave(r)];
}

let contador = 0;
function novaRegra(qualitativa) {
    const base = qualitativa
        ? { qualitativa: true, nome: 'Nova regra qualitativa', escala_id: '', progressao: 0 }
        : { qualitativa: false, nome: 'Nova regra', escala_maxima: 10, nota_minima: '5', peso_mac: '50', peso_pt: '50', peso_mt1: null, peso_mt2: null, peso_mt3: null, peso_mfd_exame: '40', peso_exame: '60',
            com_exame: false, com_recurso: false, recurso_max_disciplinas: null, disciplinas_chave: [], casas_decimais: 2, modo_arredondamento: 0, reprova_por_aproveitamento: true };
    novas.value.push({ id: null, rascunho: `n${++contador}`, nivel_ids: [], prazos: [], turmas: [], ...base });
}

function aoGuardado(r, texto) {
    return { ...aoGuardar(texto), onSuccess: () => { delete rascunhos[chave(r)]; novas.value = novas.value.filter((n) => n !== r); toast.success(texto); } };
}

function guardarRegra(r) {
    const f = formulario(r);
    const dados = r.qualitativa
        ? { regra_id: r.id, nova: r.id === null, nome: f.nome, nivel_ids: f.nivel_ids, escala_id: f.escala_id === '' ? null : f.escala_id, progressao: f.progressao }
        : { ...f, regra_id: r.id, nova: r.id === null };
    router.post(r.qualitativa ? '/avaliacao/configuracao/regras-qualitativas' : '/avaliacao/configuracao/regras', { ...dados, ano_lectivo_id: props.ano_id }, aoGuardado(r, 'Regra guardada.'));
}

function eliminarRegra(r) {
    if (r.id === null) { novas.value = novas.value.filter((n) => n !== r); return; }
    router.delete(`/avaliacao/configuracao/${r.qualitativa ? 'regras-qualitativas' : 'regras'}/${r.id}`, aoGuardar('Regra eliminada.'));
}

function juntarClasse(r, nivelId) {
    const f = formulario(r);
    if (nivelId && !f.nivel_ids.includes(nivelId)) f.nivel_ids.push(nivelId);
}
const tirarClasse = (r, nivelId) => { const f = formulario(r); f.nivel_ids = f.nivel_ids.filter((i) => i !== nivelId); };
const nomeDaClasse = (id) => props.niveis.find((n) => n.id === id)?.nome ?? id;
// Só se podem juntar a esta regra as classes do mesmo tipo (qualitativas ou com notas) que ainda não estão nela.
const juntaveis = (r) => props.niveis.filter((n) => n.qualitativo === !!r.qualitativa && !formulario(r).nivel_ids.includes(n.id));

// Os prazos são da escola (não de cada regra): por trimestre e tipo, com os tipos que as regras usam.
const tiposPrazo = computed(() => props.tipos.filter((t) => props.tipos_de_prazo.includes(t.valor)));
const prazoDe = (periodoId, tipo) => props.prazos.find((p) => p.periodo_id === periodoId && p.tipo === tipo);

function definirPrazo(periodoId, tipo) {
    const data = datas[`${periodoId}-${tipo}`];
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
        <div class="mb-6 d-flex flex-wrap justify-content-between align-items-end gap-4">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Configuração da avaliação</h1>
                <div class="text-muted">Cada regra serve várias classes: defina-a uma vez e ligue-lhe as classes. Escala, pesos, arredondamento e prazos, por ano lectivo.</div>
            </div>
            <div class="d-flex gap-3">
                <select class="form-select form-select-sm w-200px" :value="ano_id" @change="router.get('/avaliacao/configuracao', { ano: $event.target.value })">
                    <option v-for="a in anos" :key="a.id" :value="a.id">{{ a.nome }}</option>
                </select>
                <button type="button" class="btn btn-sm btn-light" @click="novaRegra(false)">Nova regra</button>
                <button type="button" class="btn btn-sm btn-light" @click="novaRegra(true)">Nova regra qualitativa</button>
            </div>
        </div>

        <div class="card card-flush mb-6">
            <div class="card-header"><h3 class="card-title fw-bold">Avaliações contínuas por trimestre</h3></div>
            <div class="card-body">
                <div class="text-muted fs-7 mb-4">Definido uma só vez: vale para todas as classes e para todos os professores da escola. O mínimo não é a nota mínima de aprovação (essa define-se em cada regra).</div>
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
                <div class="text-muted fs-8 mt-3">Por defeito, até 10 avaliações por trimestre e pelo menos 5 esperadas. O máximo trava a criação de avaliações; o mínimo é um aviso ao professor quando criou menos do que a escola pede, e não altera a média.</div>

            </div>
        </div>

        <div class="card card-flush mb-6">
            <div class="card-header"><h3 class="card-title fw-bold">Cabeçalho e assinaturas dos documentos</h3></div>
            <div class="card-body">
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

        <div v-if="qualitativas.length || regras.some((r) => r.qualitativa)" class="card card-flush mb-6">
            <div class="card-header">
                <h3 class="card-title fw-bold">Escalas qualitativas</h3>
                <div class="card-toolbar"><button type="button" class="btn btn-sm btn-light" @click="novaEscala">Nova escala</button></div>
            </div>
            <div class="card-body">
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

        <div v-if="qualitativas.length || regras.some((r) => r.qualitativa)" class="card card-flush mb-6">
            <div class="card-header"><h3 class="card-title fw-bold">Competências por área</h3></div>
            <div class="card-body">
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

        <div v-if="semRegra.length" class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div><strong>Classes sem regra:</strong> {{ semRegra.map((n) => n.nome).join(', ') }}. Enquanto não seguirem uma regra, não se lançam notas nelas.</div>
            <button type="button" class="btn btn-sm btn-primary" @click="criarRegrasPorDefeito">Criar as regras por defeito em falta</button>
        </div>
        <div v-if="semAvaliacao.length" class="text-muted fs-8 mb-4">Ainda sem avaliação disponível: {{ semAvaliacao.map((n) => n.nome).join(', ') }}. Pode juntá-las a uma regra, se quiser.</div>

        <div class="card card-flush mb-6">
            <div class="card-header"><h3 class="card-title fw-bold">Prazos de lançamento</h3></div>
            <div class="card-body">
                <div class="text-muted fs-7 mb-4">Definidos uma só vez: valem para toda a escola, para todas as regras. Sem prazo definido, vale o fim do trimestre.</div>
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-7 gy-3 mb-0">
                        <thead><tr class="text-muted fw-bold text-uppercase"><th>Trimestre</th><th v-for="t in tiposPrazo" :key="t.valor">{{ t.nome }}</th></tr></thead>
                        <tbody>
                            <tr v-for="p in periodos" :key="p.id">
                                <td class="fw-semibold">{{ p.nome }}<div class="text-muted fs-8">termina em {{ p.data_fim }}</div></td>
                                <td v-for="t in tiposPrazo" :key="t.valor">
                                    <div class="d-flex align-items-center gap-2">
                                        <input v-model="datas[`${p.id}-${t.valor}`]" type="date" class="form-control form-control-sm w-175px"
                                            @focus="datas[`${p.id}-${t.valor}`] ??= prazoDe(p.id, t.valor)?.data_limite">
                                        <button type="button" class="btn btn-sm btn-light" @click="definirPrazo(p.id, t.valor)">Definir</button>
                                    </div>
                                    <div v-if="prazoDe(p.id, t.valor)" class="mt-2">
                                        <div class="fs-8 text-muted">Em vigor: {{ prazoDe(p.id, t.valor).data_limite }}</div>
                                        <div v-for="e in prazoDe(p.id, t.valor).excepcoes" :key="e.id" class="fs-8 text-muted">
                                            Excepção até {{ e.data_limite }}{{ e.turma_id ? ' (turma)' : '' }} — {{ e.motivo }}
                                        </div>
                                        <a v-if="can('nota.prolongar-prazo')" href="#" class="fs-8"
                                            @click.prevent="prolongamentos[prazoDe(p.id, t.valor).id] ??= { data_limite: '', motivo: '', turma_id: '' }">Prolongar prazo</a>
                                        <div v-if="prolongamentos[prazoDe(p.id, t.valor).id]" class="d-flex flex-wrap gap-2 mt-2">
                                            <input v-model="prolongamentos[prazoDe(p.id, t.valor).id].data_limite" type="date" class="form-control form-control-sm w-150px">
                                            <input v-model="prolongamentos[prazoDe(p.id, t.valor).id].motivo" type="text" class="form-control form-control-sm w-200px" placeholder="Motivo">
                                            <select v-model="prolongamentos[prazoDe(p.id, t.valor).id].turma_id" class="form-select form-select-sm w-200px">
                                                <option value="">Todas as turmas</option>
                                                <option v-for="tu in turmas" :key="tu.id" :value="tu.id">{{ tu.rotulo }}</option>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-primary" @click="prolongar(prazoDe(p.id, t.valor))">Prolongar</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-for="r in todas" :key="chave(r)" class="card card-flush mb-6">
            <div class="card-header">
                <div class="card-title flex-grow-1 gap-3">
                    <input v-model="formulario(r).nome" type="text" class="form-control form-control-sm fw-bold w-300px mw-100" placeholder="Nome da regra">
                    <span class="badge" :class="r.qualitativa ? 'badge-light-info' : 'badge-light-primary'">{{ r.qualitativa ? 'Qualitativa' : 'Com notas' }}</span>
                    <span v-if="r.id === null" class="badge badge-light-warning">Por guardar</span>
                </div>
                <div class="card-toolbar">
                    <button v-if="!formulario(r).nivel_ids.length" type="button" class="btn btn-sm btn-light-danger" @click="eliminarRegra(r)">Eliminar regra</button>
                </div>
            </div>

            <div class="card-body">
                <div class="fs-8 fw-bold text-muted text-uppercase mb-2">Classes que seguem esta regra</div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-5">
                    <span v-for="id in formulario(r).nivel_ids" :key="id" class="badge badge-light-success fs-7 d-inline-flex align-items-center gap-2">
                        {{ nomeDaClasse(id) }}<a href="#" class="text-danger" title="Tirar da regra" @click.prevent="tirarClasse(r, id)">×</a>
                    </span>
                    <span v-if="!formulario(r).nivel_ids.length" class="text-muted fs-7">Nenhuma classe ligada.</span>
                    <select v-if="juntaveis(r).length" class="form-select form-select-sm w-250px" @change="juntarClasse(r, Number($event.target.value)); $event.target.value = ''">
                        <option value="">+ Juntar uma classe…</option>
                        <option v-for="n in juntaveis(r)" :key="n.id" :value="n.id">{{ n.nome }}{{ regraDaClasse(n.id) && regraDaClasse(n.id) !== r ? ` (passa de «${regraDaClasse(n.id).nome}»)` : n.sem_regra ? ' (sem regra)' : '' }}</option>
                    </select>
                </div>

                <template v-if="r.qualitativa">
                    <div v-if="!escalas.length" class="text-muted">Crie primeiro uma escala qualitativa, acima.</div>
                    <div v-else class="d-flex flex-wrap align-items-end gap-3">
                        <div>
                            <label class="form-label fs-8 fw-bold text-muted text-uppercase">Escala</label>
                            <select v-model="formulario(r).escala_id" class="form-select form-select-sm w-300px">
                                <option value="">Escolha a escala</option>
                                <option v-for="e in escalas" :key="e.id" :value="e.id">{{ e.nome }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label fs-8 fw-bold text-muted text-uppercase">Progressão</label>
                            <select v-model="formulario(r).progressao" class="form-select form-select-sm w-300px">
                                <option v-for="p in progressoes" :key="p.valor" :value="p.valor">{{ p.nome }}</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="!formulario(r).escala_id && escalas.length" class="text-warning fs-7 mt-2">Escolha a escala para poder avaliar estas classes.</div>
                </template>

                <div v-else class="row g-4">
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Escala máx.</label><input v-model="formulario(r).escala_maxima" type="number" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Nota mínima</label><input v-model="formulario(r).nota_minima" type="text" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso MAC %</label><input v-model="formulario(r).peso_mac" type="text" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso PT %</label><input v-model="formulario(r).peso_pt" type="text" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Casas decimais</label><input v-model="formulario(r).casas_decimais" type="number" min="0" max="4" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Arredondamento</label>
                        <select v-model="formulario(r).modo_arredondamento" class="form-select form-select-sm"><option v-for="m in modos" :key="m.valor" :value="m.valor">{{ m.nome }}</option></select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Pesos dos trimestres % (vazio = iguais)</label>
                        <div class="d-flex gap-2">
                            <input v-model="formulario(r).peso_mt1" type="text" class="form-control form-control-sm" placeholder="1.º">
                            <input v-model="formulario(r).peso_mt2" type="text" class="form-control form-control-sm" placeholder="2.º">
                            <input v-model="formulario(r).peso_mt3" type="text" class="form-control form-control-sm" placeholder="3.º">
                        </div>
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-end gap-6 flex-wrap">
                        <label class="form-check form-check-sm form-check-custom form-check-solid"><input v-model="formulario(r).reprova_por_aproveitamento" type="checkbox" class="form-check-input"><span class="form-check-label">Reprova por aproveitamento</span></label>
                        <label class="form-check form-check-sm form-check-custom form-check-solid"><input v-model="formulario(r).com_exame" type="checkbox" class="form-check-input"><span class="form-check-label">Exame próprio</span></label>
                        <label class="form-check form-check-sm form-check-custom form-check-solid"><input v-model="formulario(r).com_recurso" type="checkbox" class="form-check-input"><span class="form-check-label">Exame de recurso</span></label>
                    </div>
                    <div v-if="formulario(r).com_exame" class="col-12 col-md-6">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Exame próprio: peso da MFD % e do exame % (MF = MFD × peso + NE × peso)</label>
                        <div class="d-flex gap-2">
                            <input v-model="formulario(r).peso_mfd_exame" type="text" class="form-control form-control-sm" placeholder="MFD">
                            <input v-model="formulario(r).peso_exame" type="text" class="form-control form-control-sm" placeholder="Exame">
                        </div>
                    </div>
                    <div v-if="formulario(r).com_recurso" class="col-12 col-md-6">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Recurso: máximo de disciplinas negativas (vazio = sem máximo)</label>
                        <input v-model="formulario(r).recurso_max_disciplinas" type="number" min="0" class="form-control form-control-sm w-150px">
                        <div class="text-muted fs-8 mt-1">Acima deste número o aluno reprova directamente, sem recurso.</div>
                    </div>
                    <div v-if="formulario(r).com_recurso" class="col-12 col-md-6">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Disciplinas-chave (negativa a uma delas = reprovação directa, sem recurso)</label>
                        <select v-model="formulario(r).disciplinas_chave" multiple size="4" class="form-select form-select-sm">
                            <option v-for="d in disciplinas" :key="d.id" :value="d.id">{{ d.nome }}</option>
                        </select>
                        <div class="text-muted fs-8 mt-1">Ctrl (ou Cmd) + clique para escolher várias. Sem nenhuma, qualquer negativa pode ir ao recurso.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-5"><button type="button" class="btn btn-sm btn-primary" @click="guardarRegra(r)">Guardar regra</button></div>
            </div>

        </div>
    </div>
</template>
