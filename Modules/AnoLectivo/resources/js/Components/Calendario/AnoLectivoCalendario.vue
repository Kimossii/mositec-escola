<script setup>
import { computed } from 'vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import { tipoEventoCalendarioCor } from '../../Models/AnoLectivo';

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

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek',
    },
    initialDate: props.anoLectivo.data_inicio,
    validRange: {
        start: props.anoLectivo.data_inicio,
        end: proximoDia(props.anoLectivo.data_fim),
    },
    selectable: props.podeCriar,
    editable: false,
    events: props.eventos.map((evento) => ({
        id: String(evento.id),
        title: evento.titulo,
        start: evento.data_inicio,
        end: proximoDia(evento.data_fim),
        allDay: evento.dia_inteiro,
        backgroundColor: tipoEventoCalendarioCor(evento.tipo),
        borderColor: tipoEventoCalendarioCor(evento.tipo),
        extendedProps: { original: evento },
    })),
    dateClick: (info) => {
        if (props.podeCriar) emit('criar-evento', info.dateStr);
    },
    eventClick: (info) => {
        if (props.podeEditar) emit('editar-evento', info.event.extendedProps.original);
    },
}));
</script>

<template>
    <FullCalendar :options="calendarOptions" />
</template>

<style scoped>
/* Ajuste mínimo para a cor primária do FullCalendar acompanhar o tema —
   sem importar o bundle CSS jQuery do tema (evita colidir com Bootstrap/
   Tailwind, já há uma colisão documentada com .collapse). */
:deep(.fc) {
    --fc-button-bg-color: var(--bs-primary);
    --fc-button-border-color: var(--bs-primary);
    --fc-button-hover-bg-color: var(--bs-primary);
    --fc-button-active-bg-color: var(--bs-primary);
    --fc-today-bg-color: rgba(var(--bs-primary-rgb), 0.08);
}
</style>
