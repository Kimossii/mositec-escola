/**
 * Espelha Modules/AnoLectivo/app/Enums/{EstadoAnoLectivo,TipoPeriodo,TipoEventoCalendario}.php
 * — só para apresentação (labels, cores de badge/calendário). Nenhuma regra
 * de negócio aqui; a autoridade continua inteiramente no backend.
 */

export const ESTADO_ANO_LECTIVO = Object.freeze({ PLANEADO: 0, ATIVO: 1, ENCERRADO: 2 });

export const estadoAnoLectivoLabel = (estado) => {
    switch (estado) {
        case ESTADO_ANO_LECTIVO.PLANEADO: return 'Planeado';
        case ESTADO_ANO_LECTIVO.ATIVO: return 'Activo';
        case ESTADO_ANO_LECTIVO.ENCERRADO: return 'Encerrado';
        default: return '—';
    }
};

export const estadoAnoLectivoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_ANO_LECTIVO.PLANEADO: return 'badge-light-secondary';
        case ESTADO_ANO_LECTIVO.ATIVO: return 'badge-light-success';
        case ESTADO_ANO_LECTIVO.ENCERRADO: return 'badge-light-dark';
        default: return 'badge-light-secondary';
    }
};

export const TIPO_PERIODO = Object.freeze({ TRIMESTRE: 0, SEMESTRE: 1, OUTRO: 2 });

export const TIPO_PERIODO_OPCOES = [
    { value: TIPO_PERIODO.TRIMESTRE, label: 'Trimestre' },
    { value: TIPO_PERIODO.SEMESTRE, label: 'Semestre' },
    { value: TIPO_PERIODO.OUTRO, label: 'Outro' },
];

export const tipoPeriodoLabel = (tipo) =>
    TIPO_PERIODO_OPCOES.find((opcao) => opcao.value === tipo)?.label ?? '—';

export const TIPO_EVENTO_CALENDARIO = Object.freeze({
    AULA: 0,
    AVALIACAO: 1,
    REUNIAO: 2,
    FERIAS: 3,
    FERIADO: 4,
    ACTIVIDADE: 5,
    EVENTO: 6,
    OUTRO: 7,
});

export const TIPO_EVENTO_CALENDARIO_OPCOES = [
    { value: TIPO_EVENTO_CALENDARIO.AULA, label: 'Aula' },
    { value: TIPO_EVENTO_CALENDARIO.AVALIACAO, label: 'Avaliação' },
    { value: TIPO_EVENTO_CALENDARIO.REUNIAO, label: 'Reunião' },
    { value: TIPO_EVENTO_CALENDARIO.FERIAS, label: 'Férias' },
    { value: TIPO_EVENTO_CALENDARIO.FERIADO, label: 'Feriado' },
    { value: TIPO_EVENTO_CALENDARIO.ACTIVIDADE, label: 'Actividade' },
    { value: TIPO_EVENTO_CALENDARIO.EVENTO, label: 'Evento' },
    { value: TIPO_EVENTO_CALENDARIO.OUTRO, label: 'Outro' },
];

export const tipoEventoCalendarioLabel = (tipo) =>
    TIPO_EVENTO_CALENDARIO_OPCOES.find((opcao) => opcao.value === tipo)?.label ?? '—';

// Cor por tipo de evento, usada pelo AnoLectivoCalendario (FullCalendar).
// Usa as variáveis CSS Bootstrap já definidas no tema, para acompanhar
// automaticamente a paleta actual em vez de valores hexadecimais fixos.
export const tipoEventoCalendarioCor = (tipo) => {
    switch (tipo) {
        case TIPO_EVENTO_CALENDARIO.AULA: return 'var(--bs-primary)';
        case TIPO_EVENTO_CALENDARIO.AVALIACAO: return 'var(--bs-danger)';
        case TIPO_EVENTO_CALENDARIO.REUNIAO: return 'var(--bs-info)';
        case TIPO_EVENTO_CALENDARIO.FERIAS: return 'var(--bs-warning)';
        case TIPO_EVENTO_CALENDARIO.FERIADO: return 'var(--bs-dark)';
        case TIPO_EVENTO_CALENDARIO.ACTIVIDADE: return 'var(--bs-success)';
        case TIPO_EVENTO_CALENDARIO.EVENTO: return 'var(--bs-secondary)';
        case TIPO_EVENTO_CALENDARIO.OUTRO: return 'var(--bs-gray-500)';
        default: return 'var(--bs-secondary)';
    }
};
