/** Primeira letra do primeiro e do último nome, para o avatar sem foto. */
export function iniciais(nome) {
    const partes = String(nome ?? '').trim().split(/\s+/).filter(Boolean);
    if (!partes.length) return '';
    return (partes[0][0] + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase();
}
