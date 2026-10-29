export const formatarTamanho = (bytes) => {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${Math.max(1, Math.round(bytes / 1024))} KB`;

    return `${(bytes / 1048576).toFixed(1)} MB`;
};

export const formatarData = (iso) => (iso ? new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' }) : '—');
