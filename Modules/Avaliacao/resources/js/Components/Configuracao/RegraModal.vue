<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';

// A janela para criar ou editar uma regra de avaliação: o nome, as classes que a seguem e os parâmetros.
const props = defineProps({
    regra: { type: Object, default: null }, // a regra a editar; `null` com `nova` = criar
    nova: { type: Object, default: null }, // { qualitativa } ao criar
    niveis: { type: Array, required: true }, // [{ id, nome, qualitativo, sem_regra }]
    regras: { type: Array, required: true }, // todas as regras do ano (para dizer de onde passa cada classe)
    escalas: { type: Array, default: () => [] },
    progressoes: { type: Array, default: () => [] },
    modos: { type: Array, default: () => [] },
    disciplinas: { type: Array, default: () => [] },
    processando: { type: Boolean, default: false },
    erros: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['guardar', 'eliminar', 'fechar']);

const aberta = computed(() => props.regra !== null || props.nova !== null);
const qualitativa = computed(() => (props.regra ? props.regra.qualitativa : !!props.nova?.qualitativa));
const criar = computed(() => props.regra === null);
const f = reactive({});
const janela = ref(null);

const VAZIA_NUMERICA = { nome: 'Nova regra', escala_maxima: 10, nota_minima: '5', peso_mac: '50', peso_pt: '50', sigla_prova: 'PT', peso_mt1: null, peso_mt2: null, peso_mt3: null, peso_mfd_exame: '40', peso_exame: '60',
    com_exame: false, com_recurso: false, recurso_max_disciplinas: null, disciplinas_chave: [], casas_decimais: 2, modo_arredondamento: 0, reprova_por_aproveitamento: true, nivel_ids: [] };
const VAZIA_QUALITATIVA = { nome: 'Nova regra qualitativa', escala_id: '', progressao: 0, nivel_ids: [] };

// Cada vez que a janela abre, parte-se de uma cópia: cancelar não deixa rasto.
watch(aberta, async (a) => {
    if (!a) return;
    Object.keys(f).forEach((k) => delete f[k]);
    const base = props.regra ?? (qualitativa.value ? VAZIA_QUALITATIVA : VAZIA_NUMERICA);
    Object.assign(f, JSON.parse(JSON.stringify(base)));
    if (qualitativa.value) f.escala_id = f.escala_id ?? '';
    await nextTick();
    janela.value?.focus();
}, { immediate: true });

const nomeDaClasse = (id) => props.niveis.find((n) => n.id === id)?.nome ?? id;
const regraDaClasse = (id) => props.regras.find((r) => r.nivel_ids.includes(id) && r.qualitativa === qualitativa.value && r.id !== props.regra?.id);
// Só se juntam classes do mesmo tipo (qualitativas ou com notas) que ainda não estão nesta regra.
const juntaveis = computed(() => props.niveis.filter((n) => n.qualitativo === qualitativa.value && !f.nivel_ids?.includes(n.id)));
const rotuloJuntavel = (n) => {
    const outra = regraDaClasse(n.id) ?? props.regras.find((r) => r.nivel_ids.includes(n.id) && r.id !== props.regra?.id);
    return outra ? `${n.nome} — passa de «${outra.nome}»` : `${n.nome} — sem regra`;
};
const juntar = (id) => { if (id && !f.nivel_ids.includes(id)) f.nivel_ids.push(id); };
const tirar = (id) => { f.nivel_ids = f.nivel_ids.filter((i) => i !== id); };
const alternarChave = (id) => { f.disciplinas_chave = f.disciplinas_chave.includes(id) ? f.disciplinas_chave.filter((d) => d !== id) : [...f.disciplinas_chave, id]; };

const mensagens = computed(() => [...new Set(Object.values(props.erros ?? {}).flat())]);
const podeEliminar = computed(() => !criar.value && !f.nivel_ids?.length);
const titulo = computed(() => (criar.value ? (qualitativa.value ? 'Nova regra qualitativa' : 'Nova regra com notas') : 'Editar regra'));
</script>

<template>
    <div v-if="aberta" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div ref="janela" tabindex="-1" class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl" @keydown.esc="emit('fechar')">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title fw-bold mb-1">{{ titulo }}</h3>
                        <div class="text-muted fs-7">
                            {{ qualitativa
                                ? 'Avalia por níveis (Creche e Pré-Escolar). As classes juntas passam a seguir esta regra.'
                                : 'Define como se calcula e decide a avaliação. As classes juntas passam a seguir esta regra.' }}
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" aria-label="Fechar" @click="emit('fechar')">✕</button>
                </div>

                <div class="modal-body">
                    <div v-if="mensagens.length" class="alert alert-danger py-3">
                        <div v-for="m in mensagens" :key="m">{{ m }}</div>
                    </div>

                    <div class="row g-5 mb-5">
                        <div class="col-12 col-lg-5">
                            <label class="required form-label fs-7 fw-bold text-muted text-uppercase">Nome da regra</label>
                            <input v-model="f.nome" type="text" class="form-control" maxlength="120" placeholder="ex.: 3.ª a 5.ª classe">
                        </div>
                        <div class="col-12 col-lg-7">
                            <label class="form-label fs-7 fw-bold text-muted text-uppercase">Classes que seguem esta regra</label>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span v-for="id in f.nivel_ids" :key="id" class="badge badge-light-success fs-7 d-inline-flex align-items-center gap-2 py-2">
                                    {{ nomeDaClasse(id) }}<a href="#" class="text-danger" title="Tirar da regra" @click.prevent="tirar(id)">✕</a>
                                </span>
                                <span v-if="!f.nivel_ids?.length" class="text-muted fs-7">Nenhuma classe ligada.</span>
                            </div>
                            <select v-if="juntaveis.length" class="form-select form-select-sm w-350px mw-100 mt-3" @change="juntar(Number($event.target.value)); $event.target.value = ''">
                                <option value="">+ Juntar uma classe…</option>
                                <option v-for="n in juntaveis" :key="n.id" :value="n.id">{{ rotuloJuntavel(n) }}</option>
                            </select>
                            <div class="text-muted fs-8 mt-2">
                                {{ qualitativa ? 'Esta regra só aceita classes da Creche e do Pré-Escolar.' : 'Esta regra só aceita classes avaliadas por notas (1.ª classe em diante).' }}
                                <span v-if="!juntaveis.length"> Já estão aqui todas as classes que aceita.</span>
                            </div>
                        </div>
                    </div>

                    <!-- qualitativa -->
                    <div v-if="qualitativa" class="bg-body-secondary rounded p-5">
                        <div v-if="!escalas.length" class="text-muted">Ainda não há escalas. Crie uma no separador «Escalas e competências» e volte aqui.</div>
                        <div v-else class="row g-4">
                            <div class="col-12 col-md-6">
                                <label class="form-label fs-7 fw-bold text-muted text-uppercase">Escala</label>
                                <select v-model="f.escala_id" class="form-select">
                                    <option value="">Escolha a escala</option>
                                    <option v-for="e in escalas" :key="e.id" :value="e.id">{{ e.nome }}</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fs-7 fw-bold text-muted text-uppercase">Progressão</label>
                                <select v-model="f.progressao" class="form-select">
                                    <option v-for="p in progressoes" :key="p.valor" :value="p.valor">{{ p.nome }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- com notas -->
                    <div v-else class="row g-4">
                        <div class="col-12 col-lg-6">
                            <div class="bg-body-secondary rounded p-5 h-100">
                                <div class="fw-bold mb-3">Escala e aprovação</div>
                                <div class="row g-3">
                                    <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Escala máxima</label><input v-model="f.escala_maxima" type="number" class="form-control"></div>
                                    <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Nota mínima</label><input v-model="f.nota_minima" type="text" class="form-control"></div>
                                    <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Casas decimais</label><input v-model="f.casas_decimais" type="number" min="0" max="4" class="form-control"></div>
                                    <div class="col-6">
                                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Arredondamento</label>
                                        <select v-model="f.modo_arredondamento" class="form-select"><option v-for="m in modos" :key="m.valor" :value="m.valor">{{ m.nome }}</option></select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-check form-switch form-check-custom">
                                            <input v-model="f.reprova_por_aproveitamento" type="checkbox" class="form-check-input">
                                            <span class="form-check-label">Reprova por aproveitamento</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="bg-body-secondary rounded p-5 h-100">
                                <div class="fw-bold mb-1">Como se calcula a média</div>
                                <div class="text-muted fs-8 mb-3">MT = MAC × {{ Number(f.peso_mac) || 0 }}% + PT × {{ Number(f.peso_pt) || 0 }}%</div>
                                <div class="row g-3">
                                    <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso MAC %</label><input v-model="f.peso_mac" type="text" class="form-control"></div>
                                    <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso da prova %</label><input v-model="f.peso_pt" type="text" class="form-control"></div>
                                    <div class="col-12">
                                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Sigla da prova trimestral nos documentos</label>
                                        <input v-model="f.sigla_prova" type="text" class="form-control w-150px" maxlength="10" placeholder="PT">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Pesos dos trimestres % (vazio = iguais)</label>
                                        <div class="d-flex gap-2">
                                            <input v-model="f.peso_mt1" type="text" class="form-control" placeholder="1.º">
                                            <input v-model="f.peso_mt2" type="text" class="form-control" placeholder="2.º">
                                            <input v-model="f.peso_mt3" type="text" class="form-control" placeholder="3.º">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="bg-body-secondary rounded p-5 h-100">
                                <label class="form-check form-switch form-check-custom mb-3">
                                    <input v-model="f.com_exame" type="checkbox" class="form-check-input">
                                    <span class="form-check-label fw-bold">Exame próprio</span>
                                </label>
                                <template v-if="f.com_exame">
                                    <div class="text-muted fs-8 mb-2">MF = MFD × {{ Number(f.peso_mfd_exame) || 0 }}% + NE × {{ Number(f.peso_exame) || 0 }}%</div>
                                    <div class="d-flex gap-2">
                                        <input v-model="f.peso_mfd_exame" type="text" class="form-control" placeholder="Peso da MFD %">
                                        <input v-model="f.peso_exame" type="text" class="form-control" placeholder="Peso do exame %">
                                    </div>
                                </template>
                                <div v-else class="text-muted fs-8">Sem exame próprio: a decisão sai da média final.</div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="bg-body-secondary rounded p-5 h-100">
                                <label class="form-check form-switch form-check-custom mb-3">
                                    <input v-model="f.com_recurso" type="checkbox" class="form-check-input">
                                    <span class="form-check-label fw-bold">Exame de recurso</span>
                                </label>
                                <template v-if="f.com_recurso">
                                    <label class="form-label fs-8 fw-bold text-muted text-uppercase">Máximo de disciplinas negativas (vazio = sem máximo)</label>
                                    <input v-model="f.recurso_max_disciplinas" type="number" min="0" class="form-control w-150px mb-3">
                                    <label class="form-label fs-8 fw-bold text-muted text-uppercase">Disciplinas-chave (negativa = reprovação directa)</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button v-for="d in disciplinas" :key="d.id" type="button" class="btn btn-sm" :class="f.disciplinas_chave.includes(d.id) ? 'btn-primary' : 'btn-light'" @click="alternarChave(d.id)">{{ d.nome }}</button>
                                    </div>
                                    <div class="text-muted fs-8 mt-2">Acima do máximo, ou com negativa a uma disciplina-chave, o aluno reprova sem recurso.</div>
                                </template>
                                <div v-else class="text-muted fs-8">Sem recurso: quem fica abaixo do mínimo reprova.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer justify-content-between">
                    <div>
                        <button v-if="podeEliminar" type="button" class="btn btn-light-danger" :disabled="processando" @click="emit('eliminar')">Eliminar regra</button>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" :disabled="processando" @click="emit('fechar')">Cancelar</button>
                        <button type="button" class="btn btn-primary" :disabled="processando || !f.nome?.trim()" @click="emit('guardar', f)">{{ processando ? 'A guardar…' : (criar ? 'Criar regra' : 'Guardar regra') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
