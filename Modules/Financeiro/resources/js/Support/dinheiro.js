/**
 * Valores monetários chegam do backend em cêntimos (inteiro). Estes helpers só apresentam
 * ou preenchem inputs: a conversão autoritativa é Dinheiro::deKwanzas no backend.
 */
export function formatKz(centimos) {
    const valor = Math.abs(Number(centimos ?? 0));
    const inteira = Math.trunc(valor / 100);
    const fraccao = String(valor % 100).padStart(2, '0');
    const milhares = String(inteira).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${milhares},${fraccao} Kz`;
}

export function centimosParaKz(centimos) {
    const valor = Math.abs(Number(centimos ?? 0));

    return `${Math.trunc(valor / 100)}.${String(valor % 100).padStart(2, '0')}`;
}
