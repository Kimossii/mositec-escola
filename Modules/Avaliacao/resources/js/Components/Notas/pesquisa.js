// Pesquisa de alunos no ecrã: sem maiúsculas nem acentos, pelo nome ou pelo número de matrícula.
const limpar = (texto) => String(texto ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();

/** Devolve os alunos que correspondem ao termo, cada um com a sua posição original (a numeração da linha não muda ao filtrar). */
export function filtrarAlunos(alunos, termo) {
    const t = limpar(termo);

    return alunos
        .map((a, n) => ({ a, n }))
        .filter(({ a }) => t === '' || limpar(a.nome).includes(t) || limpar(a.numero_matricula).includes(t));
}
