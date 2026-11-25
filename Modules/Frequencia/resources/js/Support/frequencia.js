/** «2026-06-15» → «15/06/2026» (dias, sem conversões de fuso). */
export const formatarDia = (dia) => (dia ? dia.split('-').reverse().join('/') : '');

/** Estados de uma presença (valores de `EstadoPresenca`). */
export const PRESENCA = { PRESENTE: 1, FALTA: 2, ATRASO: 3 };

export const ESTADOS = [
    { valor: PRESENCA.PRESENTE, texto: 'Presente', classe: 'btn-light-success', activo: 'btn-success' },
    { valor: PRESENCA.FALTA, texto: 'Falta', classe: 'btn-light-danger', activo: 'btn-danger' },
    { valor: PRESENCA.ATRASO, texto: 'Atraso', classe: 'btn-light-warning', activo: 'btn-warning' },
];

/** Estados da chamada de uma aula do dia (devolvidos por `aulasDoDia`). */
export const ESTADO_AULA = {
    pendente: { texto: 'Por fazer', classe: 'badge-light-warning' },
    feita: { texto: 'Feita', classe: 'badge-light-success' },
    nao_leccionada: { texto: 'Não leccionada', classe: 'badge-light-dark' },
};

export const hojeIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
