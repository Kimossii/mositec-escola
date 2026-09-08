/**
 * Espelha Modules/Core/app/Enums/Estado.php — usado por Turma, NivelAcademico
 * e Turno, que partilham o mesmo Estado binário Ativo/Inativo no backend.
 * Só para apresentação (labels, cor de badge); a autoridade é o backend.
 */

export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

export const estadoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO.ATIVO: return 'badge-light-success';
        case ESTADO.INATIVO: return 'badge-light-secondary';
        default: return 'badge-light-secondary';
    }
};
