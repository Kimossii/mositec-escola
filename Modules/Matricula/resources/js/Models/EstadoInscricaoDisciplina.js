/**
 * Espelha Modules/Matricula/app/Enums/EstadoInscricaoDisciplinaEnum.php — só
 * para apresentação (labels, cor de badge, transições permitidas); a
 * autoridade sobre transições válidas continua no backend (podeTransitarPara).
 */

export const ESTADO_INSCRICAO_DISCIPLINA = Object.freeze({
    INSCRITA: 1,
    CONCLUIDA: 2,
    REPROVADA: 3,
    DESISTIDA: 4,
});

export const ESTADO_INSCRICAO_DISCIPLINA_LABEL = Object.freeze({
    [ESTADO_INSCRICAO_DISCIPLINA.INSCRITA]: 'Inscrita',
    [ESTADO_INSCRICAO_DISCIPLINA.CONCLUIDA]: 'Concluída',
    [ESTADO_INSCRICAO_DISCIPLINA.REPROVADA]: 'Reprovada',
    [ESTADO_INSCRICAO_DISCIPLINA.DESISTIDA]: 'Desistida',
});

export const TRANSICOES_INSCRICAO_DISCIPLINA = Object.freeze({
    [ESTADO_INSCRICAO_DISCIPLINA.INSCRITA]: [
        ESTADO_INSCRICAO_DISCIPLINA.CONCLUIDA,
        ESTADO_INSCRICAO_DISCIPLINA.REPROVADA,
        ESTADO_INSCRICAO_DISCIPLINA.DESISTIDA,
    ],
    [ESTADO_INSCRICAO_DISCIPLINA.CONCLUIDA]: [],
    [ESTADO_INSCRICAO_DISCIPLINA.REPROVADA]: [],
    [ESTADO_INSCRICAO_DISCIPLINA.DESISTIDA]: [],
});

export const estadoInscricaoDisciplinaLabel = (estado) => ESTADO_INSCRICAO_DISCIPLINA_LABEL[estado] ?? '—';

export const estadoInscricaoDisciplinaBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_INSCRICAO_DISCIPLINA.INSCRITA: return 'badge-light-warning';
        case ESTADO_INSCRICAO_DISCIPLINA.CONCLUIDA: return 'badge-light-success';
        case ESTADO_INSCRICAO_DISCIPLINA.REPROVADA: return 'badge-light-danger';
        case ESTADO_INSCRICAO_DISCIPLINA.DESISTIDA: return 'badge-light-secondary';
        default: return 'badge-light-secondary';
    }
};

export const transicoesDisponiveisInscricaoDisciplina = (estado) => TRANSICOES_INSCRICAO_DISCIPLINA[estado] ?? [];
