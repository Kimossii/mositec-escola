<script setup>
import { computed } from 'vue';
import { Deferred } from '@inertiajs/vue3';

/**
 * Cartões de indicadores com carregamento diferido: esqueletos animados enquanto a prop `indicadores` não chega.
 * `montar(indicadores)` devolve os cartões: [{ rotulo, valor, nota?, cor: success|danger|primary|warning|info, progresso? }].
 */
const props = defineProps({
    indicadores: { type: Object, default: null },
    montar: { type: Function, required: true },
    quantidade: { type: Number, default: 4 },
});

const itens = computed(() => (props.indicadores ? props.montar(props.indicadores) : []));
const coluna = computed(() => (props.quantidade === 3 ? 'col-12 col-md-4' : 'col-12 col-sm-6 col-xl-3'));
</script>

<template>
    <Deferred data="indicadores">
        <template #fallback>
            <div class="row g-4 mb-6">
                <div v-for="n in quantidade" :key="n" :class="coluna">
                    <div class="card h-100"><div class="card-body py-5 placeholder-glow">
                        <span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span>
                    </div></div>
                </div>
            </div>
        </template>
        <div class="row g-4 mb-6">
            <div v-for="item in itens" :key="item.rotulo" :class="coluna">
                <div class="card h-100 border-start border-4" :class="`border-${item.cor}`"><div class="card-body py-5">
                    <div class="text-muted fs-8 fw-bold text-uppercase mb-1">{{ item.rotulo }}</div>
                    <div class="fs-2 fw-bolder lh-1" :class="`text-${item.cor}`">{{ item.valor }}</div>
                    <div v-if="item.progresso !== undefined" class="progress h-6px mt-3 mb-1"><div class="progress-bar" :class="`bg-${item.cor}`" :style="{ width: `${item.progresso}%` }"></div></div>
                    <div v-if="item.nota" class="text-muted fs-7" :class="item.progresso === undefined ? 'mt-3' : ''">{{ item.nota }}</div>
                </div></div>
            </div>
        </div>
    </Deferred>
</template>
