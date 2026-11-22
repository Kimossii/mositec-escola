<?php

namespace Modules\Core\Contracts;

/**
 * Âmbito de um professor sobre os alunos, para o módulo Usuario decidir quem vê os documentos de quem. Implementado pelo
 * Docente (que conhece atribuições e matrículas e depende do Usuario): o inverso seria uma dependência circular.
 * Respeita o tenant corrente.
 */
interface VerificaProfessorLeccionaAluno
{
    /**
     * A pessoa `$pessoaDoProfessorId` (dados_pessoa) tem hoje uma atribuição docente em vigor numa turma onde a pessoa
     * `$pessoaDoAlunoId` (dados_pessoa) tem matrícula activa.
     */
    public function lecciona(int $pessoaDoProfessorId, int $pessoaDoAlunoId): bool;
}
