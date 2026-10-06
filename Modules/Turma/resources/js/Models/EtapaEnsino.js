/**
 * Espelha Modules/Estabelecimento/app/Enums/EtapaEnsinoEnum.php — usado
 * por NivelAcademico e Turma. Só para apresentação/lógica de UI; a
 * autoridade é o backend.
 */

export const ETAPA_ENSINO = Object.freeze({
    CRECHE: 1,
    PRE_ESCOLAR: 2,
    PRIMARIO: 3,
    SECUNDARIO: 4,
    SUPERIOR: 5,
});

export const ETAPA_ENSINO_OPCOES = [
    { value: ETAPA_ENSINO.CRECHE, label: 'Creche' },
    { value: ETAPA_ENSINO.PRE_ESCOLAR, label: 'Pré-Escolar' },
    { value: ETAPA_ENSINO.PRIMARIO, label: 'Ensino Primário' },
    { value: ETAPA_ENSINO.SECUNDARIO, label: 'Ensino Secundário' },
    { value: ETAPA_ENSINO.SUPERIOR, label: 'Ensino Superior' },
];

export const ETAPAS_QUE_EXIGEM_CURSO = [ETAPA_ENSINO.SECUNDARIO, ETAPA_ENSINO.SUPERIOR];

export const etapaExigeCurso = (etapa) => ETAPAS_QUE_EXIGEM_CURSO.includes(etapa);
