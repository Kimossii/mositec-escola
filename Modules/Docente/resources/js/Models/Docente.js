/**
 * Espelha o estado binário do docente (Modules/Core Estado: 0 inactivo, 1 activo) e o sexo de
 * Modules/Usuario/app/Models/DadosPessoa.php (SEXO_*). Só para apresentação; a autoridade é o backend.
 */

export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

export const estadoLabel = (estado) => (estado === ESTADO.ATIVO ? 'Ativo' : 'Inativo');

export const estadoBadgeClass = (estado) => (estado === ESTADO.ATIVO ? 'badge-light-success' : 'badge-inativo');

export const SEXO = Object.freeze({ NAO_ESPECIFICADO: 0, MASCULINO: 1, FEMININO: 2 });

export const SEXO_OPCOES = [
    { value: SEXO.NAO_ESPECIFICADO, label: 'Não especificado' },
    { value: SEXO.MASCULINO, label: 'Masculino' },
    { value: SEXO.FEMININO, label: 'Feminino' },
];

/** Filtro «acesso» da lista (`com` | `sem`, como o servidor espera). */
export const ACESSO = Object.freeze({ COM: 'com', SEM: 'sem' });

/** «2026-06-15» → «15/06/2026», sem passar por Date (sem desvio de fuso). */
export const formatarData = (iso) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '');
