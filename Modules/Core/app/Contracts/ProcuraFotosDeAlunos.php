<?php

namespace Modules\Core\Contracts;

/**
 * URLs das fotos dos alunos, para o módulo Usuario as mostrar na lista de contas sem importar o
 * módulo Aluno (mesma razão de ProcuraAlunoParaConta). Uma só consulta para várias matrículas, para
 * a lista não fazer uma pesquisa por linha. Respeita o tenant corrente; alunos sem foto ficam de fora.
 */
interface ProcuraFotosDeAlunos
{
    /**
     * @param  array<int, string>  $numerosMatricula
     * @return array<string, string> número de matrícula => URL da foto
     */
    public function urlsPorMatricula(array $numerosMatricula): array;
}
