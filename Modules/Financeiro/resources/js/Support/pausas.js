/**
 * Espelho de MesesDePausa::tocados (PHP): meses tocados por uma pausa, do mês da data de início ao mês
 * da data de fim, cada um marcado como coberto por inteiro (`completo`) ou só parcialmente.
 */
export function mesesTocados(dataInicio, dataFim) {
    if (!dataInicio || !dataFim) return [];

    const resultado = [];
    const [anoInicio, mesInicio] = String(dataInicio).slice(0, 7).split('-').map(Number);
    const fim = String(dataFim).slice(0, 10);

    let ano = anoInicio;
    let mes = mesInicio;

    while (true) {
        const primeiro = `${ano}-${String(mes).padStart(2, '0')}-01`;
        const ultimoDia = new Date(ano, mes, 0).getDate();
        const ultimo = `${ano}-${String(mes).padStart(2, '0')}-${String(ultimoDia).padStart(2, '0')}`;

        resultado.push({ ano, mes, completo: dataInicio <= primeiro && dataFim >= ultimo });

        if (ultimo >= fim) break;

        mes += 1;
        if (mes > 12) {
            mes = 1;
            ano += 1;
        }
    }

    return resultado;
}

export const MESES = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
];

export function rotuloMes(mes) {
    return MESES[mes - 1] ?? '';
}
