export const ESTADO = {
    INATIVO: 0,
    ATIVO: 1,
};

export const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

export function estadoBadgeClass(estado) {
    return estado === ESTADO.ATIVO ? 'badge-light-success' : 'badge-light-secondary';
}
