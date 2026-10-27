<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';

/**
 * Mapa de calor do mês: cada dia é colorido pelo estado dos vencimentos (verde pago, verde-água pago depois do vencimento, âmbar parcial, vermelho por
 * receber em dia já passado, cinzento a vencer) e a intensidade segue o valor emitido nesse dia.
 */
const props = defineProps({
    calendario: { type: Object, required: true }, // { mes: 'YYYY-MM', dias: [{ dia, quantidade, emitido, recebido, tardias, dias_de_atraso }] }
    formatar: { type: Function, required: true }, // unidades menores → texto com moeda
});

// O mês mostrado navega-se sem recarregar a página: cada mês pedido vem de /calendario-vencimentos.
const actual = ref(props.calendario);
const mesInicial = actual.value.mes;
const aCarregar = ref(false);
const falhou = ref(false);

function deslocar(mes, delta) {
    const [ano, m] = mes.split('-').map(Number);
    const d = new Date(ano, m - 1 + delta, 1);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

async function irParaMes(mes) {
    if (aCarregar.value) return;
    aCarregar.value = true;
    falhou.value = false;

    try {
        const { data } = await axios.get('/calendario-vencimentos', { params: { mes } });
        actual.value = data;
    } catch {
        falhou.value = true;
    } finally {
        aCarregar.value = false;
    }
}

const SEMANA = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const ROTULOS = { recebido: 'Pago', tardio: 'Pago c/ atraso', parcial: 'Parcial', atraso: 'Em atraso', avencer: 'A vencer' };
const CORES = { recebido: '22, 163, 74', tardio: '13, 148, 136', parcial: '245, 158, 11', atraso: '239, 68, 68', avencer: '100, 116, 139' };

const hoje = computed(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
});

const titulo = computed(() => new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(new Date(`${actual.value.mes}-01T00:00:00`)));

const celulas = computed(() => {
    const [ano, mes] = actual.value.mes.split('-').map(Number);
    const total = new Date(ano, mes, 0).getDate();
    const inicio = (new Date(ano, mes - 1, 1).getDay() + 6) % 7; // segunda = 0
    const porDia = new Map(actual.value.dias.map((d) => [d.dia, d]));
    const maior = Math.max(1, ...actual.value.dias.map((d) => d.emitido));
    const lista = Array.from({ length: inicio }, () => null);

    for (let n = 1; n <= total; n++) {
        const dia = `${actual.value.mes}-${String(n).padStart(2, '0')}`;
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

// Resumo do mês por estado (propinas e valor por receber/emitido): dá leitura rápida sem ter de passar o rato dia a dia.
const resumo = computed(() => {
    const porEstado = {};
    let emitido = 0;
    let recebido = 0;

    for (const c of celulas.value) {
        if (!c?.dados) continue;
        const e = (porEstado[c.estado] ??= { quantidade: 0, valor: 0 });
        e.quantidade += c.dados.quantidade;
        e.valor += c.estado === 'recebido' || c.estado === 'tardio' ? c.dados.recebido : c.porReceber;
        emitido += c.dados.emitido;
        recebido += c.dados.recebido;
    }

    return { estados: Object.keys(ROTULOS).filter((k) => porEstado[k]).map((k) => ({ chave: k, ...porEstado[k] })), emitido, recebido };
});

const noMesInicial = computed(() => actual.value.mes === mesInicial);
const link = (dia) => `/financeiro/propinas?vencimento_de=${dia}&vencimento_ate=${dia}`;
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <span class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-icon btn-sm btn-light" :disabled="aCarregar" aria-label="Mês anterior" @click="irParaMes(deslocar(actual.mes, -1))">
                    <i class="ki-duotone ki-arrow-left fs-4"><span class="path1"></span><span class="path2"></span></i>
                </button>
                <span class="fw-bold fs-6 text-center titulo-mes" style="min-width: 130px">{{ titulo }}</span>
                <button type="button" class="btn btn-icon btn-sm btn-light" :disabled="aCarregar" aria-label="Mês seguinte" @click="irParaMes(deslocar(actual.mes, 1))">
                    <i class="ki-duotone ki-arrow-right fs-4"><span class="path1"></span><span class="path2"></span></i>
                </button>
                <button v-if="!noMesInicial" type="button" class="btn btn-sm btn-light" :disabled="aCarregar" @click="irParaMes(mesInicial)">Mês actual</button>
                <span v-if="falhou" class="text-danger fs-8">Não foi possível carregar este mês.</span>
            </span>
            <span class="d-flex flex-wrap gap-4 fs-8 text-muted">
                <span v-for="(rotulo, chave) in ROTULOS" :key="chave" class="d-flex align-items-center gap-1">
                    <span class="d-inline-block rounded-1" style="width: 10px; height: 10px" :style="{ background: `rgb(${CORES[chave]})` }"></span>{{ rotulo }}
                </span>
            </span>
        </div>

        <div v-if="resumo.estados.length" class="d-flex flex-wrap gap-3 mb-3">
            <span v-for="e in resumo.estados" :key="e.chave" class="d-inline-flex align-items-center gap-2 rounded-2 px-3 py-2 fs-8 bg-body-secondary">
                <span class="d-inline-block rounded-circle" style="width: 8px; height: 8px" :style="{ background: `rgb(${CORES[e.chave]})` }"></span>
                <span class="fw-semibold text-gray-800">{{ ROTULOS[e.chave] }}</span>
                <span class="text-muted">{{ e.quantidade }} {{ e.quantidade === 1 ? 'propina' : 'propinas' }} · {{ formatar(e.valor) }}</span>
            </span>
            <span class="d-inline-flex align-items-center gap-2 px-3 py-2 fs-8 text-muted">Emitido {{ formatar(resumo.emitido) }} · Recebido {{ formatar(resumo.recebido) }}</span>
        </div>
        <div v-else class="text-muted fs-8 mb-3">Sem vencimentos neste mês.</div>

        <div class="text-muted fs-8 mb-3">Por data de vencimento da propina, não da data do pagamento. Uma propina paga depois do vencimento continua no dia em que venceu.</div>

        <div class="d-grid gap-1" :style="{ opacity: aCarregar ? 0.5 : 1 }" style="grid-template-columns: repeat(7, minmax(0, 1fr))">
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
                        <div class="fw-bold mb-1 titulo-mes">{{ c.titulo }}</div>
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
.titulo-mes::first-letter { text-transform: uppercase; }
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
