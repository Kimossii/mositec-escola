/**
 * Espelha Modules/Docente/app/Enums/PapelDocente.php e os estados de vigência devolvidos pelo
 * AtribuicaoDocenteConsultaService. Só para apresentação (labels, cor de badge); a autoridade é o backend.
 */

export const PAPEL = Object.freeze({ TITULAR: 0, SUBSTITUTO: 1 });

export const papelLabel = (papel) => (papel === PAPEL.SUBSTITUTO ? 'Substituto' : 'Titular');

export const VIGENCIA = Object.freeze({ AGENDADA: 'agendada', EM_CURSO: 'em_curso', TERMINADA: 'terminada' });

export const vigenciaLabel = (estado) => {
    switch (estado) {
        case VIGENCIA.AGENDADA: return 'Agendada';
        case VIGENCIA.EM_CURSO: return 'Em curso';
        case VIGENCIA.TERMINADA: return 'Terminada';
        default: return estado ?? '';
    }
};

export const vigenciaBadgeClass = (estado) => {
    switch (estado) {
        case VIGENCIA.EM_CURSO: return 'badge-light-success';
        case VIGENCIA.AGENDADA: return 'badge-light-info';
        case VIGENCIA.TERMINADA: return 'badge-inativo';
        default: return 'badge-light-secondary';
    }
};

/** «2026-06-15» → «15/06/2026» (sem conversões de fuso: as datas da vigência são dias). */
export const formatarDia = (dia) => (dia ? dia.split('-').reverse().join('/') : '');

/** Data de hoje (fuso do navegador) em AAAA-MM-DD, só como valor inicial dos campos; o servidor valida no fuso do tenant. */
export const hojeIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
