/**
 * Espelho de CalendarioDePlano::duracoes (PHP): duração, em meses, de cada período de cobrança.
 * O último período pode ser mais curto. Não depende do ano lectivo. Intervalo inválido → [].
 */
export function duracoesDosPeriodos(mesInicio, mesFim, intervalo) {
    const n = Number(intervalo);
    if (!Number.isInteger(n) || n < 1 || n > 12) return [];

    const total = ((Number(mesFim) - Number(mesInicio) + 12) % 12) + 1;
    const duracoes = Array(Math.floor(total / n)).fill(n);
    if (total % n !== 0) duracoes.push(total % n);

    return duracoes;
}

/** "4 períodos (3+3+3+1 meses)" / "1 período (12 meses)". */
export function resumoPeriodos(duracoes) {
    if (!duracoes?.length) return '';

    const quantidade = duracoes.length === 1 ? '1 período' : `${duracoes.length} períodos`;
    const meses = duracoes.length === 1 && duracoes[0] === 1 ? 'mês' : 'meses';

    return `${quantidade} (${duracoes.join('+')} ${meses})`;
}
