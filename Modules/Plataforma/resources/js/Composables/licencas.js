// Apresentação das licenças e dos planos no painel. Só texto e classes: a autoridade (estado, validade derivada,
// acções permitidas) é sempre do backend.

export const rotuloLimite = (valor) => (valor === null || valor === undefined ? 'Sem limite' : Number(valor).toLocaleString('pt-PT'));

export const rotuloModulo = (alias) => String(alias).charAt(0).toUpperCase() + String(alias).slice(1);

export const rotuloModulos = (modulos) => (modulos?.length ? modulos.map(rotuloModulo).join(', ') : '—');

// Dias inteiros até à data (negativo = já passou), para destacar o que está prestes a expirar.
export function diasAte(valor) {
    if (!valor) return null;

    return Math.ceil((new Date(valor).getTime() - Date.now()) / 86400000);
}

export const rotuloDeEvento = (tipo) => ({
    emitida: 'Emitida',
    renovada: 'Renovada',
    estendida: 'Tolerância estendida',
    suspensa: 'Suspensa',
    reactivada: 'Reactivada',
    cancelada: 'Cancelada',
}[tipo] ?? tipo);

// Mensagens de erro que não pertencem a nenhum campo do formulário/modal (`camposProprios`): transição recusada,
// emissor indisponível, módulo retirado da configuração, escola inexistente... Mostram-se TODAS, para nunca se perderem.
export const errosForaDosCampos = (erros, camposProprios = []) => Object.entries(erros ?? {})
    .filter(([campo]) => !camposProprios.includes(campo))
    .map(([, mensagem]) => mensagem);
