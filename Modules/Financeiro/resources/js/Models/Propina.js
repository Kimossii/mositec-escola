/**
 * Espelha EstadoCobranca e OrigemGeracaoPropina (PHP). Só para apresentação; a autoridade é o backend
 * (o estado que chega à lista já vem resolvido para o "hoje" da escola).
 */
export const ESTADO_COBRANCA = Object.freeze({
    EM_ABERTO: 1,
    PARCIALMENTE_PAGA: 2,
    PAGA: 3,
    CANCELADA: 4,
    ANULADA: 5,
    PENDENTE: 6,
    EM_ATRASO: 7,
});

export const ORIGEM_GERACAO = Object.freeze({ MATRICULA: 1, COMANDO: 2, MANUAL: 3, RETROACTIVA: 4 });

export const estadoCobrancaBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_COBRANCA.EM_ABERTO: return 'badge-light-primary';
        case ESTADO_COBRANCA.PARCIALMENTE_PAGA: return 'badge-light-info';
        case ESTADO_COBRANCA.PAGA: return 'badge-light-success';
        case ESTADO_COBRANCA.CANCELADA: return 'badge-inativo';
        case ESTADO_COBRANCA.ANULADA: return 'badge-light-dark';
        case ESTADO_COBRANCA.EM_ATRASO: return 'badge-light-danger';
        default: return 'badge-light-secondary';
    }
};
