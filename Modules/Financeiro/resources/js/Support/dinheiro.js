/**
 * Valores monetários chegam do backend em unidades menores da moeda (inteiro). `moeda` é o objeto
 * que o backend envia: { codigo, nome, simbolo, decimais }. Estes helpers só apresentam ou
 * preenchem inputs: a conversão autoritativa é Dinheiro::deDecimal no backend.
 * Os cálculos usam BigInt, para totais acima de Number.MAX_SAFE_INTEGER não perderem precisão.
 */
export function formatarDinheiro(unidadesMenores, moeda) {
    const fator = 10n ** BigInt(moeda.decimais);
    let valor = BigInt(unidadesMenores ?? 0);
    if (valor < 0n) valor = -valor;
    const milhares = (valor / fator).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    if (moeda.decimais === 0) {
        return `${milhares} ${moeda.simbolo}`;
    }

    const fraccao = (valor % fator).toString().padStart(moeda.decimais, '0');

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

/**
 * Texto do input → unidades menores (BigInt), com as regras de Dinheiro::deDecimal (até 12 dígitos
 * inteiros, "." ou "," e até N casas, N = casas da moeda). null se inválido. Só para pré-visualizar.
 */
export function decimalParaUnidadesMenores(texto, moeda) {
    const padrao = moeda.decimais === 0
        ? /^(\d{1,12})$/
        : new RegExp(`^(\\d{1,12})(?:[.,](\\d{1,${moeda.decimais}}))?$`);
    const partes = String(texto ?? '').trim().match(padrao);

    if (!partes) return null;

    const fraccao = partes[2] ? BigInt(partes[2].padEnd(moeda.decimais, '0')) : 0n;

    return BigInt(partes[1]) * 10n ** BigInt(moeda.decimais) + fraccao;
}
