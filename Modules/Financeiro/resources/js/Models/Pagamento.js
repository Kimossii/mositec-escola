/**
 * Espelha EstadoPagamento, EstadoCredito e EstadoUtilizacaoCredito (PHP). Só para apresentação; a autoridade é o backend.
 */
export const ESTADO_PAGAMENTO = Object.freeze({ CONFIRMADO: 1, ANULADO: 2 });
export const ESTADO_CREDITO = Object.freeze({ DISPONIVEL: 1, ESGOTADO: 2, ANULADO: 3 });
export const ESTADO_UTILIZACAO = Object.freeze({ ACTIVA: 1, ANULADA: 2 });
export const MODO_DISTRIBUICAO = Object.freeze({ AUTOMATICA: 'automatica', EXPLICITA: 'explicita' });

export const estadoPagamentoBadgeClass = (estado) => (estado === ESTADO_PAGAMENTO.CONFIRMADO ? 'badge-light-success' : 'badge-light-dark');

export const estadoCreditoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_CREDITO.DISPONIVEL: return 'badge-light-primary';
        case ESTADO_CREDITO.ESGOTADO: return 'badge-light-secondary';
        default: return 'badge-light-dark';
    }
};
