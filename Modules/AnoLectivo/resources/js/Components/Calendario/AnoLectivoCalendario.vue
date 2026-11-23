<script setup>
import { computed, ref } from 'vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ptLocale from '@fullcalendar/core/locales/pt';
import { tipoEventoCalendarioCor, tipoEventoCalendarioLabel } from '../../Models/AnoLectivo';

const props = defineProps({
    anoLectivo: { type: Object, required: true },
    eventos: { type: Array, required: true },
    podeCriar: { type: Boolean, default: false },
    podeEditar: { type: Boolean, default: false },
});
const emit = defineEmits(['criar-evento', 'editar-evento']);

// O `end` do FullCalendar (validRange e eventos multi-dia) é EXCLUSIVO —
// sem +1 dia, o próprio último dia do intervalo fica fora dele/invisível.
function proximoDia(dataISO) {
    const data = new Date(`${dataISO}T00:00:00`);
    data.setDate(data.getDate() + 1);
    const ano = data.getFullYear();
    const mes = String(data.getMonth() + 1).padStart(2, '0');
    const dia = String(data.getDate()).padStart(2, '0');
    return `${ano}-${mes}-${dia}`;
}

function hojeIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// O calendário abre sempre no mês de hoje; se hoje está fora do ano lectivo, no início (ano futuro) ou no fim (ano passado).
function dataInicial() {
    const hoje = hojeIso();
    if (hoje < props.anoLectivo.data_inicio) return props.anoLectivo.data_inicio;
    if (hoje > props.anoLectivo.data_fim) return props.anoLectivo.data_fim;
    return hoje;
}

const calendarOptions = computed(() => ({
    locale: ptLocale,
    firstDay: 0, // domingo, como no calendário habitual em Angola
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek',
    },
    initialDate: dataInicial(),
    validRange: {
        start: props.anoLectivo.data_inicio,
        end: proximoDia(props.anoLectivo.data_fim),
    },
    selectable: props.podeCriar,
    editable: false,
    // Eventos «suaves»: fundo tingido com a cor do tipo e texto na cor do tema, para se lerem nos dois temas.
    events: props.eventos.map((evento) => ({
        id: String(evento.id),
        title: evento.titulo,
        start: evento.data_inicio,
        end: proximoDia(evento.data_fim),
        allDay: evento.dia_inteiro,
        backgroundColor: `color-mix(in srgb, ${tipoEventoCalendarioCor(evento.tipo)} 18%, transparent)`,
        borderColor: tipoEventoCalendarioCor(evento.tipo),
        textColor: 'var(--bs-body-color)',
        extendedProps: { original: evento },
    })),
    eventMouseEnter: (info) => mostrarDetalhe(info),
    eventMouseLeave: () => { detalhe.value = null; },
    dateClick: (info) => {
        if (props.podeCriar) emit('criar-evento', info.dateStr);
    },
    eventClick: (info) => {
        if (props.podeEditar) emit('editar-evento', info.event.extendedProps.original);
    },
}));

// ── Detalhe ao passar o rato num evento ──
const detalhe = ref(null); // { evento, x, y, acima }

const formatoLongo = new Intl.DateTimeFormat('pt-PT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const formatoCurto = new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'long' });
const dia = (iso) => new Date(`${iso}T00:00:00`);

function periodo(evento) {
    if (evento.data_inicio === evento.data_fim) return formatoLongo.format(dia(evento.data_inicio));
    return `${formatoCurto.format(dia(evento.data_inicio))} a ${formatarComAno(evento.data_fim)}`;
}

function formatarComAno(iso) {
    return new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' }).format(dia(iso));
}

function mostrarDetalhe(info) {
    const caixa = info.el.getBoundingClientRect();
    const acima = caixa.bottom + 160 > window.innerHeight; // sem espaço por baixo: abre para cima
    detalhe.value = {
        evento: info.event.extendedProps.original,
        x: Math.min(Math.max(caixa.left, 8), window.innerWidth - 300),
        y: acima ? caixa.top - 6 : caixa.bottom + 6,
        acima,
    };
}
</script>

<template>
    <FullCalendar :options="calendarOptions" />

    <Teleport to="body">
        <div
            v-if="detalhe"
            class="cal-detalhe"
            :style="{ left: `${detalhe.x}px`, top: `${detalhe.y}px`, transform: detalhe.acima ? 'translateY(-100%)' : 'none' }"
            role="tooltip"
        >
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="cal-detalhe-ponto" :style="{ background: tipoEventoCalendarioCor(detalhe.evento.tipo) }"></span>
                <span class="text-muted fs-8 text-uppercase fw-semibold">{{ tipoEventoCalendarioLabel(detalhe.evento.tipo) }}</span>
            </div>
            <div class="fw-bold fs-6">{{ detalhe.evento.titulo }}</div>
            <div class="text-gray-700 fs-7 mt-1">{{ periodo(detalhe.evento) }}</div>
            <div v-if="detalhe.evento.dia_inteiro" class="text-muted fs-8">Dia inteiro</div>
            <div v-if="detalhe.evento.descricao" class="fs-7 mt-2 cal-detalhe-descricao">{{ detalhe.evento.descricao }}</div>
        </div>
    </Teleport>
</template>

<style scoped>
/* O calendário segue o tema (claro/escuro) pelas variáveis do Bootstrap, em vez das cores fixas (brancas) do
   FullCalendar, que no tema escuro deixavam o texto claro sobre fundo claro. Sem importar o bundle CSS do tema
   (evita colidir com Bootstrap/Tailwind, já há uma colisão documentada com .collapse). */
:deep(.fc) {
    --fc-page-bg-color: var(--bs-body-bg);
    --fc-neutral-bg-color: var(--bs-secondary-bg);
    --fc-neutral-text-color: var(--bs-secondary-color);
    --fc-border-color: var(--bs-border-color);
    --fc-button-bg-color: var(--bs-primary);
    --fc-button-border-color: var(--bs-primary);
    --fc-button-hover-bg-color: var(--bs-primary);
    --fc-button-hover-border-color: var(--bs-primary);
    --fc-button-active-bg-color: var(--bs-primary);
    --fc-button-active-border-color: var(--bs-primary);
    --fc-today-bg-color: rgba(var(--bs-primary-rgb), 0.08);
    --fc-highlight-color: rgba(var(--bs-primary-rgb), 0.12);
    --fc-event-border-color: var(--bs-primary);
    color: var(--bs-body-color);
}

:deep(.fc .fc-toolbar-title) {
    font-size: 1.25rem;
    font-weight: 600;
    text-transform: capitalize;
}

:deep(.fc .fc-col-header-cell-cushion),
:deep(.fc .fc-daygrid-day-number) {
    color: var(--bs-body-color);
    text-decoration: none;
}

:deep(.fc .fc-col-header-cell) {
    background: var(--bs-secondary-bg);
    text-transform: capitalize;
}

:deep(.fc .fc-day-other .fc-daygrid-day-number) {
    opacity: 0.45;
}

:deep(.fc .fc-event) {
    border-width: 0 0 0 3px !important;
    border-style: solid;
    border-radius: 4px;
    cursor: pointer;
}

:deep(.fc .fc-event .fc-event-main),
:deep(.fc .fc-event .fc-event-title),
:deep(.fc .fc-event .fc-event-time) {
    color: var(--bs-body-color);
    font-weight: 500;
}

:deep(.fc .fc-more-link) {
    color: var(--bs-primary);
}

:deep(.fc .fc-popover) {
    background: var(--bs-body-bg);
    border-color: var(--bs-border-color);
}

.cal-detalhe {
    position: fixed;
    z-index: 2000;
    width: 280px;
    padding: 0.75rem 0.875rem;
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
    pointer-events: none;
}

.cal-detalhe-ponto {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.cal-detalhe-descricao {
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
    white-space: pre-line;
}
</style>
