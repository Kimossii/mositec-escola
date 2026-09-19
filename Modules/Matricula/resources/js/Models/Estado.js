/**
 * Espelha Modules/Matricula/app/Enums/EstadoMatriculaEnum.php — só para
 * apresentação (labels, cor de badge, transições permitidas); a autoridade
 * sobre transições válidas continua no backend (podeTransitarPara).
 */

export const ESTADO_MATRICULA = Object.freeze({
    PENDENTE: 1,
    ACTIVA: 2,
    CANCELADA: 3,
    CONCLUIDA: 4,
    TRANSFERIDA: 5,
});

export const ESTADO_MATRICULA_LABEL = Object.freeze({
    [ESTADO_MATRICULA.PENDENTE]: 'Pendente',
    [ESTADO_MATRICULA.ACTIVA]: 'Activa',
    [ESTADO_MATRICULA.CANCELADA]: 'Cancelada',
    [ESTADO_MATRICULA.CONCLUIDA]: 'Concluída',
    [ESTADO_MATRICULA.TRANSFERIDA]: 'Transferida',
});

export const TRANSICOES_MATRICULA = Object.freeze({
    [ESTADO_MATRICULA.PENDENTE]: [ESTADO_MATRICULA.ACTIVA, ESTADO_MATRICULA.CANCELADA],
    [ESTADO_MATRICULA.ACTIVA]: [ESTADO_MATRICULA.CONCLUIDA, ESTADO_MATRICULA.CANCELADA, ESTADO_MATRICULA.TRANSFERIDA],
    [ESTADO_MATRICULA.CANCELADA]: [],
    [ESTADO_MATRICULA.CONCLUIDA]: [],
    [ESTADO_MATRICULA.TRANSFERIDA]: [],
});

export const estadoMatriculaLabel = (estado) => ESTADO_MATRICULA_LABEL[estado] ?? '—';

export const estadoMatriculaBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_MATRICULA.PENDENTE: return 'badge-light-warning';
        case ESTADO_MATRICULA.ACTIVA: return 'badge-light-success';
        case ESTADO_MATRICULA.CANCELADA: return 'badge-light-danger';
        case ESTADO_MATRICULA.CONCLUIDA: return 'badge-light-info';
        case ESTADO_MATRICULA.TRANSFERIDA: return 'badge-light-primary';
        default: return 'badge-light-secondary';
    }
};

export const transicoesDisponiveis = (estado) => TRANSICOES_MATRICULA[estado] ?? [];

export const estadoMatriculaTerminal = (estado) => [
    ESTADO_MATRICULA.CANCELADA,
    ESTADO_MATRICULA.CONCLUIDA,
    ESTADO_MATRICULA.TRANSFERIDA,
].includes(estado);
