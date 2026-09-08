/**
 * Espelha Modules/Infraestrutura/app/Enums/{EstadoSala,TipoSala}.php
 * — só para apresentação (labels, cores de badge/ícone). Nenhuma regra
 * de negócio aqui; a autoridade continua inteiramente no backend.
 */

export const ESTADO_SALA = Object.freeze({ ATIVA: 0, MANUTENCAO: 1, INATIVA: 2 });

export const estadoSalaLabel = (estado) => {
    switch (estado) {
        case ESTADO_SALA.ATIVA: return 'Ativa';
        case ESTADO_SALA.MANUTENCAO: return 'Em Manutenção';
        case ESTADO_SALA.INATIVA: return 'Inativa';
        default: return '—';
    }
};

export const estadoSalaBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_SALA.ATIVA: return 'badge-light-success';
        case ESTADO_SALA.MANUTENCAO: return 'badge-light-warning';
        case ESTADO_SALA.INATIVA: return 'badge-light-secondary';
        default: return 'badge-light-secondary';
    }
};

export const TIPO_SALA = Object.freeze({
    SALA_AULA: 0,
    LABORATORIO: 1,
    BIBLIOTECA: 2,
    AUDITORIO: 3,
    GINASIO: 4,
    SALA_PROFESSORES: 5,
    GABINETE_ADMINISTRATIVO: 6,
    OUTRO: 7,
});

export const TIPO_SALA_OPCOES = [
    { value: TIPO_SALA.SALA_AULA, label: 'Sala de Aula' },
    { value: TIPO_SALA.LABORATORIO, label: 'Laboratório' },
    { value: TIPO_SALA.BIBLIOTECA, label: 'Biblioteca' },
    { value: TIPO_SALA.AUDITORIO, label: 'Auditório' },
    { value: TIPO_SALA.GINASIO, label: 'Ginásio' },
    { value: TIPO_SALA.SALA_PROFESSORES, label: 'Sala de Professores' },
    { value: TIPO_SALA.GABINETE_ADMINISTRATIVO, label: 'Gabinete Administrativo' },
    { value: TIPO_SALA.OUTRO, label: 'Outro' },
];

export const tipoSalaLabel = (tipo) =>
    TIPO_SALA_OPCOES.find((opcao) => opcao.value === tipo)?.label ?? '—';
