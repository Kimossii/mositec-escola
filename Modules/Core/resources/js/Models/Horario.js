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

export const TIPO = {
    PERIODO: 1,
    TEMPO: 2,
};

export const TIPO_OPCOES = [
    { value: TIPO.PERIODO, label: 'Período' },
    { value: TIPO.TEMPO, label: 'Tempo' },
];

export function tipoLabel(tipo) {
    return TIPO_OPCOES.find((opcao) => opcao.value === tipo)?.label ?? '—';
}
