<script setup>
import { computed } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

// Os parâmetros de uma regra (o que muda entre uma regra com notas e uma qualitativa): usados na janela de edição e no assistente de nova regra.
const f = defineModel({ type: Object, required: true });
const props = defineProps({
    qualitativa: { type: Boolean, default: false },
    escalas: { type: Array, default: () => [] },
    progressoes: { type: Array, default: () => [] },
    modos: { type: Array, default: () => [] },
    disciplinas: { type: Array, default: () => [] },
    ocultarEssenciais: { type: Boolean, default: false }, // esconde o que o formulário compacto já mostra (escala, nota mínima, pesos e interruptores)
});

const alternarChave = (id) => { f.value.disciplinas_chave = f.value.disciplinas_chave.includes(id) ? f.value.disciplinas_chave.filter((d) => d !== id) : [...f.value.disciplinas_chave, id]; };
const opcoesEscala = computed(() => [{ value: '', label: 'Escolha a escala' }, ...props.escalas.map((e) => ({ value: e.id, label: e.nome }))]);
const opcoesProgressao = computed(() => props.progressoes.map((p) => ({ value: p.valor, label: p.nome })));
const opcoesModo = computed(() => props.modos.map((m) => ({ value: m.valor, label: m.nome })));
</script>

<template>
        <!-- qualitativa -->
        <div v-if="qualitativa" class="bg-body-secondary rounded p-5">
            <div v-if="!escalas.length" class="text-muted">Ainda não há escalas. Crie uma no separador «Escalas e competências» e volte aqui.</div>
            <div v-else class="row g-4">
                <div class="col-12 col-md-6">
                    <label class="form-label fs-7 fw-bold text-muted text-uppercase">Escala</label>
                    <SelectSolid v-model="f.escala_id" :options="opcoesEscala" placeholder="Escolha a escala" />
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fs-7 fw-bold text-muted text-uppercase">Progressão</label>
                    <SelectSolid v-model="f.progressao" :options="opcoesProgressao" />
                </div>
            </div>
        </div>

        <!-- com notas -->
        <div v-else class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="bg-body-secondary rounded p-5 h-100">
                    <div class="fw-bold mb-3">Escala e aprovação</div>
                    <div class="row g-3">
                        <div v-if="!ocultarEssenciais" class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Escala máxima</label><input v-model="f.escala_maxima" type="number" class="form-control"></div>
                        <div v-if="!ocultarEssenciais" class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Nota mínima</label><input v-model="f.nota_minima" type="text" class="form-control"></div>
                        <div class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Casas decimais</label><input v-model="f.casas_decimais" type="number" min="0" max="4" class="form-control"></div>
                        <div class="col-6">
                            <label class="form-label fs-8 fw-bold text-muted text-uppercase">Arredondamento</label>
                            <SelectSolid v-model="f.modo_arredondamento" :options="opcoesModo" />
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
                        <div v-if="!ocultarEssenciais" class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso MAC %</label><input v-model="f.peso_mac" type="text" class="form-control"></div>
                        <div v-if="!ocultarEssenciais" class="col-6"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso da prova %</label><input v-model="f.peso_pt" type="text" class="form-control"></div>
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

            <div v-if="!ocultarEssenciais || f.com_exame" class="col-12 col-lg-6">
                <div class="bg-body-secondary rounded p-5 h-100">
                    <label v-if="!ocultarEssenciais" class="form-check form-switch form-check-custom mb-3">
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
            <div v-if="!ocultarEssenciais || f.com_recurso" class="col-12 col-lg-6">
                <div class="bg-body-secondary rounded p-5 h-100">
                    <label v-if="!ocultarEssenciais" class="form-check form-switch form-check-custom mb-3">
                        <input v-model="f.com_recurso" type="checkbox" class="form-check-input">
                        <span class="form-check-label fw-bold">Exame de recurso</span>
                    </label>
                    <template v-if="f.com_recurso">
                        <template v-if="!f.com_exame">
                        <div class="text-muted fs-8 mb-2">MFR = MF × {{ Number(f.peso_mfd_exame) || 0 }}% + NER × {{ Number(f.peso_exame) || 0 }}% (os pesos do recurso)</div>
                        <div class="d-flex gap-2 mb-4">
                            <input v-model="f.peso_mfd_exame" type="text" class="form-control" placeholder="Peso da nota final %">
                            <input v-model="f.peso_exame" type="text" class="form-control" placeholder="Peso do recurso %">
                        </div>
                    </template>
                    <label class="form-label fs-8 fw-bold text-muted text-uppercase">Máximo de disciplinas negativas que ainda dão direito a recurso (vazio = sem máximo)</label>
                        <input v-model="f.recurso_max_disciplinas" type="number" min="0" class="form-control w-150px mb-3">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Disciplinas-chave: negativa a uma delas = reprovação directa, sem recurso</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button v-for="d in disciplinas" :key="d.id" type="button" class="btn btn-sm" :class="f.disciplinas_chave.includes(d.id) ? 'btn-primary' : 'btn-light'" @click="alternarChave(d.id)">{{ d.nome }}</button>
                        </div>
                        <div class="text-muted fs-8 mt-2">Exemplo: máximo 2 → com 3 negativas o aluno reprova sem recurso; com 1 ou 2 (e nenhuma a disciplina-chave) pode ir ao recurso nessas disciplinas. Máximo 0 = ninguém vai ao recurso. Negativa a uma disciplina-chave reprova logo, sem recurso.</div>
                    </template>
                    <div v-else class="text-muted fs-8">Sem recurso: quem fica abaixo do mínimo reprova.</div>
                </div>
            </div>
        </div>
</template>
