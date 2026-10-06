// Data e hora legíveis (pt-PT) a partir do texto ISO que o backend envia; vazio devolve o travessão.
export function formatarDataHora(valor) {
    if (!valor) return '—';

    return new Date(valor).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
}

export function formatarData(valor) {
    if (!valor) return '—';

    return new Date(valor).toLocaleDateString('pt-PT');
}
