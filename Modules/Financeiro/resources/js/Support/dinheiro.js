/**
 * Valores monetários chegam do backend em unidades menores da moeda (inteiro). `moeda` é o objeto
 * que o backend envia: { codigo, nome, simbolo, decimais }. Estes helpers só apresentam ou
 * preenchem inputs: a conversão autoritativa é Dinheiro::deDecimal no backend.
 */
export function formatarDinheiro(unidadesMenores, moeda) {
    const fator = 10 ** moeda.decimais;
    const valor = Math.abs(Number(unidadesMenores ?? 0));
    const inteira = Math.trunc(valor / fator);
    const milhares = String(inteira).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    if (moeda.decimais === 0) {
        return `${milhares} ${moeda.simbolo}`;
    }

    const fraccao = String(valor % fator).padStart(moeda.decimais, '0');

    return `${milhares},${fraccao} ${moeda.simbolo}`;
}

export function unidadesMenoresParaDecimal(unidadesMenores, moeda) {
    const fator = 10 ** moeda.decimais;
    const valor = Math.abs(Number(unidadesMenores ?? 0));
    const inteira = Math.trunc(valor / fator);

    if (moeda.decimais === 0) {
        return String(inteira);
    }

    return `${inteira}.${String(valor % fator).padStart(moeda.decimais, '0')}`;
}
