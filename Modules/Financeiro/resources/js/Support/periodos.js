/**
 * Ajudantes de calendário de cobrança (espelho de CalendarioDePlano, em JS). Os planos valem de uma data a
 * outra; cada mês tocado conta como mês inteiro. As competências (ano, mês) derivam-se das datas.
 */
const MESES_CURTOS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

/** @returns {Array<{ano: number, mes: number}>} meses cobertos, do mês de dataInicio ao mês de dataFim, inclusive. */
export function mesesDeDatas(dataInicio, dataFim) {
    if (!dataInicio || !dataFim) return [];

    const [anoInicio, mesInicio] = String(dataInicio).slice(0, 7).split('-').map(Number);
    const fim = String(dataFim).slice(0, 10);

    const resultado = [];
    let ano = anoInicio;
    let mes = mesInicio;

    while (true) {
        resultado.push({ ano, mes });

        const ultimoDia = new Date(ano, mes, 0).getDate();
        const ultimo = `${ano}-${String(mes).padStart(2, '0')}-${String(ultimoDia).padStart(2, '0')}`;

        if (ultimo >= fim) break;

        mes += 1;
        if (mes > 12) {
            mes = 1;
            ano += 1;
        }
    }

    return resultado;
}

/** "Set/2026". */
export function rotuloCompetencia(ano, mes) {
    return `${MESES_CURTOS[mes - 1]}/${ano}`;
}

/** "2026-09-10" → "09/2026" (mês e ano de uma data civil). */
export function mesAno(ymd) {
    if (!ymd) return '—';

    const [ano, mes] = String(ymd).slice(0, 10).split('-');

    return `${mes}/${ano}`;
}

/** "2026-09-10" → "10/09/2026". Sem `Date`: uma data civil não tem fuso e não pode recuar um dia. */
export function dataCurta(ymd) {
    if (!ymd) return '—';

    const [ano, mes, dia] = String(ymd).slice(0, 10).split('-');

    return `${dia}/${mes}/${ano}`;
}
