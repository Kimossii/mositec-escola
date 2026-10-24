/**
 * Espelha Modules/Core/app/Enums/Estado.php (Ativo/Inativo). Só para apresentação;
 * a autoridade é o backend.
 */
export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

export const estadoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO.ATIVO: return 'badge-light-success';
        case ESTADO.INATIVO: return 'badge-inativo';
        default: return 'badge-light-secondary';
    }
};
