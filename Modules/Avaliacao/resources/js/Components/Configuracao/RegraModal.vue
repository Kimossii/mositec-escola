<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import RegraParametros from './RegraParametros.vue';

// A janela de uma regra de avaliação, para criar ou editar: o essencial à vista e o resto em «Mais opções».
const props = defineProps({
    regra: { type: Object, default: null }, // a regra a editar
    nova: { type: Boolean, default: false }, // criar uma regra nova
    editar: { type: Boolean, default: false }, // abrir uma regra já em edição (senão abre só para ver)
    niveis: { type: Array, required: true }, // [{ id, nome, qualitativo, sem_regra }]
    regras: { type: Array, required: true }, // todas as regras do ano (para dizer de onde passa cada classe e para «Partir de»)
    escalas: { type: Array, default: () => [] },
    progressoes: { type: Array, default: () => [] },
    modos: { type: Array, default: () => [] },
    disciplinas: { type: Array, default: () => [] },
    processando: { type: Boolean, default: false },
    erros: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['guardar', 'eliminar', 'fechar']);

const BASE_NOTAS = () => ({ nome: '', escala_maxima: 10, nota_minima: '5', peso_mac: '50', peso_pt: '50', sigla_prova: 'PT', peso_mt1: null, peso_mt2: null, peso_mt3: null, peso_mfd_exame: '40', peso_exame: '60',
    com_exame: false, com_recurso: false, exame_usa_mfd_arredondada: true, recurso_max_disciplinas: null, disciplinas_chave: [], casas_decimais: 2, modo_arredondamento: 0, reprova_por_aproveitamento: true, nivel_ids: [] });
const BASE_QUALITATIVA = () => ({ nome: '', escala_id: '', progressao: 0, nivel_ids: [] });

const aberta = computed(() => props.regra !== null || props.nova);
const criar = computed(() => props.regra === null);
const f = reactive({});
const qualitativa = ref(false);
const copiarDe = ref('');
const avancado = ref(false);
const somenteVer = ref(false); // ver não altera nada: «Editar» passa para o formulário
const janela = ref(null);

function preencher(ehQualitativa) {
    qualitativa.value = ehQualitativa;
    copiarDe.value = '';
    Object.keys(f).forEach((k) => delete f[k]);
    Object.assign(f, ehQualitativa ? BASE_QUALITATIVA() : BASE_NOTAS());
}

// Cada vez que a janela abre, parte-se de uma cópia: cancelar não deixa rasto.
watch(aberta, async (a) => {
    if (!a) return;
    avancado.value = false;
    somenteVer.value = props.regra !== null && !props.editar;
    if (props.regra) {
        qualitativa.value = props.regra.qualitativa;
        Object.keys(f).forEach((k) => delete f[k]);
        Object.assign(f, JSON.parse(JSON.stringify(props.regra)));
        if (qualitativa.value) f.escala_id = f.escala_id ?? '';
    } else {
        preencher(false);
    }
    await nextTick();
    janela.value?.focus();
}, { immediate: true });

// «Partir de»: copia os parâmetros de uma regra existente (não o nome nem as classes).
watch(copiarDe, (id) => {
    if (!criar.value) return;
    const origem = props.regras.find((r) => r.id === id && r.qualitativa === qualitativa.value);
    const { nome, nivel_ids } = f;
    Object.keys(f).forEach((k) => delete f[k]);
    Object.assign(f, qualitativa.value ? BASE_QUALITATIVA() : BASE_NOTAS(), origem ? JSON.parse(JSON.stringify(origem)) : {}, { nome, nivel_ids });
    if (qualitativa.value) f.escala_id = f.escala_id ?? '';
});

const nomeDaClasse = (id) => props.niveis.find((n) => n.id === id)?.nome ?? id;
// Só se juntam classes do mesmo tipo (qualitativas ou com notas) que ainda não seguem nenhuma regra: uma classe só pode
// estar numa regra, por isso as que já têm uma não aparecem (tira-se primeiro da regra atual).
const juntaveis = computed(() => props.niveis.filter((n) => n.qualitativo === qualitativa.value && n.sem_regra && !f.nivel_ids?.includes(n.id)));
const rotuloJuntavel = (n) => n.nome;
const juntar = (id) => { if (id && !f.nivel_ids.includes(id)) f.nivel_ids.push(id); };
const tirar = (id) => { f.nivel_ids = f.nivel_ids.filter((i) => i !== id); };
const paraJuntar = ref('');
watch(paraJuntar, (id) => { if (id !== '') { juntar(id); paraJuntar.value = ''; } });
const opcoesJuntaveis = computed(() => juntaveis.value.map((n) => ({ value: n.id, label: rotuloJuntavel(n) })));

const opcoesOrigem = computed(() => [{ value: '', label: 'Em branco' }, ...props.regras.filter((r) => r.qualitativa === qualitativa.value).map((r) => ({ value: r.id, label: r.nome }))]);
const opcoesEscala = computed(() => [{ value: '', label: 'Escolha a escala' }, ...props.escalas.map((e) => ({ value: e.id, label: e.nome }))]);
const opcoesProgressao = computed(() => props.progressoes.map((p) => ({ value: p.valor, label: p.nome })));

const mensagens = computed(() => [...new Set(Object.values(props.erros ?? {}).flat())]);
const podeEliminar = computed(() => !criar.value && !props.regra?.em_uso);
const nomeDoModo = (v) => props.modos.find((m) => m.valor === v)?.nome ?? '';
const nomeDaEscala = (id) => props.escalas.find((e) => e.id === id)?.nome ?? 'Por escolher';
const nomeDaProgressao = (v) => props.progressoes.find((p) => p.valor === v)?.nome ?? '';
const nomesDasChaves = computed(() => (props.regra?.disciplinas_chave ?? []).map((id) => props.disciplinas.find((d) => d.id === id)?.nome).filter(Boolean));
const podeGuardar = computed(() => !!f.nome?.trim() && (!criar.value || f.nivel_ids?.length > 0));
</script>

<template>
    <div v-if="aberta" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div ref="janela" tabindex="-1" class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg" @keydown.esc="emit('fechar')">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title fw-bold">{{ criar ? 'Nova regra' : (somenteVer ? 'Regra' : 'Editar regra') }}</h3>
                    <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" aria-label="Fechar" @click="emit('fechar')">✕</button>
                </div>

                <div class="modal-body">
                    <!-- ver: só leitura -->
                    <div v-if="somenteVer" class="mb-2">
                        <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
                            <span class="fs-3 fw-bold">{{ regra.nome }}</span>
                            <span class="badge" :class="regra.qualitativa ? 'badge-light-info' : 'badge-light-primary'">{{ regra.qualitativa ? 'Qualitativa' : 'Com notas' }}</span>
                            <span v-if="regra.em_uso" class="badge badge-light-warning">Em uso</span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-6">
                            <span v-for="id in regra.nivel_ids" :key="id" class="badge badge-light-success fs-7 py-2">{{ nomeDaClasse(id) }}</span>
                            <span v-if="!regra.nivel_ids.length" class="text-muted fs-7">Nenhuma classe ligada.</span>
                        </div>
                        <div v-if="regra.qualitativa" class="row g-3">
                            <div class="col-6"><div class="border border-dashed rounded p-4"><div class="fw-bold">{{ nomeDaEscala(regra.escala_id) }}</div><div class="fs-8 text-muted">Escala</div></div></div>
                            <div class="col-6"><div class="border border-dashed rounded p-4"><div class="fw-bold">{{ nomeDaProgressao(regra.progressao) }}</div><div class="fs-8 text-muted">Progressão</div></div></div>
                        </div>
                        <div v-else class="row g-3">
                            <div class="col-6 col-md-3"><div class="border border-dashed rounded p-4 h-100"><div class="fw-bold">0–{{ regra.escala_maxima }}</div><div class="fs-8 text-muted">Escala</div></div></div>
                            <div class="col-6 col-md-3"><div class="border border-dashed rounded p-4 h-100"><div class="fw-bold">{{ regra.nota_minima }}</div><div class="fs-8 text-muted">Nota mínima</div></div></div>
                            <div class="col-6 col-md-3"><div class="border border-dashed rounded p-4 h-100"><div class="fw-bold">{{ Number(regra.peso_mac) }}% · {{ Number(regra.peso_pt) }}%</div><div class="fs-8 text-muted">MAC · {{ regra.sigla_prova }}</div></div></div>
                            <div class="col-6 col-md-3"><div class="border border-dashed rounded p-4 h-100"><div class="fw-bold">{{ regra.reprova_por_aproveitamento ? 'Sim' : 'Não' }}</div><div class="fs-8 text-muted">Reprova por aproveitamento</div></div></div>
                            <div class="col-6 col-md-3"><div class="border border-dashed rounded p-4 h-100"><div class="fw-bold">{{ regra.casas_decimais }} casas</div><div class="fs-8 text-muted">{{ nomeDoModo(regra.modo_arredondamento) }}</div></div></div>
                            <div class="col-6 col-md-3"><div class="border border-dashed rounded p-4 h-100"><div class="fw-bold">{{ regra.peso_mt1 === null ? 'Iguais' : `${Number(regra.peso_mt1)}% · ${Number(regra.peso_mt2)}% · ${Number(regra.peso_mt3)}%` }}</div><div class="fs-8 text-muted">Pesos dos trimestres</div></div></div>
                            <div class="col-12 col-md-6"><div class="border border-dashed rounded p-4 h-100"><div class="fw-bold">{{ regra.com_exame ? `Sim: MF = MFD × ${Number(regra.peso_mfd_exame)}% + NE × ${Number(regra.peso_exame)}%` : 'Não' }}</div><div class="fs-8 text-muted">Exame próprio</div></div></div>
                            <div class="col-12 col-md-6"><div class="border border-dashed rounded p-4 h-100">
                                <div class="fw-bold">{{ regra.com_recurso ? 'Sim' : 'Não' }}</div>
                                <div v-if="regra.com_recurso" class="fs-8">Máximo de negativas: {{ regra.recurso_max_disciplinas ?? 'sem máximo' }}<span v-if="nomesDasChaves.length"> · disciplinas-chave: {{ nomesDasChaves.join(', ') }}</span></div>
                                <div class="fs-8 text-muted">Exame de recurso</div>
                            </div></div>
                        </div>
                    </div>

                    <div v-if="!somenteVer && mensagens.length" class="alert alert-danger py-3">
                        <div v-for="m in mensagens" :key="m">{{ m }}</div>
                    </div>

                    <div v-if="!somenteVer">
                    <!-- tipo (só ao criar) -->
                    <div v-if="criar" class="d-flex flex-wrap gap-3 mb-6">
                        <button type="button" class="btn d-flex align-items-center gap-3 px-6 py-3" :class="!qualitativa ? 'btn-primary' : 'btn-outline btn-outline-dashed btn-color-dark'" @click="preencher(false)">
                            <span class="fw-bold">Com notas</span>
                        </button>
                        <button type="button" class="btn d-flex align-items-center gap-3 px-6 py-3" :class="qualitativa ? 'btn-primary' : 'btn-outline btn-outline-dashed btn-color-dark'" @click="preencher(true)">
                            <span class="fw-bold">Qualitativa</span>
                        </button>
                    </div>

                    <div class="row g-4 mb-5">
                        <div class="col-12" :class="criar && opcoesOrigem.length > 1 ? 'col-md-7' : ''">
                            <label class="required form-label fs-7 fw-bold text-muted text-uppercase">Nome da regra</label>
                            <input v-model="f.nome" type="text" class="form-control" maxlength="120" placeholder="Nome da regra">
                        </div>
                        <div v-if="criar && opcoesOrigem.length > 1" class="col-12 col-md-5">
                            <label class="form-label fs-7 fw-bold text-muted text-uppercase">Partir de</label>
                            <SelectSolid v-model="copiarDe" :options="opcoesOrigem" />
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label fs-7 fw-bold text-muted text-uppercase">Classes que seguem esta regra</label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span v-for="id in f.nivel_ids" :key="id" class="badge badge-light-success fs-7 d-inline-flex align-items-center gap-2 py-2">
                                {{ nomeDaClasse(id) }}<a href="#" class="text-danger" title="Tirar da regra" @click.prevent="tirar(id)">✕</a>
                            </span>
                            <span v-if="!f.nivel_ids?.length" class="text-muted fs-7">Nenhuma classe ligada.</span>
                        </div>
                        <SelectSolid v-if="juntaveis.length" v-model="paraJuntar" :options="opcoesJuntaveis" searchable pequeno placeholder="+ Juntar uma classe…" class="w-350px mw-100 mt-3" />
                        <div class="text-muted fs-8 mt-2">{{ juntaveis.length ? 'Só aparecem as classes que ainda não têm regra.' : 'Não há mais classes sem regra para juntar.' }} Uma classe segue uma só regra: para a mudar, tire-a primeiro da regra atual.</div>
                    </div>

                    <!-- o essencial -->
                    <div v-if="qualitativa" class="row g-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label fs-7 fw-bold text-muted text-uppercase">Escala</label>
                            <SelectSolid v-model="f.escala_id" :options="opcoesEscala" placeholder="Escolha a escala" />
                            <div v-if="!escalas.length" class="text-muted fs-8 mt-1">Ainda não há escalas: crie uma em «Escalas e competências».</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fs-7 fw-bold text-muted text-uppercase">Progressão</label>
                            <SelectSolid v-model="f.progressao" :options="opcoesProgressao" />
                        </div>
                    </div>
                    <template v-else>
                        <div class="row g-4 mb-5">
                            <div class="col-6 col-md-3"><label class="form-label fs-7 fw-bold text-muted text-uppercase">Escala máx.</label><input v-model="f.escala_maxima" type="number" class="form-control"></div>
                            <div class="col-6 col-md-3"><label class="form-label fs-7 fw-bold text-muted text-uppercase">Nota mínima</label><input v-model="f.nota_minima" type="text" class="form-control"></div>
                            <div class="col-6 col-md-3"><label class="form-label fs-7 fw-bold text-muted text-uppercase">Peso MAC %</label><input v-model="f.peso_mac" type="text" class="form-control"></div>
                            <div class="col-6 col-md-3"><label class="form-label fs-7 fw-bold text-muted text-uppercase">Peso prova %</label><input v-model="f.peso_pt" type="text" class="form-control"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-8 mb-5">
                            <label class="form-check form-switch form-check-custom"><input v-model="f.com_exame" type="checkbox" class="form-check-input"><span class="form-check-label">Exame próprio</span></label>
                            <label class="form-check form-switch form-check-custom"><input v-model="f.com_recurso" type="checkbox" class="form-check-input"><span class="form-check-label">Exame de recurso</span></label>
                            <label class="form-check form-switch form-check-custom"><input v-model="f.reprova_por_aproveitamento" type="checkbox" class="form-check-input"><span class="form-check-label">Reprova por aproveitamento</span></label>
                        </div>

                        <a href="#" class="fs-7 fw-semibold" @click.prevent="avancado = !avancado">{{ avancado ? '▾ Menos opções' : '▸ Mais opções (arredondamento, pesos dos trimestres, exame e recurso em detalhe…)' }}</a>
                        <div v-if="avancado" class="mt-4">
                            <RegraParametros :model-value="f" :qualitativa="false" :escalas="escalas" :progressoes="progressoes" :modos="modos" :disciplinas="disciplinas" ocultar-essenciais />
                        </div>
                    </template>
                    </div>
                </div>

                <div class="modal-footer justify-content-between">
                    <div>
                        <button v-if="podeEliminar" type="button" class="btn btn-light-danger" :disabled="processando" @click="emit('eliminar')">Eliminar regra</button>
                    </div>
                    <div v-if="somenteVer" class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-light" @click="emit('fechar')">Fechar</button>
                        <button type="button" class="btn btn-primary" @click="somenteVer = false">Editar</button>
                    </div>
                    <div v-else class="d-flex align-items-center gap-2">
                        <span v-if="criar && !podeGuardar" class="text-muted fs-8 me-2">{{ !f.nome?.trim() ? 'Dê um nome à regra.' : 'Escolha pelo menos uma classe.' }}</span>
                        <button type="button" class="btn btn-light" :disabled="processando" @click="emit('fechar')">Cancelar</button>
                        <button type="button" class="btn btn-primary" :disabled="processando || !podeGuardar" @click="emit('guardar', f, qualitativa)">{{ processando ? 'A guardar…' : (criar ? 'Criar regra' : 'Guardar regra') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
