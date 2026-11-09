<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import RegraCartao from '../../Components/Configuracao/RegraCartao.vue';
import RegraModal from '../../Components/Configuracao/RegraModal.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';

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
const opcoesAnos = computed(() => props.anos.map((a) => ({ value: a.id, label: a.nome })));
const opcoesTurmas = computed(() => [{ value: '', label: 'Todas as turmas' }, ...props.turmas.map((t) => ({ value: t.id, label: t.rotulo }))]);
const opcoesAreas = computed(() => props.disciplinas.map((d) => ({ value: d.id, label: d.nome })));
const opcoesClassesQualitativas = computed(() => [{ value: '', label: 'Todas as classes' }, ...qualitativas.value.map((c) => ({ value: c.id, label: c.nome }))]);
const abas = computed(() => [
    { id: 'regras', nome: 'Regras', alerta: semRegra.value.length > 0, total: props.regras.length },
    { id: 'prazos', nome: 'Prazos' },
    { id: 'limites', nome: 'Avaliações por trimestre' },
    { id: 'documentos', nome: 'Documentos' },
    ...(qualitativas.value.length || props.regras.some((r) => r.qualitativa) ? [{ id: 'qualitativo', nome: 'Escalas e competências' }] : []),
]);

// ---- a janela de criar/editar uma regra
const modal = reactive({ regra: null, nova: false, editar: false, processando: false, erros: {} });
const abrirVer = (r) => { modal.erros = {}; modal.regra = r; modal.nova = false; modal.editar = false; };
const abrirEditar = (r) => { modal.erros = {}; modal.regra = r; modal.nova = false; modal.editar = true; };
const abrirNova = () => { modal.erros = {}; modal.regra = null; modal.nova = true; modal.editar = false; aba.value = 'regras'; };
const fecharModal = () => { modal.regra = null; modal.nova = false; modal.editar = false; modal.erros = {}; };

function guardarRegra(f, qualitativaNova) {
    const r = modal.regra;
    const qualitativa = r ? r.qualitativa : qualitativaNova;
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

// Eliminar (do cartão ou de dentro do modal): só as regras que ainda não foram usadas. Pede sempre confirmação.
const paraEliminar = ref(null);
const aEliminar = ref(false);
const mensagemEliminar = computed(() => {
    const r = paraEliminar.value;
    if (!r) return '';
    const classes = r.nivel_ids?.length ? ` As ${r.nivel_ids.length} classe(s) ligada(s) ficam sem regra.` : '';
    return `Tem a certeza de que quer eliminar a regra «${r.nome}»?${classes} Esta acção não pode ser desfeita.`;
});

function eliminarDaLista(r) {
    paraEliminar.value = r;
}

function eliminarRegra() {
    paraEliminar.value = modal.regra;
}

function confirmarEliminar() {
    const r = paraEliminar.value;
    aEliminar.value = true;
    router.delete(`/avaliacao/configuracao/${r.qualitativa ? 'regras-qualitativas' : 'regras'}/${r.id}`, {
        preserveScroll: true,
        preserveState: true,
        onError: (e) => { modal.erros = e; toast.error(Object.values(e)[0] ?? 'Não foi possível eliminar a regra.'); },
        onSuccess: () => { fecharModal(); toast.success('Regra eliminada.'); },
        onFinish: () => { aEliminar.value = false; paraEliminar.value = null; },
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
            <SelectSolid :model-value="ano_id" :options="opcoesAnos" pequeno class="w-200px" @update:model-value="(v) => router.get('/avaliacao/configuracao', { ano: v })" />
        </div>

        <ul class="nav nav-tabs nav-line-tabs nav-line-tabs-2x fs-6 fw-semibold mb-6">
            <li v-for="t in abas" :key="t.id" class="nav-item">
                <a href="#" class="nav-link d-flex align-items-center gap-2" :class="{ active: aba === t.id }" @click.prevent="aba = t.id">
                    {{ t.nome }}
                    <span v-if="t.total !== undefined" class="badge badge-light-primary">{{ t.total }}</span>
                    <span v-if="t.alerta" class="badge badge-circle badge-warning w-10px h-10px p-0" title="Há classes sem regra"></span>
                </a>
            </li>
            <li class="nav-item ms-auto align-self-center pb-2">
                <button type="button" class="btn btn-sm btn-primary" @click="abrirNova">+ Nova regra</button>
            </li>
        </ul>

        <!-- ============ REGRAS ============ -->
        <div v-show="aba === 'regras'">
            <div v-if="semRegra.length" class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div><strong>Classes sem regra:</strong> {{ semRegra.map((n) => n.nome).join(', ') }}. Enquanto não seguirem uma regra, não se lançam notas nelas.</div>
                <button type="button" class="btn btn-sm btn-primary" @click="criarRegrasPorDefeito">Criar as regras por defeito em falta</button>
            </div>

            <div class="text-muted fs-7 mb-5">Cada cartão é uma regra independente. Clique num cartão para o ver, ou em «Editar» para o alterar, ou use o botão «+ Nova regra» para criar outra. Uma classe segue uma só regra: para a mudar, tire-a primeiro da regra atual.</div>

            <div class="row g-6 mb-6">
                <div v-for="r in regras" :key="chave(r)" class="col-md-6 col-xl-4">
                    <RegraCartao :regra="r" :niveis="niveis" :escalas="escalas" :progressoes="progressoes" @ver="abrirVer(r)" @editar="abrirEditar(r)" @eliminar="eliminarDaLista(r)" />
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
                                        <SelectSolid v-model="prolongamentos[prazoDe(p.id, t.valor).id].turma_id" :options="opcoesTurmas" searchable pequeno class="w-100" />
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
                <div v-if="!escalas.length && !editor" class="text-muted">Ainda não há escalas. Crie uma para poder avaliar por níveis.</div>
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
                    <SelectSolid v-model="compDisciplina" :options="opcoesAreas" searchable pequeno placeholder="Escolha a área" class="w-250px" @update:model-value="nextTick(carregarCompetencias)" />
                    <SelectSolid v-model="compNivel" :options="opcoesClassesQualitativas" pequeno class="w-250px" @update:model-value="nextTick(carregarCompetencias)" />
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
            :regra="modal.regra" :nova="modal.nova" :editar="modal.editar" :niveis="niveis" :regras="regras" :escalas="escalas" :progressoes="progressoes" :modos="modos" :disciplinas="disciplinas"
            :processando="modal.processando" :erros="modal.erros" @guardar="guardarRegra" @eliminar="eliminarRegra" @fechar="fecharModal"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar regra"
            :mensagem="mensagemEliminar"
            :processando="aEliminar"
            @confirmar="confirmarEliminar"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
