<script setup>
import { computed } from 'vue';
import { Deferred, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CalendarioVencimentos from '@/Components/Dashboard/CalendarioVencimentos.vue';
import GraficoApex from '@/Components/Shared/GraficoApex.vue';
import IndicadoresDiferidos from '@/Components/Shared/IndicadoresDiferidos.vue';
import { can } from '@/Composables/usePermissoes';
import { formatarDinheiro } from '../../../../Modules/Financeiro/resources/js/Support/dinheiro';

defineOptions({ layout: AppLayout });

const props = defineProps({
    moeda: { type: Object, required: true },
    indicadores: { type: Object, default: null }, // diferido: { cartoes }
    atencao: { type: Array, default: null }, // diferido
    resumo: { type: Array, default: null }, // diferido: frases com **destaque**
    risco: { type: Object, default: null }, // diferido: { total, valor, alunos }
    previsao: { type: Object, default: null }, // diferido: previsão de caixa a 30 dias
    calendario: { type: Object, default: null }, // diferido: vencimentos do mês
    graficos: { type: Object, default: null }, // diferido: só as chaves a que há acesso
    ultimosPagamentos: { type: Array, default: null }, // diferido
});

const utilizador = computed(() => usePage().props.auth.user);
const dinheiro = (v) => formatarDinheiro(v, props.moeda);

const montarIndicadores = (i) => i.cartoes.map((c) => ({
    rotulo: c.rotulo,
    valor: c.tipo === 'dinheiro' ? dinheiro(c.valor) : String(c.valor),
    nota: c.nota,
    cor: c.cor,
}));

const compacto = (v) => {
    const valor = Number(v) / 10 ** props.moeda.decimais;

    return Number.isFinite(valor) ? new Intl.NumberFormat('pt-PT', { notation: 'compact', maximumFractionDigits: 1 }).format(valor) : '';
};
const nomeDoMes = (mes) => new Intl.DateTimeFormat('pt-PT', { month: 'short', year: '2-digit' }).format(new Date(`${mes}-01T00:00:00`));
const formatarData = (d) => (d ? new Intl.DateTimeFormat('pt-PT').format(new Date(`${d}T00:00:00`)) : '—');

const opcoesPropinasMes = computed(() => {
    const meses = props.graficos?.propinas_mes ?? [];

    return {
        chart: { type: 'bar' },
        series: [
            { name: 'Emitido', data: meses.map((m) => m.emitido) },
            { name: 'Recebido', data: meses.map((m) => m.recebido) },
        ],
        colors: ['#94A3B8', '#16A34A'],
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
        dataLabels: { enabled: false },
        xaxis: { categories: meses.map((m) => nomeDoMes(m.mes)) },
        yaxis: { labels: { formatter: compacto } },
        tooltip: { y: { formatter: (v) => dinheiro(v) } },
        legend: { position: 'top', horizontalAlign: 'right' },
    };
});

const opcoesAlunosCurso = computed(() => {
    const cursos = props.graficos?.alunos_curso ?? [];

    return {
        chart: { type: 'bar' },
        series: [{ name: 'Alunos activos', data: cursos.map((c) => c.total) }],
        colors: ['#16A34A'],
        plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 3 } },
        dataLabels: { enabled: true },
        xaxis: { categories: cursos.map((c) => c.curso), labels: { show: false } },
        tooltip: { y: { formatter: (v) => `${v} aluno(s)` } },
    };
});

// Divide a frase em partes normais e destacadas (`**texto**`).
const partes = (frase) => frase.split(/\*\*(.+?)\*\*/).map((texto, i) => ({ texto, forte: i % 2 === 1 }));

const opcoesPrevisao = computed(() => {
    const semanas = props.previsao?.semanas ?? [];
    const comTaxa = props.previsao?.taxa !== null && props.previsao?.taxa !== undefined;
    const dia = (d) => new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: '2-digit' }).format(new Date(`${d}T00:00:00`));

    return {
        chart: { type: 'bar' },
        series: [
            { name: 'A vencer', data: semanas.map((w) => w.a_vencer) },
            ...(comTaxa ? [{ name: 'Previsto', data: semanas.map((w) => w.previsto) }] : []),
        ],
        colors: ['#94A3B8', '#16A34A'],
        plotOptions: { bar: { columnWidth: '60%', borderRadius: 3 } },
        dataLabels: { enabled: false },
        xaxis: { categories: semanas.map((w) => `Sem. ${dia(w.inicio)}`) },
        yaxis: { labels: { formatter: compacto } },
        tooltip: { y: { formatter: (v) => dinheiro(v) } },
        legend: { position: 'top', horizontalAlign: 'right' },
    };
});

const atalhos = computed(() => [
    { texto: 'Registar pagamento', link: '/financeiro/pagamentos/novo', permissao: 'pagamento.criar' },
    { texto: 'Novo acordo de dívida', link: '/financeiro/acordos/novo', permissao: 'acordo.criar' },
    { texto: 'Alunos', link: '/alunos', permissao: 'aluno.ver' },
    { texto: 'Matrículas', link: '/matriculas', permissao: 'matricula.ver' },
].filter((a) => can(a.permissao)));
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Olá, {{ utilizador?.name }}</h1>
            <div class="text-muted fs-6">O que precisa da sua atenção hoje.</div>
        </div>

        <Deferred data="resumo">
            <template #fallback>
                <div class="card mb-6 bg-body-secondary border-0"><div class="card-body py-6 placeholder-glow">
                    <span class="placeholder col-3 mb-3"></span><span class="placeholder col-9 d-block mb-2"></span><span class="placeholder col-7 d-block"></span>
                </div></div>
            </template>
            <div v-if="resumo && resumo.length" class="card mb-6 bg-body-secondary border-0 border-start border-4" style="border-color: #166534 !important">
                <div class="card-body py-6 d-flex align-items-start gap-5">
                    <span class="symbol symbol-45px flex-shrink-0"><span class="symbol-label" style="background: #166534"><i class="ki-duotone ki-chart-line-star fs-2x text-white"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i></span></span>
                    <div>
                        <div class="text-uppercase fw-bold fs-8 mb-2 text-success" style="letter-spacing: 0.08em">Resumo do dia</div>
                        <div v-for="frase in resumo" :key="frase" class="fs-5 mb-1 text-gray-700">
                            <template v-for="(parte, i) in partes(frase)" :key="i"><strong v-if="parte.forte" class="fw-bolder text-success">{{ parte.texto }}</strong><template v-else>{{ parte.texto }}</template></template>
                        </div>
                    </div>
                </div>
            </div>
        </Deferred>

        <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" :quantidade="4" />

        <Deferred data="graficos">
            <template #fallback>
                <div class="row g-4 mb-6">
                    <div class="col-12 col-lg-8"><div class="card"><div class="card-body placeholder-glow"><span class="placeholder col-12 rounded" style="height: 300px"></span></div></div></div>
                    <div class="col-12 col-lg-4"><div class="card"><div class="card-body placeholder-glow"><span class="placeholder col-12 rounded" style="height: 300px"></span></div></div></div>
                </div>
            </template>
            <div v-if="graficos && Object.keys(graficos).length" class="row g-4 mb-6">
                <div v-if="graficos.propinas_mes" class="col-12" :class="graficos.alunos_curso ? 'col-lg-8' : ''">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="card-title fw-bold fs-5">Propinas por mês <span class="text-muted fs-7 fw-semibold ms-2">emitido vs recebido, por vencimento</span></h3></div>
                        <div class="card-body pt-2">
                            <GraficoApex v-if="graficos.propinas_mes.length" :opcoes="opcoesPropinasMes" />
                            <div v-else class="text-muted text-center py-10">Sem propinas geradas neste ano lectivo.</div>
                        </div>
                    </div>
                </div>
                <div v-if="graficos.alunos_curso" class="col-12" :class="graficos.propinas_mes ? 'col-lg-4' : ''">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="card-title fw-bold fs-5">Alunos por curso</h3></div>
                        <div class="card-body pt-2">
                            <GraficoApex v-if="graficos.alunos_curso.length" :opcoes="opcoesAlunosCurso" />
                            <div v-else class="text-muted text-center py-10">Sem matrículas activas neste ano lectivo.</div>
                        </div>
                    </div>
                </div>
            </div>
        </Deferred>

        <Deferred v-if="can('propina.ver')" data="risco">
            <template #fallback>
                <div class="row g-4 mb-6">
                    <div class="col-12 col-lg-7"><div class="card"><div class="card-body placeholder-glow"><span class="placeholder col-12 rounded" style="height: 280px"></span></div></div></div>
                    <div class="col-12 col-lg-5"><div class="card"><div class="card-body placeholder-glow"><span class="placeholder col-12 rounded" style="height: 280px"></span></div></div></div>
                </div>
            </template>
            <div class="row g-4 mb-6">
                <div class="col-12 col-lg-7">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title fw-bold fs-5">Alunos em risco
                                <span v-if="risco?.total" class="text-muted fs-7 fw-semibold ms-2">{{ risco.total }} aluno(s) · {{ dinheiro(risco.valor) }} em dívida</span>
                            </h3>
                        </div>
                        <div class="card-body pt-2">
                            <div v-if="!risco?.alunos.length" class="text-muted text-center py-10">Nenhum aluno em risco. Todos com menos de 2 meses em atraso ou com acordo em dia.</div>
                            <div v-for="a in risco?.alunos" :key="a.matricula_id" class="d-flex align-items-center justify-content-between py-3 border-bottom border-gray-200 gap-3">
                                <div class="min-w-0">
                                    <Link :href="`/alunos/${a.aluno_id}`" class="fw-semibold text-gray-800 text-hover-primary">{{ a.aluno ?? '—' }}</Link>
                                    <div class="text-muted fs-7">{{ a.turma ?? '—' }} · {{ a.meses }} {{ a.meses === 1 ? 'mês' : 'meses' }} em atraso · {{ a.motivo }}</div>
                                </div>
                                <div class="d-flex align-items-center gap-3 flex-shrink-0">
                                    <span class="badge" :class="a.nivel === 'alto' ? 'badge-light-danger' : 'badge-light-warning'">{{ a.nivel === 'alto' ? 'Alto' : 'Médio' }}</span>
                                    <span class="fw-bold text-gray-800">{{ dinheiro(a.total) }}</span>
                                    <Link v-if="can('acordo.criar')" :href="`/financeiro/acordos/novo?matricula=${a.matricula_id}`" class="btn btn-sm btn-light-success">Propor acordo</Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-5">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="card-title fw-bold fs-5">Previsão de caixa <span class="text-muted fs-7 fw-semibold ms-2">próximos 30 dias</span></h3></div>
                        <div class="card-body pt-2">
                            <template v-if="previsao">
                                <div v-if="previsao.previsto !== null">
                                    <div class="fs-2 fw-bolder text-success lh-1">{{ dinheiro(previsao.previsto) }}</div>
                                    <div class="text-muted fs-7 mt-2">previsto, de {{ dinheiro(previsao.a_vencer) }} a vencer · taxa histórica de cobrança {{ previsao.taxa }}%</div>
                                </div>
                                <div v-else>
                                    <div class="fs-2 fw-bolder lh-1">{{ dinheiro(previsao.a_vencer) }}</div>
                                    <div class="text-muted fs-7 mt-2">a vencer · ainda sem histórico para estimar a cobrança</div>
                                </div>
                                <GraficoApex v-if="previsao.a_vencer > 0" :opcoes="opcoesPrevisao" :altura="220" class="mt-3" />
                                <div v-else class="text-muted text-center py-8">Nada a vencer nos próximos 30 dias.</div>
                                <div v-if="previsao.em_atraso > 0" class="d-flex justify-content-between fs-7 border-top border-gray-200 pt-3 mt-2">
                                    <span class="text-muted">Em atraso acumulado (fora da previsão)</span><span class="fw-bold text-danger">{{ dinheiro(previsao.em_atraso) }}</span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </Deferred>

        <Deferred v-if="can('propina.ver')" data="calendario">
            <template #fallback>
                <div class="card mb-6"><div class="card-body placeholder-glow"><span class="placeholder col-12 rounded" style="height: 280px"></span></div></div>
            </template>
            <div v-if="calendario" class="card mb-6">
                <div class="card-header"><h3 class="card-title fw-bold fs-5">Calendário de vencimentos <span class="text-muted fs-7 fw-semibold ms-2">clique num dia para ver as propinas</span></h3></div>
                <div class="card-body pt-2">
                    <CalendarioVencimentos :calendario="calendario" :formatar="dinheiro" />
                </div>
            </div>
        </Deferred>

        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fw-bold fs-5">Precisa de atenção</h3></div>
                    <div class="card-body pt-2">
                        <Deferred data="atencao">
                            <template #fallback>
                                <div v-for="n in 3" :key="n" class="placeholder-glow py-3"><span class="placeholder col-8 rounded"></span></div>
                            </template>
                            <div v-if="atencao && atencao.length === 0" class="text-muted py-6 text-center">Tudo em dia. Nada a assinalar.</div>
                            <Link
                                v-for="item in atencao"
                                :key="item.rotulo"
                                :href="item.link"
                                class="d-flex align-items-center justify-content-between py-3 border-bottom border-gray-200 text-gray-800 text-hover-primary"
                            >
                                <span class="d-flex align-items-center">
                                    <span class="bullet bullet-dot me-3" :class="`bg-${item.cor}`"></span>
                                    <span class="fw-semibold">{{ item.rotulo }}</span>
                                </span>
                                <span class="d-flex align-items-center gap-3">
                                    <span v-if="item.valor" class="text-muted fs-7">{{ dinheiro(item.valor) }}</span>
                                    <span class="badge badge-light-primary fs-7">{{ item.quantidade }}</span>
                                </span>
                            </Link>
                        </Deferred>
                    </div>
                </div>
            </div>

            <div v-if="atalhos.length" class="col-12 col-lg-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fw-bold fs-5">Atalhos</h3></div>
                    <div class="card-body pt-2 d-flex flex-column gap-3">
                        <Link v-for="a in atalhos" :key="a.link" :href="a.link" class="btn btn-light btn-active-light-primary text-start">{{ a.texto }}</Link>
                    </div>
                </div>
            </div>
        </div>

        <Deferred data="ultimosPagamentos">
            <template #fallback><span></span></template>
            <div v-if="ultimosPagamentos && ultimosPagamentos.length" class="card mt-4">
                <div class="card-header"><h3 class="card-title fw-bold fs-5">Últimos pagamentos</h3></div>
                <div class="card-body pt-2">
                    <div v-for="p in ultimosPagamentos" :key="p.numero" class="d-flex align-items-center justify-content-between py-3 border-bottom border-gray-200">
                        <div>
                            <div class="fw-semibold text-gray-800">{{ p.aluno ?? '—' }}</div>
                            <div class="text-muted fs-7">{{ p.numero }} · {{ formatarData(p.data) }}</div>
                        </div>
                        <span class="fw-bold text-success">{{ dinheiro(p.valor) }}</span>
                    </div>
                </div>
            </div>
        </Deferred>
    </div>
</template>
