<script setup>
import { computed } from 'vue';

// O cartão de uma regra, como aparece na lista e na pré-visualização do separador «Nova regra».
const props = defineProps({
    regra: { type: Object, required: true }, // { nome, qualitativa, nivel_ids, escala_maxima, nota_minima, peso_mac, peso_pt, com_exame, com_recurso, escala_id, progressao… }
    niveis: { type: Array, required: true },
    escalas: { type: Array, default: () => [] },
    progressoes: { type: Array, default: () => [] },
    clicavel: { type: Boolean, default: true },
});
const emit = defineEmits(['ver', 'editar', 'eliminar']);
const r = computed(() => props.regra); // no modelo, `r` já chega desembrulhado

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
</script>

<template>
    <div class="card h-100" :class="{ 'border-hover-primary cursor-pointer': clicavel }" :role="clicavel ? 'button' : undefined" :tabindex="clicavel ? 0 : undefined" @click="clicavel && emit('ver')" @keyup.enter="clicavel && emit('ver')">
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
                <span v-if="r.tratamento_negativas === 1" class="badge badge-light">Negativas tratadas</span>
                <span v-if="!r.com_exame && !r.com_recurso && r.tratamento_negativas === 0" class="badge badge-light">Média final decide</span>
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
                <div v-if="clicavel" class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-light" @click.stop="emit('ver')">Ver</button>
                        <button type="button" class="btn btn-sm btn-light-primary" @click.stop="emit('editar')">Editar</button>
                        <button v-if="!r.em_uso" type="button" class="btn btn-sm btn-icon btn-light-danger" title="Esta regra ainda não foi usada: pode ser eliminada" @click.stop="emit('eliminar')">✕</button>
                    </div>
            </div>
        </div>
    </div>
</template>
