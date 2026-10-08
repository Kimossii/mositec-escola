<?php

namespace Modules\Core\Contracts;

use Modules\Core\DTO\SituacaoAcademicaDoAluno;

/**
 * Curso e turma actuais de um aluno (identificado pelo número de matrícula), só para o ecrã de cadastro
 * de utilizadores confirmar de que aluno se trata. Implementado pelo módulo Matricula (que conhece o
 * Aluno, a Turma e o Curso): o Usuario só conhece este contrato e nunca importa Aluno nem Matricula.
 * Respeita o tenant corrente; sem dados devolve campos nulos.
 */
interface ProcuraSituacaoAcademicaDoAluno
{
    public function procurar(string $numeroMatricula): SituacaoAcademicaDoAluno;
}
