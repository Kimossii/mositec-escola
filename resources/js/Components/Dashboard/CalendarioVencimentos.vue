<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Mapa de calor do mês: cada dia é colorido pelo estado dos vencimentos (verde pago, verde-água pago depois do vencimento, âmbar parcial, vermelho por
 * receber em dia já passado, cinzento a vencer) e a intensidade segue o valor emitido nesse dia.
 */
const props = defineProps({
    calendario: { type: Object, required: true }, // { mes: 'YYYY-MM', dias: [{ dia, quantidade, emitido, recebido, tardias, dias_de_atraso }] }
    formatar: { type: Function, required: true }, // unidades menores → texto com moeda
});

const SEMANA = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const ROTULOS = { recebido: 'Pago', tardio: 'Pago c/ atraso', parcial: 'Parcial', atraso: 'Em atraso', avencer: 'A vencer' };
const CORES = { recebido: '22, 163, 74', tardio: '13, 148, 136', parcial: '245, 158, 11', atraso: '239, 68, 68', avencer: '100, 116, 139' };

const hoje = computed(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
});

const titulo = computed(() => new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(new Date(`${props.calendario.mes}-01T00:00:00`)));

const celulas = computed(() => {
    const [ano, mes] = props.calendario.mes.split('-').map(Number);
    const total = new Date(ano, mes, 0).getDate();
    const inicio = (new Date(ano, mes - 1, 1).getDay() + 6) % 7; // segunda = 0
    const porDia = new Map(props.calendario.dias.map((d) => [d.dia, d]));
    const maior = Math.max(1, ...props.calendario.dias.map((d) => d.emitido));
    const lista = Array.from({ length: inicio }, () => null);

    for (let n = 1; n <= total; n++) {
        const dia = `${props.calendario.mes}-${String(n).padStart(2, '0')}`;
        const dados = porDia.get(dia);
        let estado = null;

        if (dados) {
            if (dados.recebido >= dados.emitido) estado = dados.tardias > 0 ? 'tardio' : 'recebido';
            else if (dia >= hoje.value) estado = 'avencer';
            else estado = dados.recebido > 0 ? 'parcial' : 'atraso';
        }

        const opacidade = dados ? 0.18 + 0.72 * (dados.emitido / maior) : 0;
        lista.push({
            n,
            dia,
            dados,
            estado,
            hoje: dia === hoje.value,
            fundo: estado ? `rgba(${CORES[estado]}, ${opacidade.toFixed(2)})` : undefined,
            titulo: new Intl.DateTimeFormat('pt-PT', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(`${dia}T00:00:00`)),
            porReceber: dados ? Math.max(0, dados.emitido - dados.recebido) : 0,
        });
    }

    return lista;
});

const link = (dia) => `/financeiro/propinas?vencimento_de=${dia}&vencimento_ate=${dia}`;
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <span class="fw-bold text-capitalize fs-6">{{ titulo }}</span>
            <span class="d-flex flex-wrap gap-4 fs-8 text-muted">
                <span v-for="(rotulo, chave) in ROTULOS" :key="chave" class="d-flex align-items-center gap-1">
                    <span class="d-inline-block rounded-1" style="width: 10px; height: 10px" :style="{ background: `rgb(${CORES[chave]})` }"></span>{{ rotulo }}
                </span>
            </span>
        </div>

        <div class="text-muted fs-8 mb-3">Por data de vencimento da propina, não da data do pagamento. Uma propina paga depois do vencimento continua no dia em que venceu.</div>

        <div class="d-grid gap-1" style="grid-template-columns: repeat(7, minmax(0, 1fr))">
            <div v-for="s in SEMANA" :key="s" class="text-center text-muted fs-8 fw-bold text-uppercase pb-1">{{ s }}</div>
            <template v-for="(c, i) in celulas" :key="i">
                <div v-if="!c"></div>
                <div v-else class="cal-dia position-relative">
                    <component
                        :is="c.dados ? Link : 'div'"
                        :href="c.dados ? link(c.dia) : undefined"
                        class="rounded-2 d-flex flex-column align-items-center justify-content-center py-2 text-center h-100"
                        :class="[c.hoje ? 'border border-2 border-gray-800' : 'border border-transparent', c.dados ? 'text-gray-900' : 'bg-body-secondary text-muted']"
                        :style="{ background: c.fundo, minHeight: '52px' }"
                    >
                        <span class="fw-bold fs-7 lh-1">{{ c.n }}</span>
                        <span v-if="c.estado" class="fs-9 fw-semibold lh-1 mt-1 d-none d-sm-block">{{ ROTULOS[c.estado] }}</span>
                    </component>

                    <div class="cal-dica card shadow-lg p-4 fs-7 text-gray-800" :class="i % 7 < 4 ? 'cal-esquerda' : 'cal-direita'">
                        <div class="fw-bold text-capitalize mb-1">{{ c.titulo }}</div>
                        <template v-if="c.dados">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="d-inline-block rounded-circle" style="width: 8px; height: 8px" :style="{ background: `rgb(${CORES[c.estado]})` }"></span>
                                <span class="fw-semibold">{{ ROTULOS[c.estado] }}</span>
                            </div>
                            <div class="d-flex justify-content-between gap-6"><span class="text-muted">Propinas</span><span>{{ c.dados.quantidade }}</span></div>
                            <div class="d-flex justify-content-between gap-6"><span class="text-muted">Emitido</span><span>{{ formatar(c.dados.emitido) }}</span></div>
                            <div class="d-flex justify-content-between gap-6"><span class="text-muted">Recebido</span><span>{{ formatar(c.dados.recebido) }}</span></div>
                            <div v-if="c.porReceber > 0" class="d-flex justify-content-between gap-6"><span class="text-muted">Por receber</span><span class="fw-bold text-danger">{{ formatar(c.porReceber) }}</span></div>
                            <div v-if="c.estado === 'tardio'" class="text-muted mt-2">Liquidado até {{ c.dados.dias_de_atraso }} dia(s) depois do vencimento.</div>
                            <div class="text-muted mt-2 fs-8">Clique para ver as propinas</div>
                        </template>
                        <div v-else class="text-muted">Sem vencimentos neste dia.</div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.cal-dica {
    display: none;
    position: absolute;
    bottom: calc(100% + 6px);
    z-index: 20;
    min-width: 230px;
    pointer-events: none;
}
.cal-esquerda { left: 0; }
.cal-direita { right: 0; }
.cal-dia:hover .cal-dica { display: block; }
.cal-dia:hover { z-index: 30; }
</style>
