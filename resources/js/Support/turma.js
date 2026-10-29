/**
 * Rótulo único da turma (espelha Turma::rotulo() no backend).
 * Curto: «Turma 10.ª A». Completo: «Turma 10.ª A · Manhã · 2026/2027».
 * Aceita turno/ano_lectivo (ou anoLectivo) como objeto com `nome` ou como texto; se faltarem, omite essa parte.
 */
export function rotuloTurma(turma, { completo = false } = {}) {
    if (!turma) return '';

    const nome = String(turma.nome ?? turma.codigo ?? '').trim();
    const partes = [/^turma\b/i.test(nome) ? nome : `Turma ${nome}`.trim()];

    if (completo) {
        for (const valor of [turma.turno, turma.ano_lectivo ?? turma.anoLectivo]) {
            const parte = String((typeof valor === 'object' ? valor?.nome : valor) ?? '').trim();
            if (parte) partes.push(parte);
        }
    }

    return partes.join(' · ');
}
